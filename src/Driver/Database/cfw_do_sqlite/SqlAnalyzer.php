<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

/**
 * Classifies a statement and names the tables it touches.
 *
 * The driver has to answer two questions about every statement before running
 * it: is this a write, so it must be buffered while a Drupal transaction is
 * open, and which tables does it touch, so that a read can be *proven*
 * unaffected by the buffered writes and sent straight to the host.
 *
 * Both answers over-approximate. An unrecognised statement, or a
 * write whose target cannot be pinned down, reports as touching everything.
 * That costs a false positive - a read refused or resolved the expensive way -
 * rather than data that is quietly wrong.
 *
 * This is not a SQL parser and must not become one. It looks at the leading
 * keyword and at the identifiers following FROM / JOIN / INTO / UPDATE /
 * TABLE / INDEX, on SQL that Drupal's own query builders emitted.
 *
 * @see Connection::runStatement()
 */
final class SqlAnalyzer
{
	/**
	 * The statement only reads.
	 */
	public const READ = 'read';

	/**
	 * The statement changes data or schema.
	 */
	public const WRITE = 'write';

	/**
	 * The statement is transaction control, which this driver never emits.
	 */
	public const TRANSACTION_CONTROL = 'transaction_control';

	/**
	 * The statement could not be classified.
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Stand-in table name meaning "assume every table is affected".
	 */
	public const ALL_TABLES = '*';

	/**
	 * Pseudo-table used to make schema reads collide with buffered DDL.
	 */
	public const SCHEMA_TABLE = 'sqlite_master';

	/**
	 * A bare, double-quoted or schema-qualified identifier.
	 */
	private const IDENTIFIER = '(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_$]*)';

	/**
	 * Token kind: SQL outside any literal, comment or quoted identifier.
	 */
	private const TOKEN_PLAIN = 'plain';

	/**
	 * Token kind: a single-quoted string literal.
	 */
	private const TOKEN_LITERAL = 'literal';

	/**
	 * Token kind: a double-quoted identifier.
	 */
	private const TOKEN_IDENTIFIER = 'identifier';

	/**
	 * Token kind: a line or block comment.
	 */
	private const TOKEN_COMMENT = 'comment';

	/**
	 * Keywords that begin a statement changing data or schema.
	 */
	private const WRITE_KEYWORDS = [
		'INSERT',
		'REPLACE',
		'UPDATE',
		'DELETE',
		'CREATE',
		'DROP',
		'ALTER',
		'REINDEX',
		'ANALYZE',
		'VACUUM',
	];

	/**
	 * Keywords that begin a read-only statement.
	 */
	private const READ_KEYWORDS = ['SELECT', 'VALUES', 'EXPLAIN'];

	/**
	 * Keywords that begin transaction control.
	 */
	private const TRANSACTION_KEYWORDS = [
		'BEGIN',
		'COMMIT',
		'END',
		'ROLLBACK',
		'SAVEPOINT',
		'RELEASE',
	];

	/**
	 * Functions Drupal emits, mapped to the builtin that means the same thing.
	 *
	 * The core sqlite driver supplies these through
	 * PDO::sqliteCreateFunction() and ctx.storage.sql has no user-defined
	 * functions, so the only ones that can be supported are the ones a builtin
	 * already covers exactly. Each of these four was measured absent from SQLite
	 * and its replacement measured present:
	 * - IF() is not a documented SQLite function; iif() is, since 3.32
	 * - GREATEST()/LEAST() do not exist; max()/min() with two or more arguments
	 *   are variadic builtins with the same NULL propagation MySQL has
	 * - RAND() does not exist; random() does, and Drupal only ever uses it in an
	 *   ORDER BY, where the range of the value does not matter.
	 *
	 * Functions with no builtin equivalent - MD5(), REGEXP, SUBSTRING_INDEX() -
	 * are absent from this map: they must fail loudly rather than be mapped onto
	 * something that behaves differently.
	 */
	private const FUNCTION_MAP = [
		'if' => 'iif',
		'greatest' => 'max',
		'least' => 'min',
		'rand' => 'random',
	];

	/**
	 * Classifies a statement.
	 *
	 * @param string $sql
	 *   The statement, after Drupal has resolved prefixes and identifier quotes.
	 *
	 * @return string
	 *   One of the READ, WRITE, TRANSACTION_CONTROL or UNKNOWN constants.
	 */
	public static function classify(string $sql): string
	{
		$sql = self::strip($sql);

		if (preg_match('/^\s*([A-Za-z]+)/', $sql, $matches) !== 1) {
			return self::UNKNOWN;
		}
		$keyword = strtoupper($matches[1]);

		if (in_array($keyword, self::TRANSACTION_KEYWORDS, true)) {
			return self::TRANSACTION_CONTROL;
		}
		if ($keyword === 'WITH') {
			// a common table expression can front either a select or a data change
			return preg_match('/\b(?:INSERT|REPLACE|UPDATE|DELETE)\b/i', $sql) === 1
				? self::WRITE
				: self::READ;
		}
		if (in_array($keyword, self::WRITE_KEYWORDS, true)) {
			return self::WRITE;
		}
		if (in_array($keyword, self::READ_KEYWORDS, true)) {
			return self::READ;
		}
		if ($keyword === 'PRAGMA') {
			// an assigning pragma is a setting rather than a read, and replaying one
			// out of order is not something the driver may decide is safe
			return str_contains($sql, '=') ? self::UNKNOWN : self::READ;
		}

		return self::UNKNOWN;
	}

	/**
	 * Names the tables a write statement modifies.
	 *
	 * @param string $sql
	 *   A statement that classify() reported as WRITE.
	 *
	 * @return string[]
	 *   Lower-cased table names, or [ALL_TABLES] when the target cannot be
	 *   determined. DDL also reports SCHEMA_TABLE so that later introspection
	 *   reads collide with it.
	 */
	public static function writtenTables(string $sql): array
	{
		$sql = self::strip($sql);
		$identifier = self::IDENTIFIER;
		$qualified = '(' . $identifier . '(?:\s*\.\s*' . $identifier . ')?)';

		// a rename creates a second name for the same data, so nothing downstream
		// can be assumed clean
		if (preg_match('/\bALTER\s+TABLE\b[\s\S]*\bRENAME\b/i', $sql) === 1) {
			return [self::ALL_TABLES];
		}

		$dataPatterns = [
			'/\b(?:INSERT|REPLACE)\s+(?:OR\s+[A-Za-z]+\s+)?INTO\s+' . $qualified . '/i',
			'/\bUPDATE\s+(?:OR\s+[A-Za-z]+\s+)?' . $qualified . '/i',
			'/\bDELETE\s+FROM\s+' . $qualified . '/i',
		];
		foreach ($dataPatterns as $pattern) {
			if (preg_match($pattern, $sql, $matches) === 1) {
				return [self::normalizeIdentifier($matches[1])];
			}
		}

		$ifNotExists = '(?:IF\s+NOT\s+EXISTS\s+)?';
		$createTable = '/\bCREATE\s+(?:TEMP(?:ORARY)?\s+)?(?:TABLE|VIEW)\s+' . $ifNotExists;
		$createIndex = '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+' . $ifNotExists;
		// an index name may be schema-qualified, hence the optional second identifier
		$indexTarget = '(?:\s*\.\s*' . $identifier . ')?\s+ON\s+';
		$ddlPatterns = [
			$createTable . $qualified . '/i',
			$createIndex . $identifier . $indexTarget . $qualified . '/i',
			'/\bDROP\s+(?:TABLE|VIEW)\s+(?:IF\s+EXISTS\s+)?' . $qualified . '/i',
			'/\bALTER\s+TABLE\s+' . $qualified . '/i',
		];
		foreach ($ddlPatterns as $pattern) {
			if (preg_match($pattern, $sql, $matches) === 1) {
				return [self::normalizeIdentifier($matches[1]), self::SCHEMA_TABLE];
			}
		}

		// DROP INDEX, REINDEX, VACUUM, or anything unforeseen
		return [self::ALL_TABLES];
	}

	/**
	 * Names the tables a read statement references.
	 *
	 * @param string $sql
	 *   A statement that classify() reported as READ.
	 *
	 * @return string[]
	 *   Lower-cased table names. An empty array means the read touches no table
	 *   at all, for example "SELECT 1".
	 */
	public static function readTables(string $sql): array
	{
		$sql = self::strip($sql);
		$identifier = self::IDENTIFIER;
		$qualified = '(' . $identifier . '(?:\s*\.\s*' . $identifier . ')?)';

		$tables = [];
		if (preg_match_all('/\b(?:FROM|JOIN)\s+' . $qualified . '/i', $sql, $matches) > 0) {
			foreach ($matches[1] as $raw) {
				$tables[] = self::normalizeIdentifier($raw);
			}
		}

		// PRAGMA table_info("node") reads the schema of one named table
		if (
			preg_match(
				'/\bPRAGMA\s+(?:' .
					$identifier .
					'\s*\.\s*)?[A-Za-z_]+\s*\(\s*(' .
					$identifier .
					')\s*\)/i',
				$sql,
				$matches,
			) === 1
		) {
			$tables[] = self::normalizeIdentifier($matches[1]);
			$tables[] = self::SCHEMA_TABLE;
		}

		return array_values(array_unique($tables));
	}

	/**
	 * Renames the functions Drupal emits to their SQLite builtins.
	 *
	 * Only the four names in FUNCTION_MAP are touched, and only outside string
	 * literals, comments and quoted identifiers, so a title containing the word
	 * "if(" is left alone.
	 *
	 * @param string $sql
	 *   The statement.
	 *
	 * @return string
	 *   The statement with those function names replaced.
	 */
	public static function rewriteFunctions(string $sql): string
	{
		$names = implode('|', array_keys(self::FUNCTION_MAP));

		// the overwhelming majority of statements contain none of them
		if (preg_match('/\b(?:' . $names . ')\s*\(/i', $sql) !== 1) {
			return $sql;
		}

		$out = '';
		foreach (self::tokenize($sql) as [$kind, $text]) {
			$out .=
				$kind === self::TOKEN_PLAIN
					? (string) preg_replace_callback(
						'/\b(' . $names . ')(\s*\()/i',
						static fn(array $m): string => self::FUNCTION_MAP[strtolower($m[1])] .
							$m[2],
						$text,
					)
					: $text;
		}

		return $out;
	}

	/**
	 * Reduces an identifier to a comparable table name.
	 *
	 * @param string $raw
	 *   A possibly quoted, possibly schema-qualified identifier.
	 *
	 * @return string
	 *   The lower-cased, unquoted, unqualified name.
	 */
	private static function normalizeIdentifier(string $raw): string
	{
		$parts = preg_split('/\s*\.\s*/', trim($raw));
		$name = is_array($parts) ? (string) end($parts) : $raw;
		return strtolower(trim($name, '"'));
	}

	/**
	 * Blanks string literals and removes comments, leaving the rest intact.
	 *
	 * @param string $sql
	 *   The statement.
	 *
	 * @return string
	 *   The statement with literals blanked and comments replaced by a space, so
	 *   that neither can be mistaken for a table reference.
	 */
	private static function strip(string $sql): string
	{
		$out = '';
		foreach (self::tokenize($sql) as [$kind, $text]) {
			$out .= match ($kind) {
				self::TOKEN_LITERAL => "''",
				self::TOKEN_COMMENT => ' ',
				default => $text,
			};
		}
		return $out;
	}

	/**
	 * Splits a statement into literals, comments, quoted identifiers and the rest.
	 *
	 * One left-to-right pass is required rather than a set of regexes, because a
	 * literal can contain "--" and a comment can contain an apostrophe: strip
	 * either kind first and the other is corrupted. Getting that wrong makes a
	 * table reference disappear, which would let a dirty read look clean - the one
	 * failure mode this class exists to prevent. Drupal's own query comments carry
	 * arbitrary text, so this is not a hypothetical input.
	 *
	 * @param string $sql
	 *   The statement.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 *   A list of [kind, text] pairs which concatenate back to $sql exactly,
	 *   except for an unterminated final token.
	 */
	private static function tokenize(string $sql): array
	{
		$tokens = [];
		$plain = '';
		$i = 0;
		$length = strlen($sql);

		while ($i < $length) {
			$character = $sql[$i];
			$start = $i;
			$kind = null;

			if ($character === "'") {
				$i++;
				while ($i < $length) {
					if ($sql[$i] === "'" && ($sql[$i + 1] ?? '') === "'") {
						$i += 2;
						continue;
					}
					if ($sql[$i] === "'") {
						$i++;
						break;
					}
					$i++;
				}
				$kind = self::TOKEN_LITERAL;
			} elseif ($character === '"') {
				$i++;
				while ($i < $length && $sql[$i] !== '"') {
					$i++;
				}
				$i++;
				$kind = self::TOKEN_IDENTIFIER;
			} elseif ($character === '-' && ($sql[$i + 1] ?? '') === '-') {
				while ($i < $length && $sql[$i] !== "\n") {
					$i++;
				}
				$kind = self::TOKEN_COMMENT;
			} elseif ($character === '/' && ($sql[$i + 1] ?? '') === '*') {
				$i += 2;
				while ($i < $length && !($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
					$i++;
				}
				$i += 2;
				$kind = self::TOKEN_COMMENT;
			}

			if ($kind === null) {
				$plain .= $character;
				$i++;
				continue;
			}

			if ($plain !== '') {
				$tokens[] = [self::TOKEN_PLAIN, $plain];
				$plain = '';
			}
			$tokens[] = [$kind, substr($sql, $start, min($i, $length) - $start)];
		}

		if ($plain !== '') {
			$tokens[] = [self::TOKEN_PLAIN, $plain];
		}

		return $tokens;
	}

	/**
	 * Translates a SQL LIKE pattern into an equivalent builtin GLOB pattern.
	 *
	 * The two wildcard languages overlap in a way that fails silently rather than
	 * loudly, which is why this exists at all:
	 *
	 *   %  _        wildcards under LIKE, literal characters under GLOB
	 *   *  ?  [     wildcards under GLOB, literal characters under LIKE
	 *
	 * So an untranslated pattern makes a search for a literal '*' match everything
	 * and a '%' wildcard match nothing, with no error. SQLite has no GLOB ESCAPE,
	 * so a single-character class is the only way to quote a GLOB metacharacter --
	 * verified against the engine: 'a*c' GLOB 'a[*]c', 'a?c' GLOB 'a[?]c' and
	 * 'a[c' GLOB 'a[[]c' all match.
	 *
	 * Escape sequences are NOT unwound. Core's own implementation,
	 * sqlite\Connection::sqlFunctionLikeBinary(), runs preg_quote() over the
	 * pattern and then replaces % and _ unconditionally, so it treats the
	 * backslashes escapeLike() inserts as literal backslashes to be matched. This
	 * reproduces that behaviour, quirk included, because parity with
	 * Drupal-on-SQLite is the target rather than parity with MySQL.
	 *
	 * @param string $pattern
	 *   A LIKE pattern.
	 *
	 * @return string
	 *   The equivalent GLOB pattern.
	 *
	 * @see Connection::sqlFunctionLikeBinary()
	 */
	public static function likeToGlob(string $pattern): string
	{
		$out = '';
		$length = strlen($pattern);
		for ($i = 0; $i < $length; $i++) {
			$ch = $pattern[$i];
			if ($ch === '%') {
				$out .= '*';
			} elseif ($ch === '_') {
				$out .= '?';
			} elseif ($ch === '*' || $ch === '?' || $ch === '[') {
				$out .= '[' . $ch . ']';
			} else {
				$out .= $ch;
			}
		}

		return $out;
	}

	/**
	 * Widens a LIKE pattern until its GLOB translation fits the host's ceiling.
	 *
	 * THE RESULT IS A SUPERSET, NEVER AN EQUIVALENT, and every caller has to treat it that way.
	 * `%needle%` truncated to `%need%` matches everything the original matched and more, so a
	 * caller must re-apply the original pattern to the rows that come back. `Connection` does
	 * exactly that, and refuses to widen at all where it cannot.
	 *
	 * TRUNCATION HAPPENS IN LIKE SPACE, NOT GLOB SPACE, and that is a correctness point rather
	 * than a convenience: bracket-quoting expands one metacharacter into three bytes (`*` becomes
	 * `[*]`), so cutting a GLOB string can land inside a bracket group and produce a pattern that
	 * is invalid or, worse, means something else. Cutting the LIKE first and re-translating cannot.
	 *
	 * A trailing `\` is never left behind: it would escape whatever the appended `%` is, turning a
	 * wildcard into a literal and making the result NOT a superset.
	 *
	 * **THERE IS A FLOOR, AND IT IS NOT A STYLE CHOICE.** Widening trades selectivity for a pattern
	 * the engine will accept, and a one-character prefix is not a trade -- `%a%` scans the table and
	 * ships nearly every row across the bridge for PHP to discard. That turns a clean, named refusal
	 * into an unbounded read, which is worse than the limit. Below `$minRetainedBytes` of the
	 * original the answer is NULL and the caller refuses.
	 *
	 * @param string $pattern
	 *   The LIKE pattern, before translation.
	 * @param int $maxGlobBytes
	 *   The ceiling the translated pattern must fit.
	 * @param int $minRetainedBytes
	 *   How much of the original must survive for the widened form to still be selective.
	 *
	 * @return string|null
	 *   A wider LIKE pattern whose GLOB form fits, or NULL when no prefix does so while retaining
	 *   enough of the original to be worth running.
	 */
	public static function widenLikePattern(
		string $pattern,
		int $maxGlobBytes,
		int $minRetainedBytes = 8,
	): ?string {
		if (strlen(self::likeToGlob($pattern)) <= $maxGlobBytes) {
			return $pattern;
		}
		// one byte is reserved for the '%' this appends
		for ($take = strlen($pattern) - 1; $take >= max(1, $minRetainedBytes); $take--) {
			$head = substr($pattern, 0, $take);
			// never cut immediately after a backslash: the escape would consume the appended '%'
			if (substr_count($head, '\\') > 0 && str_ends_with($head, '\\')) {
				continue;
			}
			// nor mid-codepoint, or the widened pattern carries a broken UTF-8 sequence that
			// matches nothing rather than more
			if (!self::endsOnUtf8Boundary($head)) {
				continue;
			}
			$candidate = $head . '%';
			if (strlen(self::likeToGlob($candidate)) <= $maxGlobBytes) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Whether a byte string ends on a complete UTF-8 character.
	 *
	 * Written out rather than using `mb_check_encoding`, because mbstring is absent on the wasm
	 * build and the polyfill this runtime supplies has measured divergences. The question here is
	 * narrow enough not to need either: a continuation byte is `10xxxxxx`, and a lead byte
	 * announces its own length.
	 */
	public static function endsOnUtf8Boundary(string $value): bool
	{
		$length = strlen($value);
		if ($length === 0) {
			return true;
		}
		// walk back over continuation bytes to the lead byte of the last character
		$i = $length - 1;
		while ($i >= 0 && (ord($value[$i]) & 0xc0) === 0x80) {
			$i--;
		}
		if ($i < 0) {
			// nothing but continuation bytes: not valid UTF-8 at all
			return false;
		}
		$lead = ord($value[$i]);
		$expected = match (true) {
			$lead < 0x80 => 1,
			($lead & 0xe0) === 0xc0 => 2,
			($lead & 0xf0) === 0xe0 => 3,
			($lead & 0xf8) === 0xf0 => 4,
			default => 0,
		};

		return $expected > 0 && $length - $i === $expected;
	}
}

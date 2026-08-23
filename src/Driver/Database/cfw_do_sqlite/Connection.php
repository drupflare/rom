<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\InvalidQueryException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction\TransactionManagerBase;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\sqlite\Driver\Database\sqlite\Connection as SqliteDriverConnection;
use Exception;
use ReflectionProperty;
use Throwable;

/**
 * Drupal connection to ctx.storage.sql inside a Durable Object.
 *
 * It extends the core sqlite driver rather than the base Connection because the
 * engine underneath genuinely is SQLite: the SQL generation, the query builders,
 * the schema handling and the LIKE/GLOB operator map are all correct as they
 * stand and are not forked here. What changes is everything that assumed PDO and
 * a file on disk.
 *
 * Four differences are worth reading before using this class.
 *
 * **No PDO.** The client connection is a CfwSqlClient, which calls a function the
 * host installed on the PHP Module. PHP runs inside the Durable Object, so a
 * query is a synchronous call across the wasm boundary, not a round trip.
 *
 * **No user functions or collations.** ctx.storage.sql has neither, measured, so the
 * dozen SQL functions the core driver registers through
 * PDO::sqliteCreateFunction() do not exist and NOCASE_UTF8 does not exist.
 * Schema substitutes the builtin NOCASE, which folds ASCII only; see Schema.
 *
 * **No transaction control.** BEGIN is refused by the runtime. Writes issued while a
 * Drupal transaction is open are buffered here and replayed inside one host-side
 * transactionSync() at commit. That makes reads the interesting case, and
 * runStatement() is where it is handled. What a replay learns is kept on the
 * buffer, so a second question about the same buffered statement costs nothing.
 *
 * **Table prefixes are name-mangled, not attached.** The core sqlite driver
 * implements a prefix by attaching one database file per prefix and emitting
 * "prefix"."table"; there are no files and no ATTACH here, so that mechanism has
 * no analogue. The base Connection's own mechanism does, and it is the one used:
 * setPrefix() folds the prefix into the identifier, so {node} resolves to
 * "myprefix_node" in the single database this Durable Object owns. A period is
 * the one prefix character that still cannot work, because it names a schema; it
 * is refused at construction. See Schema::findTables(), which the core sqlite
 * driver implements against the attached-database assumption and which therefore
 * had to be replaced rather than inherited.
 *
 * @see CfwSqlClient
 * @see TransactionManager
 * @see Schema
 */
class Connection extends SqliteDriverConnection
{
	/**
	 * The writes withheld while a Drupal transaction is open.
	 */
	private ?TransactionBuffer $buffer = null;

	/**
	 * Whether a plain insert into each table is a simple append, and which column is its rowid.
	 *
	 * Kept for the life of the CONNECTION rather than of one buffer, and dropped when any DDL
	 * runs. A per-buffer cache costs a `sqlite_master` read every time a transaction touches a
	 * table it cannot predict -- which is every entity save, since those are the AUTOINCREMENT
	 * tables -- so the refusal would have carried a permanent toll.
	 *
	 * @var array<string, array{appendable: bool, integerPrimaryKey: string|null}>
	 */
	private array $rowidSchema = [];

	/**
	 * Committed maximum rowid per table, for the life of one buffer.
	 *
	 * Frozen for exactly that long: nothing writes the committed database while a buffer is open,
	 * since a speculative replay is rolled back and a commit closes the buffer first.
	 *
	 * @var array<string, int|null>
	 */
	private array $rowidMax = [];

	/**
	 * The engine version, cached after the first query that needs it.
	 */
	private ?string $engineVersion = null;

	/**
	 * Whether $engineVersion is a probed floor rather than a reported version.
	 */
	private bool $engineVersionIsFloor = false;

	/**
	 * Marks a placeholder whose value is a LIKE pattern needing GLOB translation.
	 *
	 * Emitted by mapConditionOperator() as the operator's SQL prefix, which
	 * Condition::compile() places immediately before the placeholder. Comment
	 * syntax so it is inert if it ever reaches the engine, and so the
	 * literal-aware scanner in SqlAnalyzer steps over it.
	 */
	private const LIKE_BINARY_MARKER = '/*cfw:l2g*/';

	/**
	 * Longest LIKE or GLOB pattern ctx.storage.sql will accept, in bytes.
	 *
	 * Measured, not from documentation: 50 bytes succeeds and 51 fails with
	 * "LIKE or GLOB pattern too complex: SQLITE_ERROR". SQLite's own default
	 * SQLITE_MAX_LIKE_PATTERN_LENGTH is 50,000, so this is the runtime lowering it
	 * by three orders of magnitude. It applies to plain LIKE as well, which this
	 * driver cannot intercept -- a Views "contains" filter on a long search string
	 * will fail in the engine.
	 */
	public const MAX_LIKE_PATTERN_BYTES = 50;

	/**
	 * Bound parameters the host allows in one statement.
	 *
	 * Measured, not documented by the platform: 100 succeeds, 101 fails with "too many SQL
	 * variables". `Upsert` already chunks writes against this; `Statement::execute()` splits an
	 * oversized read IN() list against it, which is a separate path that only surfaced when a
	 * module install made Drupal load 169 config names at once.
	 */
	public const MAX_BOUND_PARAMETERS = 100;

	/**
	 * Bytes ctx.storage.sql will accept in one record.
	 *
	 * Measured on the platform, not documented by it. A field larger than this fails the insert
	 * outright rather than truncating, which is the good failure mode -- but the engine's message
	 * names neither the column nor the cap, so a module author sees a generic write error from a
	 * statement that is correct SQL everywhere else.
	 *
	 * **DECLARED RATHER THAN ACCOMMODATED, and the arithmetic is why.** Splitting an oversized
	 * value across a side table the way `file-store.ts` chunks uploads is possible, and it was
	 * priced before being rejected: at the same 200,000-byte chunk size a 2 MB value becomes **11
	 * rows instead of 1** on the meter that binds regeneration, every read of that row becomes 11
	 * reads, and the driver would have to intercept `SELECT *`, aliases, JOINs and aggregates to
	 * reassemble it -- the same shape problem that keeps wide-integer mode narrow. Nothing in
	 * Drupal core writes a field this large. So the driver says exactly what happened and where,
	 * and a module that needs bigger values chunks them itself, where it knows what the value is.
	 */
	public const MAX_RECORD_BYTES = 2199995;

	/**
	 * Characters a table prefix may contain on this driver.
	 *
	 * Core validates a prefix against [A-Za-z0-9_.], and the period is there because
	 * every other driver reads it as a schema selector - MySQL a database, PostgreSQL a
	 * schema, core sqlite an ATTACHed file. A Durable Object owns exactly one database
	 * and cannot attach a second, so a period would name something that does not exist.
	 * Every other character core allows works here, because the prefix is folded into
	 * the identifier rather than used to address a schema.
	 */
	public const PREFIX_PATTERN = '/^[A-Za-z0-9_]*$/';

	/**
	 * Constructs a Connection.
	 *
	 * @param object $connection
	 *   The client connection, a CfwSqlClient. The parameter is widened from the
	 *   core sqlite driver's \PDO because there is no PDO here.
	 * @param array $connection_options
	 *   The connection options. 'database' is accepted and ignored: the Durable
	 *   Object's identity selects the database, so the value is documentation.
	 *   'prefix' is honoured by name-mangling; see PREFIX_PATTERN.
	 *
	 * @throws HostBridgeException
	 *   If the client is not a CfwSqlClient, or the prefix names a schema.
	 */
	public function __construct(object $connection, array $connection_options)
	{
		if (!($connection instanceof CfwSqlClient)) {
			throw new HostBridgeException(
				sprintf(
					'The cfw_do_sqlite driver requires a CfwSqlClient as its client connection, got %s.',
					get_debug_type($connection),
				),
			);
		}
		$prefix = (string) ($connection_options['prefix'] ?? '');
		if (!self::isSupportedPrefix($prefix)) {
			throw new HostBridgeException(
				sprintf(
					"The table prefix '%s' cannot be used: a period in a prefix selects a schema, and ctx.storage.sql exposes exactly one database with no ATTACH to add another. Prefixes without a period work; they are folded into the table identifier.",
					$prefix,
				),
			);
		}

		// the core sqlite constructor types its client as \PDO and turns a prefix into an
		// ATTACHed database, so the base constructor is invoked directly; its own
		// setPrefix() is the name-mangling implementation this driver wants
		DatabaseConnection::__construct($connection, $connection_options);
	}

	/**
	 * Returns whether a table prefix can be honoured by this driver.
	 *
	 * @param string $prefix
	 *   The configured prefix; an empty string is always supported.
	 *
	 * @return bool
	 *   TRUE when the prefix can be folded into an identifier.
	 */
	public static function isSupportedPrefix(string $prefix): bool
	{
		return preg_match(self::PREFIX_PATTERN, $prefix) === 1;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function open(array &$connection_options = [])
	{
		// init_commands are deliberately not executed. The core driver sets
		// "PRAGMA journal_mode=WAL" here; ctx.storage.sql manages its own
		// journalling and exposes no user-visible WAL, so the pragma is at best
		// ignored and at worst refused.
		return new CfwSqlClient();
	}

	/**
	 * {@inheritdoc}
	 */
	public function __destruct()
	{
		// the sqlite destructor prunes attached database files when a table has been
		// dropped; there are no files and getAttachedDatabases() is synthetic, so
		// only the base cycle-breaking behaviour is wanted
		DatabaseConnection::__destruct();
	}

	/**
	 * {@inheritdoc}
	 */
	public function driver()
	{
		return 'cfw_do_sqlite';
	}

	/**
	 * {@inheritdoc}
	 */
	public function databaseType()
	{
		// the engine is SQLite, and code branching on databaseType() is asking
		// about the dialect rather than about the driver
		return 'sqlite';
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDatabase($database)
	{
		// the Durable Object's storage always exists
	}

	/**
	 * {@inheritdoc}
	 */
	public function attachDatabase(string $database): void
	{
		throw new HostBridgeException(
			sprintf(
				"Cannot attach '%s': ctx.storage.sql exposes exactly one database and has no ATTACH.",
				$database,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAttachedDatabases()
	{
		// the one schema that exists, reported so that Schema::findTables() has something
		// to iterate; the backing property stays empty so the sqlite destructor never
		// tries to prune a file
		return ['main' => 'main'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function prepareStatement(
		string $query,
		array $options,
		bool $allow_row_count = false,
	): StatementInterface {
		assert(
			!isset($options['return']),
			'Passing "return" option to prepareStatement() has no effect. See https://www.drupal.org/node/3185520',
		);

		try {
			$query = $this->preprocessStatement($query, $options);
			// $options['pdo'] is accepted by the interface and ignored: there are no
			// PDO statement attributes to set
			return new Statement($this->client(), $this, $query, $allow_row_count);
		} catch (Exception $e) {
			// the handler always throws, but core types it void rather than never, so
			// the alternative is falling through with no statement to return
			$this->exceptionHandler()->handleStatementException($e, $query, $options);
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	protected function preprocessStatement(string $query, array $options): string
	{
		// the core driver supplies IF/GREATEST/LEAST/RAND through
		// PDO::sqliteCreateFunction(), which does not exist here, so the four that
		// have an exact builtin equivalent are renamed to it
		return SqlAnalyzer::rewriteFunctions(parent::preprocessStatement($query, $options));
	}

	/**
	 * {@inheritdoc}
	 */
	public function mapConditionOperator($operator)
	{
		// The core driver maps this to GLOB and then overrides GLOB with a PHP
		// function so LIKE's % and _ keep meaning "any run" and "any one". There are
		// no user functions here, and builtin GLOB reads % and _ as literals -- so
		// the unmodified mapping returns no rows and says nothing.
		//
		// The seam is the operator's SQL prefix. Condition::compile() emits
		// `field OP prefix placeholder postfix`, so a marker in the prefix lands
		// immediately before the placeholder and names the argument whose value has
		// to be rewritten from LIKE syntax to GLOB syntax. That rewrite happens in
		// PHP in runStatement(), where it can be exact.
		//
		// The postfix is dropped deliberately: core appends " ESCAPE '\'", and
		// `GLOB ? ESCAPE '\'` is a three-argument GLOB, which the builtin refuses
		// with "wrong number of arguments to function GLOB()". Measured.
		if ($operator === 'LIKE BINARY') {
			return ['operator' => 'GLOB', 'prefix' => self::LIKE_BINARY_MARKER, 'postfix' => ''];
		}
		if ($operator === 'NOT LIKE BINARY') {
			return [
				'operator' => 'NOT GLOB',
				'prefix' => self::LIKE_BINARY_MARKER,
				'postfix' => '',
			];
		}

		return parent::mapConditionOperator($operator);
	}

	/**
	 * Rewrites marked LIKE patterns into GLOB patterns.
	 *
	 * Called before anything looks at the statement, so a buffered write stores the
	 * translated form and a speculative replay cannot disagree with the commit.
	 *
	 * @param string $sql
	 *   The statement; markers are removed in place.
	 * @param array $params
	 *   The parameters; marked values are translated in place.
	 *
	 * @throws InvalidQueryException
	 *   If a marker is not followed by a resolvable named placeholder, or if the
	 *   rewrite itself fails. Refusing is the point: an untranslated pattern
	 *   silently matches nothing.
	 */
	private function translateLikeBinary(string &$sql, array &$params): array
	{
		if (!str_contains($sql, self::LIKE_BINARY_MARKER)) {
			return [];
		}

		// filled only where a pattern had to be WIDENED to fit the host's ceiling; see
		// widenOversizedPattern(). Empty is the normal case and costs nothing
		$postFilters = [];
		$widenable = $this->patternWideningAllowed($sql);
		$marker = preg_quote(self::LIKE_BINARY_MARKER, '/');
		$rewritten = preg_replace_callback(
			'/' . $marker . '\s*(:[A-Za-z0-9_]+|\?)/',
			function (array $m) use (&$params, &$postFilters, $widenable, $sql) {
				$placeholder = $m[1];
				if ($placeholder === '?') {
					// Condition::compile() always emits named placeholders, so reaching
					// this means the marker arrived from somewhere else and the argument
					// cannot be identified by name
					throw new InvalidQueryException(
						sprintf(
							'A LIKE BINARY pattern was marked against a positional placeholder, which cannot be identified by name: %s',
							$sql,
						),
					);
				}
				$key = array_key_exists($placeholder, $params)
					? $placeholder
					: (array_key_exists(substr($placeholder, 1), $params)
						? substr($placeholder, 1)
						: null);
				if ($key === null) {
					throw new InvalidQueryException(
						sprintf(
							"A LIKE BINARY pattern was marked for placeholder '%s', which is not bound: %s",
							$placeholder,
							$sql,
						),
					);
				}
				if (!is_string($params[$key])) {
					throw new InvalidQueryException(
						sprintf(
							"A LIKE BINARY pattern for '%s' is a %s, not a string.",
							$placeholder,
							get_debug_type($params[$key]),
						),
					);
				}
				$glob = SqlAnalyzer::likeToGlob($params[$key]);
				// Measured against ctx.storage.sql: 50 bytes passes, 51 fails with
				// "LIKE or GLOB pattern too complex: SQLITE_ERROR". The check is on the
				// TRANSLATED pattern because bracket-quoting expands a metacharacter
				// threefold (`*` -> `[*]`), so a 20-byte LIKE pattern of asterisks
				// becomes a 60-byte GLOB pattern and would fail where the input looked
				// safe. Refusing here names the cause; letting it through surfaces the
				// engine's message from somewhere unrelated.
				if (strlen($glob) > self::MAX_LIKE_PATTERN_BYTES) {
					$widened = $widenable
						? SqlAnalyzer::widenLikePattern($params[$key], self::MAX_LIKE_PATTERN_BYTES)
						: null;
					if ($widened === null) {
						throw new InvalidQueryException(
							sprintf(
								'This LIKE BINARY pattern is %d bytes once translated to GLOB, and ctx.storage.sql refuses any LIKE or GLOB pattern over %d bytes ("pattern too complex"). Shorten the search term, or filter in PHP. Note plain LIKE has the same ceiling.',
								strlen($glob),
								self::MAX_LIKE_PATTERN_BYTES,
							),
						);
					}
					// the widened pattern returns a SUPERSET, so the original is re-applied to the
					// rows in PHP. Both halves are required and neither is optional: shipping only
					// the widening would silently return extra rows
					$postFilters[] = [
						'column' => self::likeColumnFor($sql, $placeholder),
						'pattern' => $params[$key],
					];
					$params[$key] = SqlAnalyzer::likeToGlob($widened);
					return $placeholder;
				}
				$params[$key] = $glob;
				return $placeholder;
			},
			$sql,
		);
		if ($rewritten === null) {
			// a PCRE failure here would otherwise blank the statement and send an
			// empty string to the host, which reports something unrelated
			throw new InvalidQueryException(
				sprintf(
					'Rewriting a LIKE BINARY pattern failed (%s): %s',
					preg_last_error_msg(),
					$sql,
				),
			);
		}
		$sql = $rewritten;

		// a filter with no column to apply it to would silently drop every row, so it is refused
		// here rather than applied blindly
		foreach ($postFilters as $filter) {
			if ($filter['column'] === null) {
				throw new InvalidQueryException(
					sprintf(
						'A LIKE BINARY pattern is over the %d-byte ceiling and the column it applies to could not be identified, so it cannot be widened and re-filtered: %s',
						self::MAX_LIKE_PATTERN_BYTES,
						$sql,
					),
				);
			}
		}

		return $postFilters;
	}

	/**
	 * Whether an oversized pattern in this statement may be widened and re-filtered.
	 *
	 * REFUSES FAR MORE THAN IT ACCEPTS, on the same reasoning as `Statement::splitPointFor()`: a
	 * widened pattern returns a superset, and a superset is only recoverable when the engine has
	 * not already made a decision from it.
	 *
	 *   - not a SELECT: a DELETE or UPDATE would have already written to the extra rows, and no
	 *     post-filter can un-delete anything. This is the refusal that matters most.
	 *   - `LIMIT` or `OFFSET`: the engine cuts the superset, then the filter removes rows from what
	 *     is left, so the caller gets fewer rows than it asked for and cannot tell.
	 *   - an aggregate or `GROUP BY`: the answer is a fold over the rows, and folding a superset
	 *     gives a different number that no row filter can correct.
	 */
	private static function patternWideningAllowed(string $sql): bool
	{
		if (preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
			return false;
		}

		return preg_match(
			'/\b(?:LIMIT|OFFSET|GROUP\s+BY|HAVING|COUNT\s*\(|SUM\s*\(|MIN\s*\(|MAX\s*\(|AVG\s*\()/i',
			$sql,
		) !== 1;
	}

	/**
	 * The column an oversized LIKE applies to, or NULL when it cannot be read off the statement.
	 *
	 * Read from the SQL rather than guessed, and the alias is dropped because the ROW comes back
	 * keyed by the select-list name. Anything more elaborate than `[table].[column] LIKE :name`
	 * returns NULL and the caller refuses -- which is the right outcome: a wrong column would drop
	 * every row and look like an empty result set.
	 */
	private static function likeColumnFor(string $sql, string $placeholder): ?string
	{
		$quoted = preg_quote($placeholder, '/');
		$pattern =
			'/(?:\[?([A-Za-z0-9_]+)\]?\.)?\[?([A-Za-z0-9_]+)\]?\s+LIKE\s+(?:BINARY\s+)?' .
			preg_quote(self::LIKE_BINARY_MARKER, '/') .
			'?\s*' .
			$quoted .
			'\b/i';
		if (preg_match($pattern, $sql, $m) !== 1) {
			return null;
		}

		return $m[2] === '' ? null : $m[2];
	}

	/**
	 * Re-applies an original LIKE pattern to rows fetched with a widened one.
	 *
	 * Built as a regex rather than by calling `like` again, because there is no `like` to call:
	 * the whole point is that the engine could not carry this pattern. `%` and `_` are the only
	 * two wildcards, `\` escapes them, and everything else is a literal.
	 *
	 * @param array $rows
	 *   Rows as the host returned them.
	 * @param array $filters
	 *   Each `['column' => string, 'pattern' => string]`.
	 *
	 * @return array
	 *   The rows that match every filter.
	 */
	private static function applyLikeFilters(array $rows, array $filters): array
	{
		if ($filters === []) {
			return $rows;
		}
		$regexes = [];
		foreach ($filters as $filter) {
			$regexes[] = [
				'column' => $filter['column'],
				'regex' => self::likeToRegex($filter['pattern']),
			];
		}

		$kept = [];
		foreach ($rows as $row) {
			$keep = true;
			foreach ($regexes as $filter) {
				$value = is_array($row)
					? $row[$filter['column']] ?? null
					: $row->{$filter['column']} ?? null;
				// a NULL column never matched the pattern in the engine either
				if (!is_string($value) || preg_match($filter['regex'], $value) !== 1) {
					$keep = false;
					break;
				}
			}
			if ($keep) {
				$kept[] = $row;
			}
		}

		return $kept;
	}

	/**
	 * One LIKE pattern as a case-SENSITIVE anchored regex.
	 *
	 * Case-sensitive because the marker this whole path exists for is LIKE **BINARY**; a
	 * case-insensitive filter here would keep rows the engine's own GLOB would have dropped.
	 */
	private static function likeToRegex(string $pattern): string
	{
		$out = '';
		$length = strlen($pattern);
		for ($i = 0; $i < $length; $i++) {
			$ch = $pattern[$i];
			if ($ch === '\\' && $i + 1 < $length) {
				$out .= preg_quote($pattern[++$i], '/');
			} elseif ($ch === '%') {
				$out .= '.*';
			} elseif ($ch === '_') {
				$out .= '.';
			} else {
				$out .= preg_quote($ch, '/');
			}
		}

		return '/^' . $out . '$/sD';
	}

	/**
	 * {@inheritdoc}
	 */
	public function exceptionHandler()
	{
		return new ExceptionHandler();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Overridden ONLY to reach this driver's Upsert. Drupal 11 resolves query classes by
	 * standard autoloading inside the method that returns them rather than through
	 * getDriverClass(), which now throws for 'Upsert' -- so without this override, core's
	 * sqlite Connection::upsert() resolves `Upsert` in the sqlite namespace and this
	 * driver's subclass is never constructed.
	 *
	 * That matters because the host caps a statement at 100 bound parameters and core's
	 * sqlite Upsert emits one multi-row statement of rows x fields. See Upsert.php.
	 */
	public function upsert($table, array $options = [])
	{
		return new Upsert($this, $table, $options);
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema()
	{
		if (empty($this->schema)) {
			$this->schema = new Schema($this);
		}
		return $this->schema;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function driverTransactionManager(): TransactionManagerInterface
	{
		return new TransactionManager($this);
	}

	/**
	 * {@inheritdoc}
	 */
	public function version()
	{
		if ($this->engineVersion !== null) {
			return $this->engineVersion;
		}

		try {
			$this->engineVersion = (string) $this->query(
				'SELECT sqlite_version() AS [version]',
			)->fetchField();
			$this->engineVersionIsFloor = false;
		} catch (Exception) {
			// Measured, not inferred: workerd's SQL authorizer refuses the call with
			// "not authorized to use function: sqlite_version". So the engine will not
			// report its own version and the only honest answer is a floor proven by
			// feature probe. Drupal 11.4.5 gates installation on >= 3.45, which a
			// guess could not be trusted to clear.
			$this->engineVersion = $this->probeVersionFloor();
			$this->engineVersionIsFloor = true;
		}

		return $this->engineVersion;
	}

	/**
	 * Establishes a lower bound on the engine version by probing for features.
	 *
	 * Each candidate landed in exactly one SQLite release, so the highest one that
	 * answers is a proven floor rather than an estimate. Ordered ascending; the
	 * last success wins.
	 *
	 * @return string
	 *   The highest proven version, as a three-part string.
	 */
	private function probeVersionFloor(): string
	{
		$ladder = [
			// iif() -- 3.32.0
			'3.32.0' => 'SELECT iif(1,2,3)',
			// math functions -- 3.35.0
			'3.35.0' => 'SELECT pow(2,3)',
			// concat() -- 3.44.0
			'3.44.0' => "SELECT concat('a','b')",
			// jsonb -- 3.45.0
			'3.45.0' => "SELECT hex(jsonb('{}'))",
			// unhex() -- 3.46.0
			'3.46.0' => "SELECT unhex('41')",
		];

		$floor = '3.0.0';
		foreach ($ladder as $release => $sql) {
			try {
				$this->query($sql);
				$floor = $release;
			} catch (Exception) {
				// this release's marker is absent, so the floor stays where it was
				break;
			}
		}

		return $floor;
	}

	/**
	 * Returns whether version() reported a probed floor rather than a real version.
	 *
	 * Anything that logs or displays the engine version should say so, because a
	 * floor is not the version.
	 *
	 * @return bool
	 *   TRUE when the engine refused to report its version.
	 */
	public function engineVersionIsFloor(): bool
	{
		// resolve the version first, or the flag has not been set yet
		$this->version();
		return $this->engineVersionIsFloor;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Ctx.storage.sql refuses CREATE TEMPORARY TABLE with "not authorized:
	 * SQLITE_AUTH", measured. The core sqlite driver implements this and our class
	 * inherits SupportsTemporaryTablesInterface from it, so an `instanceof` check
	 * cannot be made to fail; throwing is the only way a caller learns.
	 *
	 * Auditing core for callers found none outside the interface declaration and
	 * the three driver implementations, so nothing in Drupal 11.4.5 reaches this.
	 */
	public function queryTemporary($query, array $args = [], array $options = [])
	{
		throw new InvalidQueryException(
			'ctx.storage.sql refuses CREATE TEMPORARY TABLE (SQLITE_AUTH), so this driver has no temporary tables. Drupal core has no queryTemporary() callers; materialise the result in PHP or write to a real table you drop.',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function clientVersion()
	{
		// the client and the engine are the same build, linked into the runtime
		return $this->version();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $string
	 *   The string to quote.
	 * @param int $parameter_type
	 *   Ignored. Spelled as an int rather than PDO::PARAM_STR so the driver does
	 *   not require the PDO extension to be present.
	 */
	public function quote($string, $parameter_type = 2)
	{
		return "'" . str_replace("'", "''", (string) $string) . "'";
	}

	/**
	 * {@inheritdoc}
	 */
	public function lastInsertId(?string $name = null): string
	{
		if ($this->buffer !== null && $this->buffer->hasInsert()) {
			return $this->bufferedInsertId();
		}
		return $this->client()->lastInsertId();
	}

	/**
	 * Returns how many statements this connection has sent to the host.
	 *
	 * Includes replays, so a transaction resolved speculatively costs more than
	 * one statement per Drupal query. Useful for the per-request query budget.
	 *
	 * @return int
	 *   The count.
	 */
	public function statementCount(): int
	{
		return $this->client()->statementCount();
	}

	/**
	 * Returns how many host transactions this connection has opened.
	 *
	 * Committing and speculative together, because both are a BEGIN on the host and both are
	 * billed as one.
	 *
	 * @return int
	 *   The count.
	 */
	public function transactionCount(): int
	{
		return $this->client()->transactionCount();
	}

	/**
	 * Returns how many of those transactions were speculative.
	 *
	 * A speculative transaction is replayed and rolled back so a read can observe buffered
	 * writes, or so a row count or an insert id can be resolved before commit.
	 *
	 * @return int
	 *   The count.
	 */
	public function speculativeCount(): int
	{
		return $this->client()->speculativeCount();
	}

	/**
	 * Returns how many statements were re-sent inside speculative replays.
	 *
	 * The three counters answer three different questions and none of them substitutes for
	 * another: statementCount() is bridge crossings, transactionCount() is host BEGINs, and this
	 * is work the host did inside them. A replay cache would leave the first two alone and move
	 * only this one, which is what makes it a measurable change rather than a hopeful one.
	 *
	 * @return int
	 *   The count.
	 */
	public function replayedStatementCount(): int
	{
		return $this->client()->replayedStatementCount();
	}

	/**
	 * Returns whether a buffered transaction can be committed atomically.
	 *
	 * FALSE means the host has no transaction entry point, so a commit replays
	 * the buffer statement by statement and a failure part-way through leaves the
	 * earlier statements applied.
	 *
	 * @return bool
	 *   TRUE when the host exposes its transaction primitive.
	 */
	public function supportsAtomicCommit(): bool
	{
		return $this->client()->supportsTransactions();
	}

	/**
	 * Returns whether a Drupal transaction is currently buffering writes.
	 *
	 * @return bool
	 *   TRUE when writes are being withheld.
	 */
	public function isBuffering(): bool
	{
		return $this->buffer !== null;
	}

	/**
	 * Runs a statement, or buffers it, and returns what the caller needs.
	 *
	 * This is the whole of the transaction mapping, and the read case is the part
	 * that is not obvious.
	 *
	 * With no Drupal transaction open, everything goes straight to the host. The
	 * Durable Object still wraps each event in an implicit transaction of its own,
	 * so a single statement is atomic without any help.
	 *
	 * With a transaction open:
	 * - a write is buffered, and returns no rows and no row count;
	 * - a read whose tables have no buffered write is proven unaffected, and goes
	 *   straight to the host;
	 * - a read whose tables do have buffered writes has to observe them, so the
	 *   buffer is replayed and the read evaluated inside a host transaction that
	 *   is then rolled back. Without the host's transaction entry point that is
	 *   impossible, and the driver raises UncommittedStateException rather than
	 *   answering from the committed database, which would be quietly wrong;
	 * - a statement that cannot be classified is refused for the same reason.
	 *
	 * @param string $sql
	 *   The statement, prefixes and identifier quotes already resolved.
	 * @param array $params
	 *   The parameters, ready for the host.
	 *
	 * @return array{rows: array, rowCount: int|null, bufferIndex: int|null}
	 *   The rows, the number of rows changed where it is known, and the buffer
	 *   index when the statement was withheld.
	 *
	 * @throws UncommittedStateException
	 *   If the statement cannot be answered without observing buffered state.
	 * @throws SqlErrorException
	 *   If SQLite rejected the statement.
	 */
	public function runStatement(string $sql, array $params): array
	{
		// before classification and before buffering, so every later reader of the
		// statement sees the translated form
		$likeFilters = $this->translateLikeBinary($sql, $params);
		// BEFORE the write reaches the buffer, so a transaction is refused at the statement that
		// is too large rather than at the commit that replays it -- the engine's own message names
		// neither the column nor the cap, and by commit time the statement is one of many
		self::refuseOversizedParameter($sql, $params);

		$kind = SqlAnalyzer::classify($sql);

		if ($kind === SqlAnalyzer::TRANSACTION_CONTROL) {
			throw new UncommittedStateException(
				sprintf(
					'This driver never issues transaction control, because ctx.storage.sql refuses it; something bypassed the transaction manager with: %s',
					$sql,
				),
			);
		}

		// DDL can turn a table that appends into one that does not, so the memo it would be read
		// from is dropped here rather than being kept correct. Conservative on a rolled-back
		// buffer, which costs one re-read
		if (
			$kind === SqlAnalyzer::WRITE &&
			preg_match('/^\s*(?:CREATE|DROP|ALTER)\b/i', $sql) === 1
		) {
			$this->rowidSchema = [];
		}

		if ($this->buffer === null) {
			// ONE exit for every read path, so a widened pattern cannot reach a caller unfiltered
			// through a branch somebody forgot. `$likeFilters` is empty on every statement that
			// did not need widening, and empty on every write by construction
			return self::filterOutcome($this->runDirect($sql, $params), $likeFilters);
		}

		if ($kind === SqlAnalyzer::WRITE) {
			return [
				'rows' => [],
				'rowCount' => null,
				'bufferIndex' => $this->buffer->add(
					$sql,
					$params,
					SqlAnalyzer::writtenTables($sql),
				),
			];
		}

		if ($kind === SqlAnalyzer::UNKNOWN) {
			throw new UncommittedStateException(
				sprintf(
					'Cannot tell whether this statement reads or writes, and %d write(s) are buffered, so neither running it nor withholding it is safe: %s',
					$this->buffer->count(),
					$sql,
				),
			);
		}

		if ($this->buffer->isEmpty() || !$this->buffer->touches(SqlAnalyzer::readTables($sql))) {
			return self::filterOutcome($this->runDirect($sql, $params), $likeFilters);
		}

		return self::filterOutcome($this->runSpeculativeRead($sql, $params), $likeFilters);
	}

	/**
	 * Refuses a parameter larger than the host's record cap, naming what and where.
	 *
	 * The DECLARED half of the record-cap limit; see `MAX_RECORD_BYTES` for why the driver does not
	 * chunk instead. A module that wrote legal SQL gets a message it can act on rather than a
	 * generic write failure from the engine, and a `hook_requirements()` row can quote it verbatim.
	 *
	 * Checked on the PARAMETER rather than on the rendered statement: the statement text has its
	 * own separate 100,000-character ceiling, and conflating the two would report the wrong limit.
	 *
	 * @throws InvalidQueryException
	 *   Naming the placeholder, its size and the cap.
	 */
	private static function refuseOversizedParameter(string $sql, array $params): void
	{
		foreach ($params as $name => $value) {
			if (!is_string($value) || strlen($value) <= self::MAX_RECORD_BYTES) {
				continue;
			}
			throw new InvalidQueryException(
				sprintf(
					'Parameter %s is %d bytes and ctx.storage.sql caps one record at %d, so this write is refused rather than truncated. This driver does not split an oversized value across a side table: at a 200,000-byte chunk that is one row per chunk on the daily rows-written meter, and every read of the row becomes as many reads. Chunk the value in the module, where its structure is known. Statement: %s',
					is_string($name) ? $name : '#' . $name,
					strlen($value),
					self::MAX_RECORD_BYTES,
					substr($sql, 0, 200),
				),
			);
		}
	}

	/**
	 * Applies any LIKE post-filters to an outcome's rows.
	 *
	 * `rowCount` is left alone: a SELECT writes nothing, so the field carries no meaning that the
	 * filter could invalidate, and `Statement` resolves its own count from the rows it received.
	 */
	private static function filterOutcome(array $outcome, array $filters): array
	{
		if ($filters === []) {
			return $outcome;
		}
		$outcome['rows'] = self::applyLikeFilters($outcome['rows'], $filters);

		return $outcome;
	}

	/**
	 * Opens the write buffer.
	 *
	 * @throws UncommittedStateException
	 *   If a buffer is already open, which would mean the transaction stack and
	 *   the driver disagree about the depth.
	 */
	public function beginBufferedTransaction(): void
	{
		if ($this->buffer !== null) {
			throw new UncommittedStateException(
				'A transaction buffer is already open; nested root transactions are collapsed by the transaction manager and should never reach here.',
			);
		}
		$this->buffer = new TransactionBuffer();
		$this->rowidMax = [];
	}

	/**
	 * Discards the write buffer, which is what a rollback is.
	 */
	public function discardBufferedTransaction(): void
	{
		$this->buffer = null;
		$this->rowidMax = [];
	}

	/**
	 * Discards a transaction a halted request left open, and says what that cost.
	 *
	 * On a real SAPI this state cannot exist: the process dies, the Transaction object is
	 * destructed and TransactionManagerBase rolls back. Here the interpreter outlives the request
	 * and Database::$connections holds this object, so a script that ends before its commit leaves
	 * the buffer open -- and every write the NEXT request makes is withheld into a buffer nobody
	 * will replay. Measured on a warm object: the following render answered 200 with the same byte
	 * count as a clean one, wrote nothing, and left isBuffering() still TRUE.
	 *
	 * Both halves have to go. Discarding the buffer alone leaves Drupal's own stack believing a
	 * transaction is open, so the next startTransaction() records a savepoint into a buffer that no
	 * longer exists and the request dies with UncommittedStateException instead.
	 *
	 * The manager is dropped rather than unwound. Unpiling would run the rollback path for stack
	 * items whose savepoints are indexes into the buffer this method just threw away, and
	 * transactionManager() rebuilds a clean one on next use.
	 *
	 * Safe only at a REQUEST BOUNDARY, where anything open is by definition orphaned. Calling it
	 * inside a live transaction discards writes the caller believes it has made.
	 *
	 * @return array{buffered: int, stack: int, manager: bool}
	 *   How many buffered statements and stack items were discarded and whether the manager was
	 *   dropped. All zero and FALSE on a clean boundary, which is what a passing probe looks like.
	 */
	public function discardOrphanedTransaction(): array
	{
		$out = ['buffered' => 0, 'stack' => 0, 'manager' => false];

		if ($this->buffer !== null) {
			$out['buffered'] = $this->buffer->count();
			$this->buffer = null;
			$this->rowidMax = [];
		}

		if (!isset($this->transactionManager)) {
			return $out;
		}

		$manager = $this->transactionManager;
		// emptied before the manager is dropped, because TransactionManagerBase::__destruct()
		// asserts the stack is empty and an assertion build would fatal on the way out
		foreach (['stack', 'voidedItems', 'postTransactionCallbacks'] as $name) {
			try {
				$property = new ReflectionProperty(TransactionManagerBase::class, $name);
				if ($name === 'stack') {
					$out['stack'] = count((array) $property->getValue($manager));
				}
				$property->setValue($manager, []);
			} catch (Throwable) {
				// a runtime that renamed the property should lose this reset, not refuse to serve
			}
		}

		// REPLACED rather than unset. The property is typed and non-nullable, so unset() would leave
		// it uninitialised for transactionManager() to rebuild -- which works, and which phpstan
		// refuses because a subclass may declare property hooks on it. Building the replacement here
		// is the same outcome with no uninitialised window.
		$this->transactionManager = $this->driverTransactionManager();
		$out['manager'] = true;

		return $out;
	}

	/**
	 * Replays the write buffer and closes it.
	 *
	 * The buffer is detached before the replay, so a failure cannot leave the
	 * connection buffering into a transaction Drupal has already finished with.
	 *
	 * @throws SqlErrorException
	 *   If SQLite rejected one of the replayed statements. With the host's
	 *   transaction entry point the whole replay is rolled back; without it, the
	 *   statements before the failure remain applied.
	 */
	public function commitBufferedTransaction(): void
	{
		$buffer = $this->buffer;
		$this->buffer = null;
		$this->rowidMax = [];

		if ($buffer === null || $buffer->isEmpty()) {
			return;
		}

		$statements = $buffer->statements();
		if ($this->client()->supportsTransactions()) {
			$this->client()->runTransaction($statements, true);
			return;
		}

		// Degraded path: without the host's transaction primitive the replay is a
		// sequence of independent statements. Rollback stayed correct - nothing was
		// written before this point - but a failure mid-replay is not undone.
		foreach ($statements as $statement) {
			$this->client()->exec($statement['sql'], $statement['params']);
		}
	}

	/**
	 * Records a savepoint at the current buffer position.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function markBufferSavepoint(string $name): void
	{
		$this->requireBuffer('create savepoint ' . $name)->mark($name);
	}

	/**
	 * Discards everything buffered since a savepoint.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function rollbackBufferToSavepoint(string $name): void
	{
		$this->requireBuffer('roll back to savepoint ' . $name)->rollbackTo($name);
	}

	/**
	 * Forgets a savepoint, keeping what was buffered after it.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function releaseBufferSavepoint(string $name): void
	{
		$this->requireBuffer('release savepoint ' . $name)->release($name);
	}

	/**
	 * Resolves how many rows a buffered write changed.
	 *
	 * Nothing has run, so the only way to know is to replay the buffer up to that
	 * statement and read the counter, then discard the replay. That costs one
	 * host call and O(buffered statements) statement executions inside it, which
	 * is why Statement caches the answer.
	 *
	 * A replay that has already covered this index answers for free; see
	 * TransactionBuffer::rememberResults().
	 *
	 * @param int $index
	 *   The buffer index of the statement.
	 *
	 * @return int
	 *   The number of rows the statement would change.
	 *
	 * @throws UncommittedStateException
	 *   If the buffer is gone, or the host cannot run a speculative replay.
	 */
	public function resolveBufferedRowCount(int $index): int
	{
		$buffer = $this->requireBuffer('resolve the row count of a buffered write');

		$cached = $buffer->resolvedResult($index);
		if ($cached !== null) {
			return $cached['changes'];
		}

		// a single-row insert changed one row, which is arithmetic rather than a question for the
		// engine. No schema is involved, so this holds even where the rowid cannot be predicted
		if ($buffer->isSingleRowInsert($index)) {
			return 1;
		}

		if (!$this->client()->supportsTransactions()) {
			throw new UncommittedStateException(
				sprintf(
					"The number of rows changed by a buffered write cannot be known without replaying the buffer, and the host has not installed '%s'. Either add that entry point or do not ask for a row count inside a transaction.",
					CfwSqlClient::TRANSACTION_BRIDGE,
				),
			);
		}

		$this->speculate($buffer, $index);
		$result = $buffer->resolvedResult($index);

		return $result !== null ? $result['changes'] : 0;
	}

	/**
	 * Sends a statement straight to the host.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param array $params
	 *   The parameters.
	 *
	 * @return array{rows: array, rowCount: int|null, bufferIndex: int|null}
	 *   The outcome.
	 */
	private function runDirect(string $sql, array $params): array
	{
		$result = $this->client()->exec($sql, $params);

		return [
			'rows' => $result['rows'],
			'rowCount' => $result['changes'],
			'bufferIndex' => null,
		];
	}

	/**
	 * Answers a read that has to observe buffered writes.
	 *
	 * The buffer is replayed and the read evaluated inside one host transaction
	 * which is then rolled back, so the read sees the writes and the database
	 * does not. Throwing inside transactionSync() is the measured rollback
	 * mechanism; this is the same door, used deliberately.
	 *
	 * @param string $sql
	 *   The read.
	 * @param array $params
	 *   Its parameters.
	 *
	 * @return array{rows: array, rowCount: int|null, bufferIndex: int|null}
	 *   The outcome.
	 *
	 * @throws UncommittedStateException
	 *   If the host cannot run a speculative replay.
	 */
	private function runSpeculativeRead(string $sql, array $params): array
	{
		$buffer = $this->requireBuffer('read uncommitted state');

		if (!$this->client()->supportsTransactions()) {
			throw new UncommittedStateException(
				sprintf(
					"This read would have to see %d uncommitted buffered write(s) to %s, and the host has not installed '%s', so the buffer cannot be replayed. Answering from the committed database would return data that is wrong without saying so. Read: %s",
					$buffer->count(),
					implode(', ', SqlAnalyzer::readTables($sql)) ?: 'an unknown table',
					CfwSqlClient::TRANSACTION_BRIDGE,
					$sql,
				),
			);
		}

		// a dirty read replays the WHOLE buffer, so it answers every outstanding insert id
		// and row count at no extra cost; keeping those is the only way the O(W*R) term
		// ever shrinks rather than just being measured
		$replay = $this->speculate($buffer, $buffer->lastIndex(), [
			'sql' => $sql,
			'params' => $params,
		]);
		$read = $replay['readResult'];

		if (!is_array($read)) {
			throw new UncommittedStateException(
				'The host ran the speculative replay but returned no result for the read that needed it.',
			);
		}

		return [
			'rows' => $read['rows'],
			'rowCount' => $read['changes'],
			'bufferIndex' => null,
		];
	}

	/**
	 * Returns the rowid a buffered insert would be given.
	 *
	 * A replay that has already covered this index answers for free; see
	 * TransactionBuffer::rememberResults().
	 *
	 * @return string
	 *   The rowid as a decimal string.
	 *
	 * @throws UncommittedStateException
	 *   If the host cannot run a speculative replay.
	 */
	private function bufferedInsertId(): string
	{
		$buffer = $this->requireBuffer('report the id of a buffered insert');
		$index = $buffer->lastInsertIndex();

		if ($index === null) {
			return $this->client()->lastInsertId();
		}

		$cached = $buffer->resolvedResult($index);
		if ($cached !== null) {
			return $cached['lastInsertId'];
		}

		$predicted = $this->predictBufferedInsertId($buffer, $index);
		if ($predicted !== null) {
			return $predicted;
		}

		if (!$this->client()->supportsTransactions()) {
			throw new UncommittedStateException(
				sprintf(
					"An insert inside a transaction has been buffered, so SQLite has not assigned it a rowid yet, and the host has not installed '%s' to work it out. Returning a guessed id would corrupt every entity saved in a transaction.",
					CfwSqlClient::TRANSACTION_BRIDGE,
				),
			);
		}

		$this->speculate($buffer, $index);
		$result = $buffer->resolvedResult($index);

		return $result !== null ? $result['lastInsertId'] : '0';
	}

	/**
	 * Works out a buffered insert's rowid from arithmetic instead of a replay.
	 *
	 * SQLite gives an ordinary rowid table `max(rowid) + 1`, and nothing writes the committed
	 * database while a buffer is open, so the answer is the committed maximum plus the appends
	 * buffered since. RowidPlan holds the second half and refuses every statement shape whose
	 * effect on the rowid is not an append; this holds the first half and refuses the two schema
	 * shapes that break the arithmetic.
	 *
	 * @param TransactionBuffer $buffer
	 *   The open buffer.
	 * @param int $index
	 *   The buffer index of the insert.
	 *
	 * @return string|null
	 *   The rowid as a decimal string, or NULL when it cannot be known without replaying.
	 */
	private function predictBufferedInsertId(TransactionBuffer $buffer, int $index): ?string
	{
		$entry = $buffer->rowidPrediction($index);
		if ($entry === null) {
			return null;
		}

		$schema = $this->rowidSchemaFor($entry['table']);
		if (!$schema['appendable']) {
			return null;
		}
		if (RowidPlan::suppliesRowid($entry['columns'], $schema['integerPrimaryKey'])) {
			return null;
		}

		$base = 0;
		if (!$entry['cleared']) {
			$base = $this->committedMaxRowid($entry['table']);
			if ($base === null) {
				return null;
			}
		}

		return (string) ($base + $entry['offset']);
	}

	/**
	 * Reads whether a plain insert into one table appends, and which column is its rowid.
	 *
	 * @param string $table
	 *   The lower-cased table name.
	 *
	 * @return array{appendable: bool, integerPrimaryKey: string|null}
	 *   A table whose schema cannot be read reports appendable FALSE, which is the replay path.
	 */
	private function rowidSchemaFor(string $table): array
	{
		if (isset($this->rowidSchema[$table])) {
			return $this->rowidSchema[$table];
		}

		$facts = ['appendable' => false, 'integerPrimaryKey' => null];
		try {
			$schema = $this->client()->exec(
				'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
				['table', $table],
			);
			$ddl = (string) ($schema['rows'][0]['sql'] ?? '');
			if ($ddl !== '' && self::isAppendableDdl($ddl)) {
				$facts = [
					'appendable' => true,
					'integerPrimaryKey' => self::integerPrimaryKeyColumn($ddl),
				];
			}
		} catch (Exception) {
			$facts = ['appendable' => false, 'integerPrimaryKey' => null];
		}

		$this->rowidSchema[$table] = $facts;
		return $facts;
	}

	/**
	 * Reads one table's committed maximum rowid, once per buffer.
	 *
	 * Goes straight to the host rather than through runStatement(), and that is the point: the
	 * committed maximum is wanted precisely because the buffered rows are not in it. Routing this
	 * through the buffer would see the table is dirty and open the replay this exists to avoid.
	 *
	 * @param string $table
	 *   The lower-cased table name.
	 *
	 * @return int|null
	 *   The maximum, 0 for an empty table, or NULL when the answer is not a number.
	 */
	private function committedMaxRowid(string $table): ?int
	{
		if (array_key_exists($table, $this->rowidMax)) {
			return $this->rowidMax[$table];
		}

		$max = null;
		try {
			// the name came back from sqlite_master, so it exists exactly as spelled; quoted
			// rather than interpolated bare because a table may be named after a keyword.
			// `_rowid_` rather than `rowid`, which a column of that name would shadow
			$result = $this->client()->exec(
				sprintf('SELECT MAX(_rowid_) AS m FROM "%s"', str_replace('"', '""', $table)),
				[],
			);
			$value = $result['rows'][0]['m'] ?? null;
			// NULL is an empty table, whose next rowid is 1; a non-numeric answer is not one this
			// can reason about, so it falls back rather than guessing zero
			$max = $value === null ? 0 : (is_numeric($value) ? (int) $value : null);
		} catch (Exception) {
			$max = null;
		}

		$this->rowidMax[$table] = $max;
		return $max;
	}

	/**
	 * Returns whether a table's DDL allows a plain insert to be a simple append.
	 *
	 * @param string $ddl
	 *   The CREATE TABLE statement from sqlite_master.
	 *
	 * @return bool
	 *   FALSE for WITHOUT ROWID, which has no rowid at all, and for AUTOINCREMENT, whose next id
	 *   comes from sqlite_sequence.
	 */
	private static function isAppendableDdl(string $ddl): bool
	{
		if (preg_match('/\bWITHOUT\s+ROWID\b/i', $ddl) === 1) {
			return false;
		}
		return preg_match('/\bAUTOINCREMENT\b/i', $ddl) !== 1;
	}

	/**
	 * Names the column that is an alias for the rowid, when the table has one.
	 *
	 * @param string $ddl
	 *   The CREATE TABLE statement from sqlite_master.
	 *
	 * @return string|null
	 *   The lower-cased column name, or NULL when the table has no INTEGER PRIMARY KEY.
	 */
	private static function integerPrimaryKeyColumn(string $ddl): ?string
	{
		$matched = preg_match(
			'/[(,]\s*("?)([A-Za-z_][A-Za-z0-9_$]*)\1\s+INTEGER\s+PRIMARY\s+KEY\b/i',
			$ddl,
			$parts,
		);
		return $matched === 1 ? strtolower($parts[2]) : null;
	}

	/**
	 * Replays the buffer speculatively, repairing it if the engine rejects a statement.
	 *
	 * A buffered write is reported as successful before anything has run, so a
	 * statement SQLite would have refused sits in the buffer looking fine until some
	 * later replay trips over it. Left there it is fatal twice over: every
	 * subsequent replay re-runs it, and so does the commit, so the transaction can
	 * never succeed even once the reason for the refusal is gone. On a real
	 * connection that statement would have failed where it was issued and left no
	 * trace - which is what Drupal's own "write, catch, create the table, carry on"
	 * idiom is built on, and what breaks the router table during a site install.
	 *
	 * So a rejected statement is found, discarded, and its error raised once.
	 *
	 * @param TransactionBuffer $buffer
	 *   The open buffer.
	 * @param int $upTo
	 *   The last buffer index to replay.
	 * @param array{sql: string, params: array}|null $read
	 *   (optional) A read to evaluate inside the same replay.
	 *
	 * @return array{results: array<int, array>, readResult: array|null}
	 *   The host reply.
	 *
	 * @throws SqlErrorException
	 *   If SQLite rejected the read, or a buffered statement that has now been
	 *   discarded.
	 */
	private function speculate(TransactionBuffer $buffer, int $upTo, ?array $read = null): array
	{
		try {
			$replay = $this->client()->runTransaction($buffer->statementsUpTo($upTo), false, $read);
		} catch (SqlErrorException $e) {
			$rejected = $this->findRejectedStatement($buffer, $upTo, $read !== null);
			if ($rejected === null) {
				// nothing buffered is at fault, so the trailing read is
				throw $e;
			}
			$sql = $buffer->sqlAt($rejected);
			$buffer->discardFailed($rejected);
			// re-attributed to the statement that actually failed, which is not
			// necessarily the one whose id or row count was being asked for
			throw new SqlErrorException($e->getSqlError(), $sql);
		}

		$buffer->rememberResults($replay['results'], $upTo);

		return $replay;
	}

	/**
	 * Finds which buffered statement a failed replay tripped over.
	 *
	 * The host reports one error for the whole replay and does not say which
	 * statement raised it, so the position is recovered by bisection: the answer is
	 * the shortest prefix that still fails. Every probe is a speculative replay and
	 * is counted as one, but this only runs on a path that is already an error, and
	 * a prefix an earlier replay has already resolved is skipped - the committed
	 * state cannot change while the buffer is open, so a replay of the same prefix
	 * is deterministic.
	 *
	 * @param TransactionBuffer $buffer
	 *   The open buffer.
	 * @param int $upTo
	 *   The last buffer index the failed replay covered.
	 * @param bool $hadRead
	 *   Whether that replay carried a trailing read, which may be the real culprit.
	 *
	 * @return int|null
	 *   The buffer index of the rejected statement, or NULL when the buffer replays
	 *   cleanly and the fault is elsewhere.
	 */
	private function findRejectedStatement(
		TransactionBuffer $buffer,
		int $upTo,
		bool $hadRead,
	): ?int {
		$live = $buffer->liveIndexesUpTo($upTo);
		if ($live === []) {
			return null;
		}
		if ($hadRead && $this->replaySucceeds($buffer, $upTo)) {
			return null;
		}

		$low = 0;
		while ($low < count($live) && $buffer->resolvedResult($live[$low]) !== null) {
			$low++;
		}
		$high = count($live) - 1;
		if ($low > $high) {
			// every statement here has replayed successfully before, so the buffer is
			// not what changed; report nothing rather than discard an innocent statement
			return null;
		}

		while ($low < $high) {
			$middle = intdiv($low + $high, 2);
			if ($this->replaySucceeds($buffer, $live[$middle])) {
				$low = $middle + 1;
			} else {
				$high = $middle;
			}
		}

		return $live[$low];
	}

	/**
	 * Returns whether a prefix of the buffer replays without error.
	 *
	 * @param TransactionBuffer $buffer
	 *   The open buffer.
	 * @param int $upTo
	 *   The last buffer index to replay.
	 *
	 * @return bool
	 *   TRUE when the host accepted every statement. A successful probe keeps what
	 *   it learned, so bisecting is not wasted work.
	 */
	private function replaySucceeds(TransactionBuffer $buffer, int $upTo): bool
	{
		try {
			$replay = $this->client()->runTransaction($buffer->statementsUpTo($upTo), false);
		} catch (SqlErrorException) {
			return false;
		}

		$buffer->rememberResults($replay['results'], $upTo);

		return true;
	}

	/**
	 * Returns the open buffer.
	 *
	 * @param string $action
	 *   What the caller was trying to do, for the error message.
	 *
	 * @return TransactionBuffer
	 *   The buffer.
	 *
	 * @throws UncommittedStateException
	 *   If no transaction is open.
	 */
	private function requireBuffer(string $action): TransactionBuffer
	{
		if ($this->buffer === null) {
			throw new UncommittedStateException(
				sprintf('Cannot %s: no Drupal transaction is open on this connection.', $action),
			);
		}
		return $this->buffer;
	}

	/**
	 * Returns the client connection, typed.
	 *
	 * @return CfwSqlClient
	 *   The client.
	 *
	 * @throws HostBridgeException
	 *   If the connection has already been torn down.
	 */
	private function client(): CfwSqlClient
	{
		if (!($this->connection instanceof CfwSqlClient)) {
			throw new HostBridgeException(
				'The client connection is gone; the Connection object has been destructed or was built without one.',
			);
		}
		return $this->connection;
	}
}

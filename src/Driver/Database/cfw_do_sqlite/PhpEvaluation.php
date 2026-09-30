<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\InvalidQueryException;

/**
 * Evaluates in PHP the SQL functions ctx.storage.sql cannot run.
 *
 * Drupal's own SQLite driver supplies REGEXP, MD5() and SUBSTRING_INDEX() through
 * PDO::sqliteCreateFunction(), and ctx.storage.sql has no user-defined functions. So the
 * statement is rewritten into one the engine can answer and the rest is done on the rows:
 *
 *   - `col [NOT] REGEXP :p` in a SELECT, as a top-level AND condition: the condition becomes `1`,
 *     the column is added under a private alias, and the rows are kept where core's own
 *     `preg_match('#p#i')` agrees. LIMIT/OFFSET, DISTINCT and a `SELECT COUNT(*) FROM (...)`
 *     pager wrapper are applied after the filter, because applying them to the superset would
 *     answer wrong.
 *   - `MD5(col) AS a` and `SUBSTRING_INDEX(col, d, n) AS a` in the top-level select list: the
 *     column is fetched and the function applied to the value.
 *
 * Everything else that names one of these is left for the engine, which refuses it by name.
 */
final class PhpEvaluation
{
	private const ALIAS = '__cfw_rx_';

	/**
	 * A column reference: "t"."c", t.c, "c" or c.
	 */
	private const COLUMN = '(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_]*)(?:\s*\.\s*(?:"[^"]+"|[A-Za-z_][A-Za-z0-9_]*))?';

	/**
	 * Plans the rewrite, or returns NULL when the statement needs none.
	 *
	 * @return array|null
	 *   `sql`, and what `apply()` needs: `filters`, `computed`, `limit`, `offset`, `distinct`,
	 *   `count`.
	 *
	 * @throws InvalidQueryException
	 *   For a REGEXP a superset cannot recover: in a write, under OR or NOT, or beside an aggregate.
	 */
	public static function plan(string $sql, array &$params): ?array
	{
		$masked = self::mask($sql);
		$hasRegexp = preg_match('/\bREGEXP\b/i', $masked) === 1;
		$hasFunction = preg_match('/\b(?:MD5|SUBSTRING_INDEX)\s*\(/i', $masked) === 1;
		if (!$hasRegexp && !$hasFunction) {
			return null;
		}
		if (preg_match('/^\s*SELECT\b/i', $masked) !== 1) {
			if ($hasRegexp) {
				self::refuse('REGEXP in a statement that writes', $sql);
			}
			return null;
		}

		$plan = [
			'filters' => [],
			'computed' => [],
			'limit' => null,
			'offset' => 0,
			'distinct' => false,
			'count' => null,
			'unbind' => [],
		];
		$start = 0;
		$end = strlen($sql);
		// the pager wraps the query it counts, so the count has to be taken after the filter
		if (
			$hasRegexp &&
			preg_match(
				'/^\s*SELECT\s+COUNT\s*\(\s*\*\s*\)\s+AS\s+"?([A-Za-z0-9_]+)"?\s+FROM\s+\(/i',
				$masked,
				$m,
				PREG_OFFSET_CAPTURE,
			) === 1
		) {
			$open = $m[0][1] + strlen($m[0][0]) - 1;
			$close = self::matching($masked, $open);
			if (
				$close !== null &&
				preg_match(
					'/^\s*(?:AS\s+)?"?[A-Za-z0-9_]+"?\s*$/i',
					substr($masked, $close + 1),
				) === 1
			) {
				$plan['count'] = $m[1][0];
				$start = $open + 1;
				$end = $close;
			}
		}

		$inner = substr($sql, $start, $end - $start);
		$innerMasked = substr($masked, $start, $end - $start);
		$rewritten = $hasRegexp
			? self::planRegexp($inner, $innerMasked, $params, $plan, $sql)
			: $inner;
		if ($hasFunction && $plan['count'] === null) {
			$rewritten = self::planFunctions($rewritten, $params, $plan);
		}
		if ($plan['filters'] === [] && $plan['computed'] === []) {
			return null;
		}
		// under a count wrapper the engine answers the inner rows and the count is taken in PHP
		$plan['sql'] = $rewritten;
		// a placeholder the rewrite removed must not stay bound; the engine refuses an unused one
		foreach ($plan['unbind'] as $key) {
			$name = $key[0] === ':' ? $key : ':' . $key;
			if (preg_match('/' . preg_quote($name, '/') . '\b/', $rewritten) !== 1) {
				unset($params[$key]);
			}
		}
		unset($plan['unbind']);
		return $plan;
	}

	/**
	 * Applies a plan to the rows the engine returned.
	 */
	public static function apply(array $rows, array $plan): array
	{
		$kept = [];
		foreach ($rows as $row) {
			$values = is_array($row) ? $row : get_object_vars($row);
			foreach ($plan['filters'] as $filter) {
				$value = $values[$filter['alias']] ?? null;
				// a NULL column is NULL under REGEXP and NOT REGEXP alike, so never kept
				if ($value === null) {
					continue 2;
				}
				$match =
					preg_match(
						'#' . addcslashes($filter['pattern'], '#') . '#i',
						(string) $value,
					) === 1;
				if ($match === $filter['negated']) {
					continue 2;
				}
			}
			foreach ($plan['filters'] as $filter) {
				unset($values[$filter['alias']]);
			}
			foreach ($plan['computed'] as $alias => $fn) {
				if (array_key_exists($alias, $values) && $values[$alias] !== null) {
					$values[$alias] = $fn((string) $values[$alias]);
				}
			}
			$kept[] = is_array($row) ? $values : (object) $values;
		}
		if ($plan['distinct']) {
			$seen = [];
			$kept = array_values(
				array_filter($kept, static function ($row) use (&$seen): bool {
					$key = serialize(is_array($row) ? $row : get_object_vars($row));
					if (isset($seen[$key])) {
						return false;
					}
					$seen[$key] = true;
					return true;
				}),
			);
		}
		if ($plan['limit'] !== null || $plan['offset'] > 0) {
			$kept = array_slice($kept, $plan['offset'], $plan['limit']);
		}
		if ($plan['count'] !== null) {
			return [[$plan['count'] => count($kept)]];
		}
		return $kept;
	}

	/**
	 * Plans the filter for the REGEXP conditions of a plain SELECT.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param string $masked
	 *   The statement with literals blanked.
	 * @param array $params
	 *   The bound parameters.
	 * @param array $plan
	 *   Receives the filters and the clause rewrites.
	 * @param string $whole
	 *   The original statement, for refusal messages.
	 *
	 * @return string
	 *   The statement with each REGEXP condition replaced by 1.
	 *
	 * @throws InvalidQueryException
	 *   For a shape a post-filter cannot answer exactly.
	 */
	private static function planRegexp(
		string $sql,
		string $masked,
		array $params,
		array &$plan,
		string $whole,
	): string {
		if (preg_match('/\b(?:UNION|INTERSECT|EXCEPT)\b/i', $masked) === 1) {
			self::refuse('REGEXP in a compound SELECT', $whole);
		}
		$where = self::clause($masked, 'WHERE', ['GROUP\s+BY', 'HAVING', 'ORDER\s+BY', 'LIMIT']);
		if (
			$where === null ||
			preg_match(
				'/\b(?:GROUP\s+BY|HAVING)\b|\b(?:COUNT|SUM|MIN|MAX|AVG|GROUP_CONCAT|TOTAL)\s*\(/i',
				$masked,
			) === 1
		) {
			self::refuse('REGEXP outside a plain WHERE, or beside an aggregate', $whole);
		}

		$found = preg_match_all(
			// the operand is a column or a function call over columns, which is what the Views
			// Combine filter puts there (`CONCAT_WS(' ', a, b) REGEXP :p`)
			'/(?<col>[A-Za-z_][A-Za-z0-9_]*\s*(?<args>\((?:[^()]++|(?&args))*\))|' .
				self::COLUMN .
				')\s+(?<not>NOT\s+)?REGEXP\s+(?<pat>:[A-Za-z0-9_]+|\'[^\']*\')/i',
			$masked,
			$hits,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
		);
		if ($found === 0 || $found === false) {
			self::refuse('a REGEXP whose column or pattern could not be read', $whole);
		}
		$depth = self::depths($masked);
		$edits = [];
		foreach ($hits as $i => $hit) {
			$at = $hit[0][1];
			if ($at < $where[0] || $at >= $where[1]) {
				self::refuse('REGEXP outside the WHERE clause', $whole);
			}
			self::assertConjunct($masked, $depth, $at, $where, $whole);
			$operand = substr($sql, $hit['pat'][1], strlen($hit['pat'][0]));
			if ($operand[0] === ':') {
				$key = array_key_exists($operand, $params)
					? $operand
					: (array_key_exists(substr($operand, 1), $params)
						? substr($operand, 1)
						: null);
				if ($key === null || !is_scalar($params[$key])) {
					self::refuse("REGEXP pattern $operand is not bound to a string", $whole);
				}
				$pattern = (string) $params[$key];
				$plan['unbind'][] = (string) $key;
			} else {
				$pattern = str_replace("''", "'", substr($operand, 1, -1));
			}
			$alias = self::ALIAS . $i;
			$plan['filters'][] = [
				'alias' => $alias,
				'pattern' => $pattern,
				'negated' => trim($hit['not'][0] ?? '') !== '',
			];
			$edits[] = [
				$at,
				strlen($hit[0][0]),
				'1',
				substr($sql, $hit['col'][1], strlen($hit['col'][0])),
				$alias,
			];
		}

		// right to left, so earlier offsets stay valid
		foreach (array_reverse($edits) as [$at, $length, $replacement]) {
			$sql = substr_replace($sql, $replacement, $at, $length);
		}
		$masked = self::mask($sql);
		if (preg_match('/^\s*SELECT\s+DISTINCT\b/i', $masked) === 1) {
			$plan['distinct'] = true;
		}
		$from = self::topLevel($masked, 'FROM');
		if ($from === null) {
			self::refuse('REGEXP in a SELECT with no FROM', $whole);
		}
		$extra = '';
		foreach ($edits as [, , , $column, $alias]) {
			$extra .= ", $column AS \"$alias\"";
		}
		$sql = rtrim(substr($sql, 0, $from)) . $extra . ' ' . substr($sql, $from);

		$masked = self::mask($sql);
		if (
			preg_match(
				'/\sLIMIT\s+(\d+)(?:\s*(,|OFFSET)\s*(\d+))?\s*$/i',
				$masked,
				$l,
				PREG_OFFSET_CAPTURE,
			) === 1
		) {
			if (($l[2][0] ?? '') === ',') {
				// LIMIT offset, count
				$plan['offset'] = (int) $l[1][0];
				$plan['limit'] = (int) $l[3][0];
			} else {
				$plan['limit'] = (int) $l[1][0];
				$plan['offset'] = isset($l[3]) ? (int) $l[3][0] : 0;
			}
			$sql = rtrim(substr($sql, 0, $l[0][1]));
		} elseif (preg_match('/\b(?:LIMIT|OFFSET)\b/i', substr($masked, $where[0])) === 1) {
			self::refuse('REGEXP beside a LIMIT that is not a literal', $whole);
		}
		return $sql;
	}

	/**
	 * Plans the PHP evaluation of MD5() and SUBSTRING_INDEX() in the select list.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param array $params
	 *   The bound parameters.
	 * @param array $plan
	 *   Receives the computed columns.
	 *
	 * @return string
	 *   The statement with each call replaced by its column.
	 */
	private static function planFunctions(string $sql, array $params, array &$plan): string
	{
		$masked = self::mask($sql);
		$from = self::topLevel($masked, 'FROM') ?? strlen($sql);
		$list = substr($masked, 0, $from);
		$count = preg_match_all(
			'/\b(MD5|SUBSTRING_INDEX)\s*\(\s*(' .
				self::COLUMN .
				')\s*(?:,\s*(:[A-Za-z0-9_]+|\'[^\']*\')\s*,\s*(:[A-Za-z0-9_]+|-?\d+)\s*)?\)\s+AS\s+"?([A-Za-z0-9_]+)"?/i',
			$list,
			$hits,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
		);
		if (!$count) {
			return $sql;
		}
		$value = static function (string $operand) use ($params) {
			if ($operand !== '' && $operand[0] === ':') {
				return $params[$operand] ?? ($params[substr($operand, 1)] ?? null);
			}
			return $operand !== '' && $operand[0] === "'"
				? str_replace("''", "'", substr($operand, 1, -1))
				: $operand;
		};
		foreach (array_reverse($hits) as $hit) {
			$fn = strtoupper($hit[1][0]);
			$column = substr($sql, $hit[2][1], strlen($hit[2][0]));
			$alias = $hit[5][0];
			if ($fn === 'MD5') {
				if (($hit[3][0] ?? '') !== '') {
					continue;
				}
				$plan['computed'][$alias] = static fn(string $v): string => md5($v);
			} else {
				if (($hit[3][0] ?? '') === '') {
					continue;
				}
				$delimiter = (string) $value($hit[3][0]);
				$n = (int) $value($hit[4][0]);
				$plan['computed'][$alias] = static fn(string $v): string => self::substringIndex(
					$v,
					$delimiter,
					$n,
				);
			}
			$sql = substr_replace($sql, "$column AS \"$alias\"", $hit[0][1], strlen($hit[0][0]));
		}
		return $sql;
	}

	/**
	 * MySQL's SUBSTRING_INDEX(), both directions.
	 *
	 * Core's SQLite callback handles a positive count only; a negative one counts from the right,
	 * which is what MySQL does and what a query written against MySQL expects.
	 */
	public static function substringIndex(string $string, string $delimiter, int $count): string
	{
		if ($string === '' || $delimiter === '' || $count === 0) {
			return '';
		}
		$parts = explode($delimiter, $string);
		return $count > 0
			? implode($delimiter, array_slice($parts, 0, $count))
			: implode($delimiter, array_slice($parts, $count));
	}

	/**
	 * Why the REGEXP at `$at` cannot be filtered after the fact, if it cannot.
	 */
	private static function assertConjunct(
		string $masked,
		array $depth,
		int $at,
		array $where,
		string $whole,
	): void {
		$level = $depth[$at];
		for ($d = $level; $d >= 0; $d--) {
			// the span of the group enclosing $at at depth $d
			$open = $where[0];
			$close = $where[1];
			if ($d > 0) {
				for ($i = $at; $i >= $where[0]; $i--) {
					if ($masked[$i] === '(' && $depth[$i] === $d - 1) {
						$open = $i;
						break;
					}
				}
				$close = self::matching($masked, $open) ?? $where[1];
				$before = rtrim(substr($masked, $where[0], $open - $where[0]));
				if (preg_match('/\bNOT$/i', $before) === 1) {
					self::refuse('REGEXP inside NOT (...)', $whole);
				}
			}
			for ($i = $open; $i < $close; $i++) {
				if (
					$depth[$i] === $d &&
					preg_match('/\GOR\b/i', $masked, $_, 0, $i) === 1 &&
					($i === 0 || (!ctype_alnum($masked[$i - 1]) && $masked[$i - 1] !== '_'))
				) {
					self::refuse('REGEXP inside an OR', $whole);
				}
			}
		}
	}

	/**
	 * The byte span of a top-level clause, from its keyword to the next listed keyword or the end.
	 */
	private static function clause(string $masked, string $keyword, array $next): ?array
	{
		$at = self::topLevel($masked, $keyword);
		if ($at === null) {
			return null;
		}
		$end = strlen($masked);
		foreach ($next as $word) {
			$n = self::topLevel($masked, $word, $at);
			if ($n !== null && $n < $end) {
				$end = $n;
			}
		}
		return [$at, $end];
	}

	/**
	 * The offset of a keyword at parenthesis depth 0, or NULL.
	 */
	private static function topLevel(string $masked, string $keyword, int $from = 0): ?int
	{
		$depth = self::depths($masked);
		if (preg_match_all('/\b' . $keyword . '\b/i', $masked, $m, PREG_OFFSET_CAPTURE, $from)) {
			foreach ($m[0] as [, $offset]) {
				if ($depth[$offset] === 0) {
					return $offset;
				}
			}
		}
		return null;
	}

	/**
	 * Maps each offset to its parenthesis depth.
	 *
	 * @param string $masked
	 *   The statement with literals blanked.
	 *
	 * @return int[]
	 *   The depth at each offset.
	 */
	private static function depths(string $masked): array
	{
		$depth = [];
		$d = 0;
		$length = strlen($masked);
		for ($i = 0; $i < $length; $i++) {
			if ($masked[$i] === ')') {
				$d--;
			}
			$depth[$i] = $d;
			if ($masked[$i] === '(') {
				$d++;
			}
		}
		return $depth;
	}

	/**
	 * Finds the parenthesis that closes the one at an offset.
	 *
	 * @param string $masked
	 *   The statement with literals blanked.
	 * @param int $open
	 *   The offset of the opening parenthesis.
	 *
	 * @return int|null
	 *   The offset of the closing parenthesis, or null when unbalanced.
	 */
	private static function matching(string $masked, int $open): ?int
	{
		$d = 0;
		$length = strlen($masked);
		for ($i = $open; $i < $length; $i++) {
			if ($masked[$i] === '(') {
				$d++;
			} elseif ($masked[$i] === ')' && --$d === 0) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Single-quoted literals blanked to spaces, so a keyword inside one is never read as SQL.
	 */
	private static function mask(string $sql): string
	{
		return (string) preg_replace_callback(
			"/'(?:[^']|'')*'/",
			static fn(array $m): string => "'" . str_repeat(' ', strlen($m[0]) - 2) . "'",
			$sql,
		);
	}

	/**
	 * Refuses a statement whose REGEXP cannot be answered in PHP.
	 *
	 * @param string $why
	 *   What about the statement rules it out.
	 * @param string $sql
	 *   The statement.
	 *
	 * @throws InvalidQueryException
	 *   Always.
	 */
	private static function refuse(string $why, string $sql): never
	{
		throw new InvalidQueryException(
			sprintf(
				'%s cannot be evaluated here: ctx.storage.sql has no REGEXP, so it is filtered in PHP, which is exact only for a top-level AND condition in a plain SELECT. Statement: %s',
				$why,
				$sql,
			),
		);
	}
}

<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

/**
 * Predicts the rowid a buffered insert will be given, without running anything.
 *
 * SQLite assigns an ordinary rowid table `max(rowid) + 1`, so the answer is arithmetic on state
 * the driver already holds: the committed maximum, plus the inserts buffered since. This class
 * holds the second half -- what the buffer implies -- and `Connection` supplies the first.
 *
 * It refuses far more than it answers, and every refusal falls back to the replay that was
 * always there, so a case this cannot read is slow rather than wrong:
 *
 *   - anything but `INSERT INTO t (cols) VALUES (...)` with one tuple and no nested parentheses:
 *     `ON CONFLICT`, `OR REPLACE`, `INSERT ... SELECT` and `DEFAULT VALUES` can all write a row
 *     that is not a fresh append.
 *   - any other write to that table, except a `DELETE FROM t` with no `WHERE`, which empties it
 *     and is the shape the router rebuild opens with.
 *   - a statement whose target could not be named at all, which blocks every table at once.
 *
 * The AUTOINCREMENT case is refused in `Connection`, where the schema is readable: its next id
 * comes from `sqlite_sequence` rather than from the table, and those are the tables whose id a
 * caller actually uses.
 *
 * @see Connection::predictBufferedInsertId()
 */
final class RowidPlan
{
	/**
	 * Column names that name the rowid itself, so an insert supplying one is not an append.
	 */
	private const ROWID_ALIASES = ['rowid', 'oid', '_rowid_'];

	/**
	 * What each buffered insert implies, keyed by buffer index.
	 *
	 * Snapshotted per statement rather than kept as one running total, because the index asked
	 * about is not always the last thing buffered: `[INSERT t, DELETE t]` reports the insert as
	 * the last insert, and a running counter would have already been cleared by the delete.
	 *
	 * @var array<int, array{table: string, cleared: bool, offset: int, columns: string[], supplied: int|null, suppliedBefore: int}>
	 */
	private array $entries = [];

	/**
	 * The highest rowid an insert has SUPPLIED to each table, per table.
	 *
	 * Tracked because a supplied rowid is not an append and the offset counter cannot express it.
	 * `INSERT INTO t (id) VALUES (5)` into a table whose committed maximum is 100 leaves the
	 * maximum at 100, while `VALUES (400)` moves it to 400 -- so the next append is 101 in one case
	 * and 401 in the other, and counting both as `+1` predicts 102 either way. That was a silent
	 * id COLLISION rather than a missed prediction, and it is why this is read rather than refused.
	 *
	 * @var array<string, int>
	 */
	private array $suppliedMax = [];

	/**
	 * Tables emptied by an unconditional delete, whose next rowid is therefore 1.
	 *
	 * @var array<string, bool>
	 */
	private array $cleared = [];

	/**
	 * Rows appended to each table since its base, committed or cleared.
	 *
	 * @var array<string, int>
	 */
	private array $appended = [];

	/**
	 * Tables carrying a buffered write this cannot reason about.
	 *
	 * @var array<string, bool>
	 */
	private array $blocked = [];

	/**
	 * Whether a statement wrote something unnameable, which blocks every table.
	 */
	private bool $blockedAll = false;

	/**
	 * Folds one buffered statement in.
	 *
	 * @param int $index
	 *   The buffer index the statement was given.
	 * @param string $sql
	 *   The statement.
	 * @param string[] $tables
	 *   The tables it writes, from SqlAnalyzer::writtenTables().
	 * @param array $params
	 *   The statement's parameters, so an insert that names its own id can be read rather than
	 *   replayed for. Empty is legal: a literal id needs no parameter.
	 * @param string|null $integerPrimaryKey
	 *   The table's INTEGER PRIMARY KEY column, when it has one. Supplied by the caller because
	 *   only the connection can read a schema.
	 */
	public function record(
		int $index,
		string $sql,
		array $tables,
		array $params = [],
		?string $integerPrimaryKey = null,
	): void {
		if (in_array(SqlAnalyzer::ALL_TABLES, $tables, true)) {
			$this->blockedAll = true;
			return;
		}
		// DDL reports its table AND the schema pseudo-table; a column added or a table rebuilt
		// changes what an insert means, so the table stops being readable rather than being read
		// through the change
		if (in_array(SqlAnalyzer::SCHEMA_TABLE, $tables, true)) {
			foreach ($tables as $table) {
				$this->blocked[$table] = true;
			}
			return;
		}
		if (count($tables) !== 1) {
			// a write naming two data tables is a shape this does not model
			foreach ($tables as $table) {
				$this->blocked[$table] = true;
			}
			return;
		}

		$table = $tables[0];
		$parsed = self::plainInsert($sql, $table);
		if ($parsed !== null) {
			[$columns, $values] = $parsed;
			$suppliedBefore = $this->suppliedMax[$table] ?? 0;
			$suppliesOne = self::suppliesRowid($columns, $integerPrimaryKey);
			$supplied = $suppliesOne
				? self::suppliedRowid($columns, $values, $params, $integerPrimaryKey)
				: null;

			if ($supplied !== null) {
				// not an append: the maximum moves to the supplied value only when it is higher
				$this->suppliedMax[$table] = max($suppliedBefore, $supplied);
			} else {
				$this->appended[$table] = ($this->appended[$table] ?? 0) + 1;
			}
			// RECORDED BEFORE THE BLOCK, always. `isSingleRowInsert()` reads this map and is about
			// how many rows the statement wrote, which a later write cannot change; only the ID is
			// unknowable, and `entry()` is what refuses that
			$this->entries[$index] = [
				'table' => $table,
				'cleared' => $this->cleared[$table] ?? false,
				'offset' => $this->appended[$table] ?? 0,
				'columns' => $columns,
				'supplied' => $supplied,
				'suppliedBefore' => $suppliedBefore,
			];
			// an insert that NAMES its id and whose id could not be read leaves the table's maximum
			// unknown, so every LATER prediction for it would be a guess
			if ($suppliesOne && $supplied === null) {
				$this->blocked[$table] = true;
			}
			return;
		}

		if (self::isUnconditionalDelete($sql, $table)) {
			$this->cleared[$table] = true;
			$this->appended[$table] = 0;
			return;
		}

		$this->blocked[$table] = true;
	}

	/**
	 * Forgets everything, so the plan can be rebuilt from a truncated buffer.
	 */
	public function reset(): void
	{
		$this->entries = [];
		$this->cleared = [];
		$this->appended = [];
		$this->suppliedMax = [];
		$this->blocked = [];
		$this->blockedAll = false;
	}

	/**
	 * Returns what a buffered insert implies about its rowid.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return array{table: string, cleared: bool, offset: int, columns: string[], supplied: int|null, suppliedBefore: int}|null
	 *   The prediction inputs, or NULL when this statement or its table cannot be read.
	 */
	public function entry(int $index): ?array
	{
		$entry = $this->entries[$index] ?? null;
		if ($entry === null || $this->blockedAll) {
			return null;
		}
		if ($this->blocked[$entry['table']] ?? false) {
			return null;
		}
		return $entry;
	}

	/**
	 * Says WHY {@link entry} refused, so a replay can be attributed rather than counted.
	 *
	 * Three refusals share that one NULL and they have three different remedies: a statement shape
	 * this does not parse, a later write to the same table, and a write naming no table at all.
	 * Collapsing them is how "the remaining replays are supplied rowids" became a plausible guess
	 * that turned out to account for none of them.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return string
	 *   The reason, or an empty string when nothing was refused.
	 */
	public function refusalFor(int $index): string
	{
		$entry = $this->entries[$index] ?? null;
		if ($entry === null) {
			return 'not-a-plain-insert';
		}
		if ($this->blockedAll) {
			return 'unnameable-write-blocked-everything';
		}
		if ($this->blocked[$entry['table']] ?? false) {
			return 'table-written-again';
		}
		return '';
	}

	/**
	 * Returns whether a buffered statement is a plain single-row insert.
	 *
	 * That is enough to know it changed exactly one row, which needs no schema and no replay.
	 * Unlike {@link entry()} this stays true for a blocked table: a later write to the same
	 * table changes which rowid this insert got, never how many rows it wrote.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return bool
	 *   TRUE when the statement appends exactly one row.
	 */
	public function isSingleRowInsert(int $index): bool
	{
		return isset($this->entries[$index]);
	}

	/**
	 * Returns the columns AND the VALUES expressions of a plain single-row insert into one table.
	 *
	 * The two are returned together and positionally aligned, which is what lets an insert that
	 * names its own id be read: the id is in the tuple opposite the column that names it.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param string $table
	 *   The table SqlAnalyzer named, lower-cased.
	 *
	 * @return array{0: string[], 1: string[]}|null
	 *   The lower-cased column names and the VALUES expressions, or NULL when the statement is any
	 *   other shape or the two lists are not the same width.
	 */
	private static function plainInsert(string $sql, string $table): ?array
	{
		// no nested parentheses in either list, so a function call, a sub-select or a second
		// VALUES tuple all fail to match rather than being read as an append
		$matched = preg_match(
			'/^\s*INSERT\s+INTO\s+("?[A-Za-z_][A-Za-z0-9_$]*"?)\s*\(([^()]*)\)\s*VALUES\s*\(([^()]*)\)\s*;?\s*$/i',
			$sql,
			$parts,
		);
		if ($matched !== 1) {
			return null;
		}
		if (strtolower(trim($parts[1], '"')) !== $table) {
			return null;
		}

		$columns = [];
		foreach (explode(',', $parts[2]) as $column) {
			$name = strtolower(trim(trim($column), '"'));
			if ($name === '') {
				return null;
			}
			$columns[] = $name;
		}

		$values = array_map('trim', explode(',', $parts[3]));
		// a tuple of a different width than the column list is not a shape to reason about
		if (count($values) !== count($columns)) {
			return null;
		}

		return [$columns, $values];
	}

	/**
	 * Reads the rowid an insert supplies, from its own VALUES expression.
	 *
	 * The id is sitting in the statement that assigns it, and the driver was REPLAYING THE WHOLE
	 * BUFFER to learn it. Only two expressions are read -- a decimal literal, and a placeholder
	 * resolved against the statement's own parameters -- and everything else, `NULL` included,
	 * answers NULL so the caller falls back to what it did before.
	 *
	 * `NULL` matters and is not an oversight: `INSERT INTO t (id) VALUES (NULL)` makes SQLite
	 * assign the id after all, so it is an append wearing a supplied id's shape.
	 *
	 * @param string[] $columns
	 *   Lower-cased column names.
	 * @param string[] $values
	 *   The VALUES expressions, positionally aligned with $columns.
	 * @param array $params
	 *   The statement's parameters, named or positional.
	 * @param string|null $integerPrimaryKey
	 *   The table's INTEGER PRIMARY KEY column, when it has one.
	 *
	 * @return int|null
	 *   The supplied rowid, or NULL when the statement does not supply one this can read.
	 */
	public static function suppliedRowid(
		array $columns,
		array $values,
		array $params,
		?string $integerPrimaryKey,
	): ?int {
		$at = null;
		foreach ($columns as $i => $column) {
			if (
				in_array($column, self::ROWID_ALIASES, true) ||
				($integerPrimaryKey !== null && $column === $integerPrimaryKey)
			) {
				$at = $i;
				break;
			}
		}
		if ($at === null) {
			return null;
		}

		$expression = $values[$at] ?? '';
		if (preg_match('/^-?\d+$/', $expression) === 1) {
			return (int) $expression;
		}

		if (preg_match('/^:[A-Za-z_][A-Za-z0-9_]*$/', $expression) === 1) {
			$value = $params[$expression] ?? ($params[substr($expression, 1)] ?? null);
			return self::asRowid($value);
		}

		if ($expression === '?') {
			// positional binding counts only the placeholders BEFORE this one, because a literal
			// in the tuple consumes no parameter
			$ordinal = 0;
			for ($i = 0; $i < $at; $i++) {
				if (($values[$i] ?? '') === '?') {
					$ordinal++;
				}
			}
			$list = array_values($params);
			return self::asRowid($list[$ordinal] ?? null);
		}

		return null;
	}

	/**
	 * Narrows a bound parameter to a rowid, or refuses it.
	 *
	 * A numeric STRING is accepted because that is how the host hands integers back across the
	 * JSON bridge; a float, a bool and NULL are refused rather than cast, since each would produce
	 * an id SQLite did not assign.
	 *
	 * @param mixed $value
	 *   The bound value.
	 *
	 * @return int|null
	 *   The rowid, or NULL when the value is not one.
	 */
	private static function asRowid(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Returns whether a statement empties a table outright.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param string $table
	 *   The table SqlAnalyzer named, lower-cased.
	 *
	 * @return bool
	 *   TRUE for `DELETE FROM t` with no WHERE, which is what a router rebuild opens with.
	 */
	private static function isUnconditionalDelete(string $sql, string $table): bool
	{
		$matched = preg_match(
			'/^\s*DELETE\s+FROM\s+("?[A-Za-z_][A-Za-z0-9_$]*"?)\s*;?\s*$/i',
			$sql,
			$parts,
		);
		return $matched === 1 && strtolower(trim($parts[1], '"')) === $table;
	}

	/**
	 * Returns whether a column list names the rowid itself.
	 *
	 * @param string[] $columns
	 *   Lower-cased column names.
	 * @param string|null $integerPrimaryKey
	 *   The table's INTEGER PRIMARY KEY column, when it has one.
	 *
	 * @return bool
	 *   TRUE when the insert chooses its own rowid, so SQLite does not append.
	 */
	public static function suppliesRowid(array $columns, ?string $integerPrimaryKey): bool
	{
		foreach ($columns as $column) {
			if (in_array($column, self::ROWID_ALIASES, true)) {
				return true;
			}
			if ($integerPrimaryKey !== null && $column === $integerPrimaryKey) {
				return true;
			}
		}
		return false;
	}
}

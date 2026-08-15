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
 * IT REFUSES FAR MORE THAN IT ANSWERS, and every refusal falls back to the replay that was
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
	 * @var array<int, array{table: string, cleared: bool, offset: int, columns: string[]}>
	 */
	private array $entries = [];

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
	 */
	public function record(int $index, string $sql, array $tables): void
	{
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
		$columns = self::plainInsertColumns($sql, $table);
		if ($columns !== null) {
			$this->appended[$table] = ($this->appended[$table] ?? 0) + 1;
			$this->entries[$index] = [
				'table' => $table,
				'cleared' => $this->cleared[$table] ?? false,
				'offset' => $this->appended[$table],
				'columns' => $columns,
			];
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
		$this->blocked = [];
		$this->blockedAll = false;
	}

	/**
	 * Returns what a buffered insert implies about its rowid.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return array{table: string, cleared: bool, offset: int, columns: string[]}|null
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
	 * Returns the columns of a plain single-row insert into one table.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param string $table
	 *   The table SqlAnalyzer named, lower-cased.
	 *
	 * @return string[]|null
	 *   The lower-cased column names, or NULL when the statement is any other shape.
	 */
	private static function plainInsertColumns(string $sql, string $table): ?array
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

		return $columns;
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

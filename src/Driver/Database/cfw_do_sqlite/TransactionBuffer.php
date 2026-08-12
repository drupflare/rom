<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

/**
 * The ordered list of writes withheld while a Drupal transaction is open.
 *
 * Rolling back is discarding the buffer, committing is replaying it, and a
 * savepoint is an index into it. That is the whole of the transaction mapping:
 * ctx.storage.sql has no BEGIN and no SAVEPOINT, so the only place the ordering
 * can live is here.
 *
 * The dirty-table set is recomputed on truncation rather than tracked
 * incrementally, because a rollback to a savepoint has to un-dirty whatever
 * only the discarded statements had written.
 */
final class TransactionBuffer
{
	/**
	 * Buffered statements, in issue order.
	 *
	 * @var array<int, array{sql: string, params: array, tables: string[]}>
	 */
	private array $statements = [];

	/**
	 * Savepoint name to the buffer length when it was created.
	 *
	 * @var array<string, int>
	 */
	private array $savepoints = [];

	/**
	 * Lower-cased names of tables the buffered statements write.
	 *
	 * @var array<string, true>
	 */
	private array $dirtyTables = [];

	/**
	 * Whether some buffered statement's target could not be determined.
	 */
	private bool $allDirty = false;

	/**
	 * Index of the last buffered statement that inserted rows, if any.
	 */
	private ?int $lastInsertIndex = null;

	/**
	 * Appends a statement.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param array $params
	 *   Its parameters, ready for the host.
	 * @param string[] $tables
	 *   The tables it writes, from SqlAnalyzer::writtenTables().
	 *
	 * @return int
	 *   The index of the buffered statement.
	 */
	public function add(string $sql, array $params, array $tables): int
	{
		$index = count($this->statements);
		$this->statements[$index] = [
			'sql' => $sql,
			'params' => $params,
			'tables' => $tables,
		];
		$this->markDirty($tables);

		if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $sql) === 1) {
			$this->lastInsertIndex = $index;
		}

		return $index;
	}

	/**
	 * Returns the number of buffered statements.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->statements);
	}

	/**
	 * Returns whether nothing is buffered.
	 *
	 * @return bool
	 *   TRUE when the buffer holds no statements.
	 */
	public function isEmpty(): bool
	{
		return $this->statements === [];
	}

	/**
	 * Returns every buffered statement, ready to hand to the host.
	 *
	 * @return array<int, array{sql: string, params: array}>
	 *   The statements, in issue order.
	 */
	public function statements(): array
	{
		return $this->slice(count($this->statements) - 1);
	}

	/**
	 * Returns the buffered statements up to and including one index.
	 *
	 * @param int $index
	 *   The last index to include.
	 *
	 * @return array<int, array{sql: string, params: array}>
	 *   The statements, in issue order.
	 */
	public function statementsUpTo(int $index): array
	{
		return $this->slice($index);
	}

	/**
	 * Returns whether any of the given tables has a buffered write.
	 *
	 * @param string[] $tables
	 *   Lower-cased table names, from SqlAnalyzer::readTables().
	 *
	 * @return bool
	 *   TRUE when a read of those tables could observe buffered state.
	 */
	public function touches(array $tables): bool
	{
		if ($this->allDirty) {
			return true;
		}
		foreach ($tables as $table) {
			if ($table === SqlAnalyzer::ALL_TABLES || isset($this->dirtyTables[$table])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns whether the buffer contains an insert.
	 *
	 * @return bool
	 *   TRUE when a buffered statement would assign a rowid.
	 */
	public function hasInsert(): bool
	{
		return $this->lastInsertIndex !== null;
	}

	/**
	 * Returns the index of the last buffered insert.
	 *
	 * @return int|null
	 *   The index, or NULL when nothing buffered inserts.
	 */
	public function lastInsertIndex(): ?int
	{
		return $this->lastInsertIndex;
	}

	/**
	 * Records a savepoint at the current buffer position.
	 *
	 * @param string $name
	 *   The savepoint name Drupal generated.
	 */
	public function mark(string $name): void
	{
		$this->savepoints[$name] = count($this->statements);
	}

	/**
	 * Discards everything written since a savepoint.
	 *
	 * @param string $name
	 *   The savepoint name.
	 *
	 * @throws UncommittedStateException
	 *   If the savepoint is unknown, which would mean the stack is corrupt.
	 */
	public function rollbackTo(string $name): void
	{
		if (!isset($this->savepoints[$name])) {
			throw new UncommittedStateException(
				sprintf(
					'Unknown savepoint %s; the transaction buffer and the Drupal transaction stack disagree.',
					$name,
				),
			);
		}
		$this->statements = array_slice($this->statements, 0, $this->savepoints[$name]);
		$this->release($name);
		$this->recomputeDirtyTables();
	}

	/**
	 * Forgets a savepoint, keeping the statements written since it was created.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function release(string $name): void
	{
		$released = $this->savepoints[$name] ?? null;
		unset($this->savepoints[$name]);
		if ($released === null) {
			return;
		}
		// SQLite releases every savepoint created after the released one
		foreach ($this->savepoints as $savepoint => $position) {
			if ($position > $released) {
				unset($this->savepoints[$savepoint]);
			}
		}
	}

	/**
	 * Returns the buffered statements up to an index, without bookkeeping.
	 *
	 * @param int $index
	 *   The last index to include; a negative value yields an empty list.
	 *
	 * @return array<int, array{sql: string, params: array}>
	 *   The statements.
	 */
	private function slice(int $index): array
	{
		$out = [];
		for ($i = 0; $i <= $index; $i++) {
			if (!isset($this->statements[$i])) {
				break;
			}
			$out[] = [
				'sql' => $this->statements[$i]['sql'],
				'params' => $this->statements[$i]['params'],
			];
		}
		return $out;
	}

	/**
	 * Adds tables to the dirty set.
	 *
	 * @param string[] $tables
	 *   The table names a statement writes.
	 */
	private function markDirty(array $tables): void
	{
		foreach ($tables as $table) {
			if ($table === SqlAnalyzer::ALL_TABLES) {
				$this->allDirty = true;
				continue;
			}
			$this->dirtyTables[$table] = true;
		}
	}

	/**
	 * Rebuilds the dirty set and the insert marker from what is still buffered.
	 */
	private function recomputeDirtyTables(): void
	{
		$this->dirtyTables = [];
		$this->allDirty = false;
		$this->lastInsertIndex = null;

		foreach ($this->statements as $index => $statement) {
			$this->markDirty($statement['tables']);
			if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $statement['sql']) === 1) {
				$this->lastInsertIndex = $index;
			}
		}
	}
}

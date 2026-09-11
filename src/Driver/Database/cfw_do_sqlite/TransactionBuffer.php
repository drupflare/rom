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
	 * A failed entry keeps its slot so that every index already handed out stays
	 * valid; it is simply never replayed again. See discardFailed().
	 *
	 * @var array<int, array{sql: string, params: array, tables: string[], failed: bool, minted: string|null}>
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
	 * Per-index results learned from a replay, keyed by buffer index.
	 *
	 * A replay always starts from the same committed state and runs statements 0..k in
	 * order, so the result of statement i is a function of the buffer's first i+1 entries
	 * and of nothing else. Those entries never change while they are buffered - a buffer
	 * only grows, except at a savepoint rollback, which truncates and is handled in
	 * rollbackTo(). So an answer learned once stays correct until the transaction ends.
	 *
	 * @var array<int, array{lastInsertId: string, changes: int}>
	 */
	private array $resolved = [];

	/**
	 * Tables each buffered statement READS, keyed by buffer index.
	 *
	 * Populated on demand: only a replay that narrows itself asks, and a transaction that
	 * never needs an unpredictable id never pays the parse.
	 *
	 * @var array<int, string[]>
	 */
	private array $readTables = [];

	/**
	 * What the buffered inserts imply about the rowids they will be given.
	 *
	 * Maintained as statements arrive rather than derived on demand, so answering costs nothing
	 * at the point an insert id is asked for -- which is every insert Drupal makes.
	 */
	private RowidPlan $rowidPlan;

	public function __construct()
	{
		$this->rowidPlan = new RowidPlan();
	}

	/**
	 * Returns what a buffered insert implies about its own rowid.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return array{table: string, cleared: bool, offset: int, columns: string[], supplied: int|null, suppliedBefore: int}|null
	 *   The prediction inputs, or NULL when the statement or its table cannot be read.
	 */
	public function rowidPrediction(int $index): ?array
	{
		if (($this->statements[$index]['failed'] ?? true) === true) {
			return null;
		}
		return $this->rowidPlan->entry($index);
	}

	/**
	 * Says why {@link rowidPrediction} returned NULL, for the connection's refusal tally.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return string
	 *   The reason, or an empty string when nothing was refused.
	 */
	public function rowidRefusal(int $index): string
	{
		if (($this->statements[$index]['failed'] ?? true) === true) {
			return 'statement-failed';
		}
		return $this->rowidPlan->refusalFor($index);
	}

	/**
	 * Returns the highest rowid a buffered insert has named for one table.
	 *
	 * @param string $table
	 *   The table SqlAnalyzer named, lower-cased.
	 *
	 * @return int
	 *   The value, or 0 when nothing buffered has named its own id in that table.
	 */
	public function suppliedMax(string $table): int
	{
		return $this->rowidPlan->suppliedMaxFor($table);
	}

	/**
	 * Returns whether a buffered statement appends exactly one row.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return bool
	 *   TRUE when its row count is known to be 1 without replaying anything.
	 */
	public function isSingleRowInsert(int $index): bool
	{
		if (($this->statements[$index]['failed'] ?? true) === true) {
			return false;
		}
		return $this->rowidPlan->isSingleRowInsert($index);
	}

	/**
	 * Appends a statement.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param array $params
	 *   Its parameters, ready for the host.
	 * @param string[] $tables
	 *   The tables it writes, from SqlAnalyzer::writtenTables().
	 * @param string|null $integerPrimaryKey
	 *   The written table's INTEGER PRIMARY KEY column, when it has one. Only the connection can
	 *   read a schema, so it arrives here rather than being looked up.
	 * @param string|null $mintedTable
	 *   The table whose rowid this connection minted from its own residue class and spliced into
	 *   the statement, or NULL when the id is SQLite's to assign. It rides out on the payload so the
	 *   host can tell an id a lane originated safely from one it merely wrote.
	 *
	 * @return int
	 *   The index of the buffered statement.
	 */
	public function add(
		string $sql,
		array $params,
		array $tables,
		?string $integerPrimaryKey = null,
		?string $mintedTable = null,
	): int {
		$index = count($this->statements);
		$this->statements[$index] = [
			'sql' => $sql,
			'params' => $params,
			'tables' => $tables,
			'failed' => false,
			// kept so a rebuild after a failed statement re-reads a supplied id the same way
			'integerPrimaryKey' => $integerPrimaryKey,
			'minted' => $mintedTable,
		];
		$this->markDirty($tables);
		$this->rowidPlan->record($index, $sql, $tables, $params, $integerPrimaryKey);

		if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $sql) === 1) {
			$this->lastInsertIndex = $index;
		}

		return $index;
	}

	/**
	 * Returns the number of statements that would be replayed.
	 *
	 * @return int
	 *   The count, excluding statements the engine rejected.
	 */
	public function count(): int
	{
		return count($this->liveIndexesUpTo($this->lastIndex()));
	}

	/**
	 * Returns whether nothing would be replayed.
	 *
	 * @return bool
	 *   TRUE when the buffer holds no statement the engine has accepted.
	 */
	public function isEmpty(): bool
	{
		return $this->count() === 0;
	}

	/**
	 * Drops a statement the engine rejected, keeping the transaction usable.
	 *
	 * A buffered write is reported as successful before anything has run, so its
	 * rejection is only discovered by a later replay. On a real connection that
	 * statement would have failed where it was issued and left no trace, and the
	 * transaction would have carried on - Drupal's own "write, catch, create the
	 * table, continue" idiom depends on exactly that. Keeping the rejected
	 * statement instead poisons every later replay and the commit with it.
	 *
	 * The slot is kept rather than removed so that a buffer index already handed
	 * to a Statement still names the same statement.
	 *
	 * @param int $index
	 *   The buffer index of the rejected statement.
	 *
	 * @throws UncommittedStateException
	 *   If no statement is buffered at that index.
	 */
	public function discardFailed(int $index): void
	{
		if (!isset($this->statements[$index])) {
			throw new UncommittedStateException(
				sprintf(
					'Cannot discard buffered statement %d: the buffer holds %d slot(s).',
					$index,
					count($this->statements),
				),
			);
		}

		$this->statements[$index]['failed'] = true;
		// a statement that never ran changed no rows and was given no rowid, so this is
		// the exact answer rather than a placeholder, and it keeps a later rowCount()
		// from paying for a replay to rediscover it
		$this->resolved[$index] = ['lastInsertId' => '0', 'changes' => 0];
		$this->recomputeDirtyTables();
	}

	/**
	 * Returns the SQL of one buffered statement.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return string
	 *   The statement, or an empty string when the index is unknown.
	 */
	public function sqlAt(int $index): string
	{
		return $this->statements[$index]['sql'] ?? '';
	}

	/**
	 * Returns every buffered statement, ready to hand to the host.
	 *
	 * @return array<int, array{sql: string, params: array}>
	 *   The statements, in issue order.
	 */
	public function statements(): array
	{
		return $this->slice($this->liveIndexesUpTo($this->lastIndex()));
	}

	/**
	 * Returns the highest buffer index in use.
	 *
	 * @return int
	 *   The index, or -1 when nothing has been buffered. Counts discarded slots,
	 *   because they still occupy an index.
	 */
	public function lastIndex(): int
	{
		return count($this->statements) - 1;
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
		return $this->slice($this->liveIndexesUpTo($index));
	}

	/**
	 * Returns a chosen subset of buffered statements, in issue order.
	 *
	 * @param array<int, int> $indexes
	 *   Buffer indexes, ascending, as dependencyIndexesUpTo() returns them.
	 *
	 * @return array<int, array{sql: string, params: array}>
	 *   The statements, in issue order.
	 */
	public function statementsAt(array $indexes): array
	{
		return $this->slice($indexes);
	}

	/**
	 * Returns the buffer indexes a replay needs in order to answer for one statement.
	 *
	 * A speculative replay exists to learn one of two things about one statement: the rowid an
	 * insert would be given, or how many rows a write would change. Replaying the statements that
	 * cannot move either answer is the whole of this project's write cost - a rolled-back row is
	 * billed identically to a committed one, so the ninth pass of a node save pays for every
	 * statement of the first eight a second time.
	 *
	 * Only a write to a table the target touches can change the target's answer, and three
	 * properties of this deployment are what make that true rather than merely plausible:
	 * SQLite takes a rowid from the target table alone, Drupal's SQLite schema declares no
	 * triggers, and foreign keys are off - so no write to another table reaches this one.
	 *
	 * The set is a transitive closure rather than a single intersection, because a write may read
	 * another table (`INSERT INTO a SELECT ... FROM b`) and so inherit that table's writers. It
	 * grows until nothing new joins it.
	 *
	 * NULL means the replay cannot be narrowed and the caller must send everything. That is the
	 * answer whenever a table cannot be named (SqlAnalyzer::ALL_TABLES), and also whenever the
	 * subset would be the whole list anyway, so a caller never pays a second pass to learn it
	 * saved nothing.
	 *
	 * @param int $index
	 *   The buffer index the replay is being opened for.
	 *
	 * @return array<int, int>|null
	 *   The buffer indexes to send, ascending and including $index, or NULL to send everything.
	 */
	public function dependencyIndexesUpTo(int $index): ?array
	{
		if (
			$index < 0 ||
			!isset($this->statements[$index]) ||
			$this->statements[$index]['failed']
		) {
			return null;
		}

		$live = $this->liveIndexesUpTo($index);
		if (count($live) < 2) {
			return null;
		}

		$wanted = [];
		$seed = array_merge($this->statements[$index]['tables'], $this->readsOf($index));
		foreach ($seed as $table) {
			if ($table === SqlAnalyzer::ALL_TABLES) {
				return null;
			}
			$wanted[$table] = true;
		}
		if ($wanted === []) {
			return null;
		}

		$chosen = [$index => true];
		do {
			$grew = false;
			foreach ($live as $i) {
				if (isset($chosen[$i])) {
					continue;
				}
				$writes = $this->statements[$i]['tables'];
				$relevant = false;
				foreach ($writes as $table) {
					if ($table === SqlAnalyzer::ALL_TABLES) {
						// an untargetable write could have touched the table being asked about
						return null;
					}
					$relevant = $relevant || isset($wanted[$table]);
				}
				if (!$relevant) {
					continue;
				}
				$chosen[$i] = true;
				$grew = true;
				foreach ($this->readsOf($i) as $table) {
					if (!isset($wanted[$table])) {
						$wanted[$table] = true;
					}
				}
			}
		} while ($grew);

		if (count($chosen) === count($live)) {
			return null;
		}

		ksort($chosen);

		return array_keys($chosen);
	}

	/**
	 * Returns the tables one buffered statement reads, memoized.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return string[]
	 *   Lower-cased table names.
	 */
	private function readsOf(int $index): array
	{
		return $this->readTables[$index] ??= SqlAnalyzer::readTables(
			$this->statements[$index]['sql'],
		);
	}

	/**
	 * Records what a replay learned about each buffered statement.
	 *
	 * Every speculative replay hands back one result per statement it ran, whether it was
	 * opened to resolve an insert id, to resolve a row count, or to answer a dirty read.
	 * The read case is the valuable one: it replays the WHOLE buffer, so it answers every
	 * outstanding id and row count as a side effect of work already being paid for.
	 *
	 * @param array<int, array{lastInsertId?: string, changes?: int}> $results
	 *   Host results, keyed by their POSITION in the replay rather than by buffer
	 *   index; a discarded statement is not sent, so the two only agree while
	 *   nothing has been discarded. Anything past the end is ignored rather than
	 *   trusted.
	 * @param int|null $upTo
	 *   The buffer index the replay ran to, or NULL when it ran the whole buffer.
	 * @param array<int, int>|null $indexes
	 *   The buffer indexes the replay actually sent, when it sent a narrowed subset. Every
	 *   statement in a narrowed replay saw every write that could affect it -- that is what
	 *   dependencyIndexesUpTo() computes -- so their results are as authoritative as a full
	 *   pass's and are kept on the same terms.
	 */
	public function rememberResults(array $results, ?int $upTo = null, ?array $indexes = null): void
	{
		$indexes ??= $this->liveIndexesUpTo($upTo ?? $this->lastIndex());
		$indexes = array_values($indexes);
		foreach ($results as $position => $result) {
			if (!isset($indexes[$position])) {
				continue;
			}
			$this->resolved[$indexes[$position]] = [
				'lastInsertId' => (string) ($result['lastInsertId'] ?? '0'),
				'changes' => (int) ($result['changes'] ?? 0),
			];
		}
	}

	/**
	 * Returns the buffer indexes a replay up to one index would send, in order.
	 *
	 * The list is positional: element 0 is the first statement the host would run,
	 * which is what makes it the map from a host result back to a buffer index.
	 *
	 * @param int $index
	 *   The last buffer index to include; a negative value yields an empty list.
	 *
	 * @return array<int, int>
	 *   The buffer indexes, keyed by their position in the replay.
	 */
	public function liveIndexesUpTo(int $index): array
	{
		$out = [];
		for ($i = 0; $i <= $index; $i++) {
			if (!isset($this->statements[$i])) {
				break;
			}
			if ($this->statements[$i]['failed']) {
				continue;
			}
			$out[] = $i;
		}
		return $out;
	}

	/**
	 * Returns what a previous replay learned about one buffered statement.
	 *
	 * @param int $index
	 *   The buffer index.
	 *
	 * @return array{lastInsertId: string, changes: int}|null
	 *   The result, or NULL when no replay has covered that statement yet.
	 */
	public function resolvedResult(int $index): ?array
	{
		return $this->resolved[$index] ?? null;
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

		foreach (array_keys($this->resolved) as $index) {
			if (!isset($this->statements[$index])) {
				unset($this->resolved[$index]);
			}
		}

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
	 * Returns the named buffered statements, without bookkeeping.
	 *
	 * @param array<int, int> $indexes
	 *   Buffer indexes, ascending. A discarded or unknown one is dropped.
	 *
	 * @return array<int, array{sql: string, params: array, minted?: string}>
	 *   The statements.
	 */
	private function slice(array $indexes): array
	{
		$out = [];
		foreach ($indexes as $i) {
			if (!isset($this->statements[$i]) || $this->statements[$i]['failed']) {
				continue;
			}
			$entry = [
				'sql' => $this->statements[$i]['sql'],
				'params' => $this->statements[$i]['params'],
			];
			// omitted rather than sent as null, so an ordinary write's payload is the shape it
			// always was and only a minted id costs a field
			if (($this->statements[$i]['minted'] ?? null) !== null) {
				$entry['minted'] = $this->statements[$i]['minted'];
			}
			$out[] = $entry;
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
	 * Rebuilds the dirty set, the insert marker and the rowid plan from what is still buffered.
	 *
	 * The rowid plan is rebuilt rather than adjusted: a savepoint rollback removes statements from
	 * the middle of what a later insert counted, so its offset is only correct if it is counted
	 * again from the start.
	 */
	private function recomputeDirtyTables(): void
	{
		$this->dirtyTables = [];
		$this->allDirty = false;
		$this->lastInsertIndex = null;
		$this->rowidPlan->reset();

		foreach ($this->statements as $index => $statement) {
			if ($statement['failed']) {
				continue;
			}
			$this->markDirty($statement['tables']);
			$this->rowidPlan->record(
				$index,
				$statement['sql'],
				$statement['tables'],
				$statement['params'],
				$statement['integerPrimaryKey'] ?? null,
			);
			if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $statement['sql']) === 1) {
				$this->lastInsertIndex = $index;
			}
		}
	}
}

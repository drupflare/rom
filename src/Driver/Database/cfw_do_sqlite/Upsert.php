<?php

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\sqlite\Driver\Database\sqlite\Upsert as SqliteUpsert;
use Exception;

/**
 * Upsert that respects the host's 100-bound-parameter ceiling.
 *
 * WHY THIS EXISTS. Durable Object SQLite refuses a statement carrying more than 100
 * bound parameters: 100 succeeds, 101 fails with `too many SQL variables:
 * SQLITE_ERROR`. Bisected directly through `/sql` against a real `ctx.storage.sql`,
 * not inferred from PDO or from documentation.
 *
 * Core's sqlite `Upsert::__toString()` builds ONE multi-row statement --
 * `INSERT INTO t (...) VALUES (...), (...), ... ON CONFLICT ...` -- so the generic
 * `execute()` flattens every row into a single parameter list of rows x fields.
 *
 * That is not a corner case, it is the cache write path. `DatabaseBackend::setMultiple()`
 * upserts in chunks of `MAX_ITEMS_PER_CACHE_SET = 100` rows over 7 columns, which is
 * **700 placeholders** -- so core's own chunking is already 7x over the ceiling. A cold
 * `cache_discovery` write (82 entries, 574 placeholders) made every render 500. Any cache
 * flush on a live site reproduces it, so it is not specific to a freshly packed database.
 *
 * The fix is to re-batch by PLACEHOLDER count rather than by row count, because the limit
 * counts parameters and a row's width is not fixed. Core's row-based chunk cannot express
 * that; this can.
 *
 * Multiple batches are wrapped in one transaction, matching what core's sqlite `Insert`
 * does for multi-row inserts: the driver withholds writes and replays the whole buffer
 * inside one `transactionSync()`, so a half-applied cache set is not observable.
 *
 * @see Connection::upsert()
 */
class Upsert extends SqliteUpsert
{
	/**
	 * Bound parameters the host accepts in one statement.
	 *
	 * MEASURED, not assumed: 100 binds, 101 throws. An earlier note in TECHNICAL_REPORT.md claimed
	 * the limit did not exist on Durable Object SQLite; that claim is refuted.
	 */
	const MAX_PLACEHOLDERS = 100;

	/**
	 * {@inheritdoc}
	 *
	 * @return int|null
	 *   Rows affected, or NULL when there was nothing to write. Core's own
	 *   Query\Upsert::execute() returns NULL from the same branch while documenting
	 *   `@return int`; keeping the NULL keeps this a drop-in for it.
	 */
	public function execute()
	{
		if (!$this->preExecute()) {
			return null;
		}

		$all = $this->insertValues;
		if (!$all) {
			$this->insertValues = [];
			return 0;
		}

		// width of one row, defaults included: they are placed first and still bind
		$fields = count($this->defaultFields) + count($this->insertFields);
		$per_batch = max(1, intdiv(self::MAX_PLACEHOLDERS, max(1, $fields)));
		$batches = array_chunk($all, $per_batch);

		// one batch needs no transaction, and skipping it keeps the common single-row cache
		// write on the same path it was always on
		$transaction = null;
		if (count($batches) > 1) {
			try {
				$transaction = $this->connection->startTransaction();
			} catch (Exception $e) {
				// handleExecutionException() wants a statement, which there is not one of yet
				$this->insertValues = [];
				throw new DatabaseExceptionWrapper($e->getMessage(), 0, $e);
			}
		}

		$affected_rows = 0;
		foreach ($batches as $batch) {
			// __toString() reads insertValues to size its placeholder fragment, so the batch
			// has to be installed before the query is stringified
			$this->insertValues = $batch;
			$max_placeholder = 0;
			$values = [];
			foreach ($batch as $insert_values) {
				foreach ($insert_values as $value) {
					$values[':db_insert_placeholder_' . $max_placeholder++] = $value;
				}
			}

			$stmt = $this->connection->prepareStatement((string) $this, $this->queryOptions, true);
			try {
				$stmt->execute($values, $this->queryOptions);
				$affected_rows += $stmt->rowCount();
			} catch (Exception $e) {
				if ($transaction) {
					$transaction->rollBack();
				}
				$this->insertValues = [];
				$this->connection
					->exceptionHandler()
					->handleExecutionException($e, $stmt, $values, $this->queryOptions);
			}
		}

		if ($transaction) {
			$transaction->commitOrRelease();
		}

		// re-initialised so the query object can be reused, as core does
		$this->insertValues = [];

		return $affected_rows;
	}
}

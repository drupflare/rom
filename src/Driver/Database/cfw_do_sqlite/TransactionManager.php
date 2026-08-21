<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\Transaction\ClientConnectionTransactionState;
use Drupal\Core\Database\Transaction\TransactionManagerBase;
use Exception;

/**
 * Maps Drupal's begin/commit transaction API onto a write buffer.
 *
 * Every other Drupal driver implements the four
 * client methods below by sending SQL or calling PDO::beginTransaction().
 * Neither is available: ctx.storage.sql refuses transaction control outright,
 * measured, with "To execute a transaction, please use the
 * state.storage.transaction() or state.storage.transactionSync() APIs instead
 * of ...". transactionSync() does work, but it is callback-scoped, and Drupal's
 * API is begin-commit. The two do not compose, so nothing here emits SQL.
 *
 * Beginning a transaction opens a buffer on the
 * connection; every write issued while it is open is withheld rather than run.
 * Committing replays the buffer inside one host-side transactionSync(). Rolling
 * back discards it, which is why a rollback here cannot fail. A savepoint is an
 * index into the buffer, so rolling back to one truncates it - the reason none
 * of the three savepoint methods sends a SAVEPOINT statement.
 *
 * The consequence a caller can see is that reads inside a transaction have to
 * be resolved against the buffer rather than against the database.
 *
 * @see Connection::runStatement()
 * @see TransactionBuffer
 */
class TransactionManager extends TransactionManagerBase
{
	/**
	 * {@inheritdoc}
	 */
	protected function beginClientTransaction(): bool
	{
		$this->driverConnection()->beginBufferedTransaction();
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function addClientSavepoint(string $name): bool
	{
		$this->driverConnection()->markBufferSavepoint($name);
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function rollbackClientSavepoint(string $name): bool
	{
		$this->driverConnection()->rollbackBufferToSavepoint($name);
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function releaseClientSavepoint(string $name): bool
	{
		$this->driverConnection()->releaseBufferSavepoint($name);
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function rollbackClientTransaction(): bool
	{
		// discarding the buffer cannot fail, so unlike every other driver a
		// rollback here has no failure mode to report
		$this->driverConnection()->discardBufferedTransaction();
		$this->setConnectionTransactionState(ClientConnectionTransactionState::RolledBack);
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function commitClientTransaction(): bool
	{
		try {
			$this->driverConnection()->commitBufferedTransaction();
		} catch (Exception $e) {
			// rethrow rather than returning FALSE
			$this->setConnectionTransactionState(ClientConnectionTransactionState::CommitFailed);
			throw $e;
		}

		$this->setConnectionTransactionState(ClientConnectionTransactionState::Committed);
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function voidClientTransaction(): void
	{
		// Drupal calls this when it believes the database has already committed the
		// open transaction behind its back. Nothing has been sent here, so the
		// faithful equivalent is to commit the buffer; dropping it would silently
		// lose writes Drupal considers durable. supportsTransactionalDDL() is TRUE,
		// so this should not be reached.
		if ($this->driverConnection()->isBuffering()) {
			$this->driverConnection()->commitBufferedTransaction();
		}
		parent::voidClientTransaction();
	}

	/**
	 * Returns the connection, typed to this driver.
	 *
	 * @return Connection
	 *   The connection that owns the buffer.
	 *
	 * @throws HostBridgeException
	 *   If the manager was built against a foreign connection.
	 */
	private function driverConnection(): Connection
	{
		if (!($this->connection instanceof Connection)) {
			throw new HostBridgeException(
				'The cfw_do_sqlite transaction manager was constructed with a foreign connection object.',
			);
		}
		return $this->connection;
	}
}

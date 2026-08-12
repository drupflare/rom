<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\ExceptionHandler as CoreExceptionHandler;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\StatementInterface;
use Exception;
use Throwable;

/**
 * Maps host SQL errors onto Drupal's exception vocabulary.
 *
 * Core's handler classifies by SQLSTATE, reading it off a \PDOException: any
 * 23xxx becomes an IntegrityConstraintViolationException. There is no PDO here
 * and ctx.storage.sql returns a message, not a SQLSTATE, so the same
 * classification has to come from the message text.
 *
 * This matters beyond tidiness. Drupal treats a unique-key collision as control
 * flow in several places - Merge, key/value writes, user registration - and
 * catches IntegrityConstraintViolationException specifically. A driver that
 * reported those as a generic wrapper would turn a recoverable collision into a
 * fatal error.
 */
class ExceptionHandler extends CoreExceptionHandler
{
	/**
	 * Message fragments SQLite uses for constraint failures.
	 *
	 * SQLite's wording is stable across versions; the constraint kind is named
	 * before the words "constraint failed".
	 */
	private const CONSTRAINT_FRAGMENTS = [
		'constraint failed',
		'are not unique',
		'may not be NULL',
		'PRIMARY KEY must be unique',
	];

	/**
	 * {@inheritdoc}
	 */
	public function handleStatementException(
		Exception $exception,
		string $sql,
		array $options = [],
	): void {
		if ($exception instanceof SqlErrorException) {
			throw $this->classify($exception, $exception->getMessage());
		}
		parent::handleStatementException($exception, $sql, $options);
	}

	/**
	 * {@inheritdoc}
	 */
	public function handleExecutionException(
		Exception $exception,
		StatementInterface $statement,
		array $arguments = [],
		array $options = [],
	): void {
		if ($exception instanceof SqlErrorException) {
			$message =
				$exception->getSqlError() .
				': ' .
				$statement->getQueryString() .
				'; ' .
				print_r($arguments, true);
			throw $this->classify($exception, $message);
		}
		parent::handleExecutionException($exception, $statement, $arguments, $options);
	}

	/**
	 * Chooses the Drupal exception for a SQLite error message.
	 *
	 * @param SqlErrorException $exception
	 *   The error from the host.
	 * @param string $message
	 *   The message to carry, including whatever debug context the caller added.
	 *
	 * @return Throwable
	 *   The exception to throw. Constraint failures become an integrity
	 *   violation; everything else stays as the driver's own error so the SQLite
	 *   message survives intact.
	 */
	private function classify(SqlErrorException $exception, string $message): Throwable
	{
		foreach (self::CONSTRAINT_FRAGMENTS as $fragment) {
			if (stripos($exception->getSqlError(), $fragment) !== false) {
				// 23000 is the SQLSTATE core's handler would have matched on
				return new IntegrityConstraintViolationException($message, 23000, $exception);
			}
		}

		return $exception;
	}
}

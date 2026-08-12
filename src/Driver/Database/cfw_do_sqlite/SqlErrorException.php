<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\DatabaseException;
use RuntimeException;

/**
 * Carries a SQLite error message back from the host.
 *
 * There is no PDO here, so there is no SQLSTATE and no \PDOException for
 * Drupal's ExceptionHandler to match on. The raw engine message is kept
 * separately from the formatted message so that the driver's ExceptionHandler
 * can classify it (for example a UNIQUE violation) without re-parsing the
 * query text that has been appended for debugging.
 */
final class SqlErrorException extends RuntimeException implements DatabaseException
{
	/**
	 * Constructs a SqlErrorException.
	 *
	 * @param string $sqlError
	 *   The message reported by SQLite, verbatim.
	 * @param string $sql
	 *   The statement that produced it.
	 */
	public function __construct(private readonly string $sqlError, private readonly string $sql)
	{
		// same shape core's ExceptionHandler builds for a \PDOException
		parent::__construct($sqlError . ': ' . $sql . '; ');
	}

	/**
	 * Returns the verbatim SQLite error message.
	 *
	 * @return string
	 *   The engine message, without the query appended.
	 */
	public function getSqlError(): string
	{
		return $this->sqlError;
	}

	/**
	 * Returns the statement that failed.
	 *
	 * @return string
	 *   The SQL string.
	 */
	public function getSql(): string
	{
		return $this->sql;
	}
}

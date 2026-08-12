<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\RowCountException;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Database\Statement\PrefetchedResult;
use Drupal\Core\Database\Statement\StatementBase;
use Drupal\Core\Database\StatementInterface;
use Stringable;
use Exception;

/**
 * A statement whose result set arrives whole, from the host.
 *
 * StatementBase is the right base here rather than StatementPrefetchIterator:
 * the prefetching one is generic over any client but still drives a
 * \PDOStatement through PdoTrait, and there is no PDO anywhere in this driver.
 * What is kept from it is the shape - the host hands back every row in one
 * reply, so the result is a PrefetchedResult and there is never a live cursor to
 * keep open. That removes the problem the core sqlite Statement was written to
 * work around (SQLite refusing writes to a table with an open SELECT) rather
 * than working around it.
 *
 * Two behaviours are worth knowing before reading a value out of this class.
 *
 * COLUMN VALUES ARE STRINGS OR NULL. The core sqlite driver sets PDO's
 * ATTR_STRINGIFY_FETCHES, so Drupal is written against string columns; this
 * driver matches that. It also has to: the wasm build is 32-bit, so a SQLite
 * INTEGER above 2^31 crosses the boundary as a ['__phpint' => '<digits>']
 * envelope, and the client resolves that envelope to its digits. A node ID, a
 * timestamp or a file size therefore reaches Drupal as an exact decimal string
 * instead of a wrapped negative int or a leaked array.
 *
 * ROW COUNTS CAN BE DEFERRED. While a Drupal transaction is open the connection
 * buffers writes instead of running them, so the number of rows a buffered
 * UPDATE or DELETE changed is not known when execute() returns. rowCount() then
 * resolves it on demand, which costs a speculative replay of the buffer.
 *
 * @see Connection::runStatement()
 * @see CfwSqlClient
 */
final class Statement extends StatementBase implements StatementInterface
{
	/**
	 * The connection, typed to this driver so buffering is reachable.
	 */
	private readonly Connection $driverConnection;

	/**
	 * Buffer index of this statement, when it was withheld rather than run.
	 */
	private ?int $bufferIndex = null;

	/**
	 * A resolved row count for a buffered write, cached after the first request.
	 */
	private ?int $resolvedRowCount = null;

	/**
	 * Constructs a Statement.
	 *
	 * @param CfwSqlClient $client
	 *   The client connection, in the slot a \PDO would occupy.
	 * @param Connection $connection
	 *   The Drupal connection.
	 * @param string $queryString
	 *   The statement, with prefixes and identifier quotes already resolved.
	 * @param bool $rowCountEnabled
	 *   (optional) Whether rowCount() may be called. Defaults to FALSE.
	 */
	public function __construct(
		CfwSqlClient $client,
		Connection $connection,
		string $queryString,
		bool $rowCountEnabled = false,
	) {
		parent::__construct($connection, $client, $queryString, $rowCountEnabled);
		$this->driverConnection = $connection;
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute($args = [], $options = [])
	{
		if (isset($options['fetch']) && is_int($options['fetch'])) {
			@trigger_error(
				"Passing the 'fetch' key as an integer to \$options in execute() is deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use a case of \Drupal\Core\Database\Statement\FetchAs enum instead. See https://www.drupal.org/node/3488338",
				E_USER_DEPRECATED,
			);
		}

		$args = is_array($args) ? $args : [];
		$startEvent = $this->dispatchStatementExecutionStartEvent($args);

		try {
			$outcome = $this->driverConnection->runStatement(
				$this->queryString,
				self::normalizeArgs($args),
			);
		} catch (Exception $e) {
			$this->dispatchStatementExecutionFailureEvent($startEvent, $e);
			throw $e;
		}

		$this->bufferIndex = $outcome['bufferIndex'];
		$this->resolvedRowCount = null;
		$this->result = new PrefetchedResult(
			$this->fetchMode,
			$this->fetchOptions,
			$outcome['rows'],
			$this->rowCountEnabled ? $outcome['rowCount'] : null,
		);
		$this->markResultsetIterable(true);

		if (isset($options['fetch'])) {
			if (is_string($options['fetch'])) {
				// note: fields are set on the object before its constructor runs
				$this->setFetchMode(FetchAs::ClassObject, $options['fetch']);
			} else {
				$this->setFetchMode($options['fetch']);
			}
		}

		$this->dispatchStatementExecutionEndEvent($startEvent);

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function rowCount()
	{
		if (!$this->rowCountEnabled) {
			throw new RowCountException();
		}

		if ($this->bufferIndex !== null) {
			$this->resolvedRowCount ??= $this->driverConnection->resolveBufferedRowCount(
				$this->bufferIndex,
			);
			return $this->resolvedRowCount;
		}

		return $this->result?->rowCount();
	}

	/**
	 * Renders Drupal's arguments as parameters the host can bind.
	 *
	 * @param array $args
	 *   The arguments as Drupal built them.
	 *
	 * @return array
	 *   The parameters, keys untouched so the host's named-to-positional
	 *   rewriting still applies.
	 *
	 * @throws HostBridgeException
	 *   If an argument is of a type the bridge cannot carry.
	 */
	private static function normalizeArgs(array $args): array
	{
		$out = [];
		foreach ($args as $placeholder => $value) {
			if (is_bool($value)) {
				// SQLite has no boolean type; PDO binds one as an int and so do we
				$out[$placeholder] = $value ? 1 : 0;
				continue;
			}
			if ($value === null || is_scalar($value)) {
				$out[$placeholder] = $value;
				continue;
			}
			if (is_array($value)) {
				// a codec envelope for an integer wider than 32 bits, read out of an
				// earlier query and being written back
				if (isset($value['__phpint'])) {
					$out[$placeholder] = $value;
					continue;
				}
				throw new HostBridgeException(
					sprintf(
						'Argument %s is an array; Connection::expandArguments() should have flattened it before it reached the driver.',
						(string) $placeholder,
					),
				);
			}
			if ($value instanceof Stringable) {
				$out[$placeholder] = (string) $value;
				continue;
			}

			throw new HostBridgeException(
				sprintf(
					'Argument %s is a %s, which cannot be bound.',
					(string) $placeholder,
					get_debug_type($value),
				),
			);
		}

		return $out;
	}
}

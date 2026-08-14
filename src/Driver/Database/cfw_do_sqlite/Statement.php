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
	 * Where an oversized read can be split, or NULL when it cannot be.
	 *
	 * WHY THIS EXISTS. The host caps a statement at 100 bound parameters. `Upsert` already chunks
	 * WRITES against that ceiling, and the read path had no equivalent because nothing had ever
	 * generated an oversized read -- until a module install made Drupal's config storage load 169
	 * names in one `IN()`, which failed with "too many SQL variables" and left the install
	 * half-applied: `core.extension` written, the rest of the install not.
	 *
	 * REFUSES FAR MORE THAN IT ACCEPTS, and every refusal is a case where concatenating batches
	 * would silently return the wrong answer rather than an error:
	 *
	 *   - not a SELECT: splitting a write changes what it writes.
	 *   - `ORDER BY`, `LIMIT` or `OFFSET`: global ordering and cut-off cannot be reconstructed from
	 *     independently ordered batches, and the result would look plausible.
	 *   - `GROUP BY`, `DISTINCT` or an aggregate: the answer is a fold over the whole set, so a
	 *     per-batch fold is a different number.
	 *   - more than one placeholder `IN()` list, or an `IN()` holding anything but placeholders:
	 *     which list to split is then a guess.
	 *   - a statement whose NON-list parameters alone already exceed the ceiling: no batch size
	 *     helps, so failing loudly is correct.
	 *
	 * @return array{prefix: string, names: list<string>, suffix: string}|null
	 *   The split point, or NULL when the statement must not be split.
	 */
	private static function splitPointFor(string $query, array $args): ?array
	{
		if (count($args) <= Connection::MAX_BOUND_PARAMETERS) {
			return null;
		}
		if (preg_match('/^\s*SELECT\b/i', $query) !== 1) {
			return null;
		}
		if (
			preg_match(
				'/\b(?:ORDER\s+BY|LIMIT|OFFSET|GROUP\s+BY|DISTINCT|COUNT\s*\(|SUM\s*\(|MIN\s*\(|MAX\s*\(|AVG\s*\()/i',
				$query,
			) === 1
		) {
			return null;
		}

		// an IN list of nothing but named placeholders, which is the only shape core generates for
		// a multi-name load and the only one whose split is exact
		$pattern = '/\bIN\s*\(\s*(:[A-Za-z0-9_]+(?:\s*,\s*:[A-Za-z0-9_]+)+)\s*\)/i';
		if (preg_match_all($pattern, $query, $matches, PREG_OFFSET_CAPTURE) !== 1) {
			return null;
		}

		$listText = $matches[1][0][0];
		$listAt = (int) $matches[1][0][1];
		$names = array_map(
			static fn(string $n): string => ltrim(trim($n), ':'),
			explode(',', $listText),
		);
		foreach ($names as $name) {
			if (!array_key_exists($name, $args) && !array_key_exists(':' . $name, $args)) {
				// a placeholder with no argument means this is not the list being bound
				return null;
			}
		}
		if (count($args) - count($names) >= Connection::MAX_BOUND_PARAMETERS) {
			return null;
		}

		return [
			'prefix' => substr($query, 0, $listAt),
			'names' => $names,
			'suffix' => substr($query, $listAt + strlen($listText)),
		];
	}

	/**
	 * Runs an oversized read as batches and merges the rows.
	 *
	 * Exact rather than approximate: the batches partition the IN list, so their union is the
	 * original result set. `rowCount` is summed; a SELECT writes nothing, so there is no buffered
	 * write to reconcile and `bufferIndex` stays whatever the last batch reported.
	 */
	private function runSplitInList(array $args): array
	{
		$point = self::splitPointFor($this->queryString, $args);
		if ($point === null) {
			// unreachable through execute(), which checks first; kept so a future caller cannot
			// silently get an unsplit oversized statement
			return $this->driverConnection->runStatement($this->queryString, $args);
		}

		$keyOf = static fn(string $name): string => array_key_exists($name, $args)
			? $name
			: ':' . $name;
		$fixed = $args;
		foreach ($point['names'] as $name) {
			unset($fixed[$keyOf($name)]);
		}

		$budget = Connection::MAX_BOUND_PARAMETERS - count($fixed);
		$batches = array_chunk($point['names'], max(1, $budget));

		$rows = [];
		$rowCount = 0;
		$bufferIndex = null;
		foreach ($batches as $batch) {
			$list = implode(', ', array_map(static fn(string $n): string => ':' . $n, $batch));
			$batchArgs = $fixed;
			foreach ($batch as $name) {
				$batchArgs[$keyOf($name)] = $args[$keyOf($name)];
			}
			$outcome = $this->driverConnection->runStatement(
				$point['prefix'] . $list . $point['suffix'],
				$batchArgs,
			);
			foreach ($outcome['rows'] as $row) {
				$rows[] = $row;
			}
			$rowCount += (int) ($outcome['rowCount'] ?? 0);
			$bufferIndex = $outcome['bufferIndex'] ?? $bufferIndex;
		}

		return ['rows' => $rows, 'rowCount' => $rowCount, 'bufferIndex' => $bufferIndex];
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
			$normalized = self::normalizeArgs($args);
			$outcome =
				self::splitPointFor($this->queryString, $normalized) !== null
					? $this->runSplitInList($normalized)
					: $this->driverConnection->runStatement($this->queryString, $normalized);
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

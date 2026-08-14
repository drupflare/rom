<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

/**
 * The client connection: a synchronous bridge to ctx.storage.sql.
 *
 * This class stands where \PDO stands in every other Drupal driver. It is not a
 * connection in the network sense - PHP is executing inside the Durable Object
 * that owns the database, so a query is a function call across the wasm
 * boundary rather than a round trip to a server. Measured cost of that call is
 * 0.0125 ms, against 0.0070 ms for the in-wasm PDO it replaces, so there is no
 * cache or batching layer here on purpose: it would buy 0.0055 ms per query and
 * cost correctness.
 *
 * INTEGERS. The wasm build is 32-bit (PHP_INT_SIZE 4) and a SQLite INTEGER is
 * 64-bit, so every value crossing the boundary goes through the shared codec.
 * Anything wider than 32 bits arrives as ['__phpint' => '<digits>'] rather than
 * as a bare string, because a bare string cannot be told apart from a genuine
 * string value. This class resolves those markers to their digits before Drupal
 * ever sees them, which is also what PDO's ATTR_STRINGIFY_FETCHES does for the
 * core sqlite driver: every column value reaches Drupal as a string or NULL.
 * Parameters travel the other way through pw_encode(), so a node ID read out of
 * one query can be written back by the next without losing its type.
 *
 * @see Connection
 */
final class CfwSqlClient
{
	/**
	 * Module key under which the host installs the single-statement entry point.
	 */
	public const EXEC_BRIDGE = 'cfwSqlExec';

	/**
	 * Module key under which the host installs the transaction entry point.
	 *
	 * Optional. Without it the driver cannot replay a buffered transaction
	 * atomically and cannot evaluate a read against the buffer.
	 *
	 * @see CfwSqlClient::runTransaction()
	 */
	public const TRANSACTION_BRIDGE = 'cfwSqlTxn';

	/**
	 * The host's single-statement function, as surfaced by vrzno.
	 */
	private mixed $execFunction;

	/**
	 * The host's transaction function, or NULL when the host has none.
	 */
	private mixed $transactionFunction;

	/**
	 * The rowid of the last committed insert, as a decimal string.
	 */
	private string $lastInsertId = '0';

	/**
	 * How many statements have crossed the bridge on this connection.
	 */
	private int $statementCount = 0;

	/**
	 * How many host transactions have been opened on this connection.
	 *
	 * Counts every runTransaction() call, committing or not, because each one is a
	 * BEGIN on the host side and each one is billed as such.
	 */
	private int $transactionCount = 0;

	/**
	 * How many of those transactions were speculative, so rolled back rather than committed.
	 */
	private int $speculativeCount = 0;

	/**
	 * How many statements were re-sent inside speculative replays.
	 *
	 * The one figure that makes the O(W*R) term visible. statementCount() counts a
	 * replay as ONE statement, because one host call crossed the bridge; this counts
	 * what the host actually executed inside it, which is what grows with the buffer.
	 */
	private int $replayedStatementCount = 0;

	/**
	 * Constructs a CfwSqlClient.
	 *
	 * @param mixed $execFunction
	 *   (optional) The host's single-statement function. Resolved from the PHP
	 *   Module when omitted; injectable so the class can be driven from a test.
	 * @param mixed $transactionFunction
	 *   (optional) The host's transaction function.
	 *
	 * @throws HostBridgeException
	 *   If the bridge or the codec helpers are absent.
	 */
	public function __construct(mixed $execFunction = null, mixed $transactionFunction = null)
	{
		$this->execFunction = $execFunction ?? self::fromModule(self::EXEC_BRIDGE);
		$this->transactionFunction =
			$transactionFunction ?? self::fromModule(self::TRANSACTION_BRIDGE);

		if (!self::isInvokable($this->execFunction)) {
			throw new HostBridgeException(
				sprintf(
					"The host has not installed '%s'. This driver only works with PHP running inside the Durable Object that owns ctx.storage.sql; the host installs the bridge in SiteDurableObject::installBridge().",
					self::EXEC_BRIDGE,
				),
			);
		}
		if (!function_exists('pw_encode') || !function_exists('pw_decode')) {
			throw new HostBridgeException(
				'The pw_encode()/pw_decode() codec helpers are not defined. Without them a 64-bit SQLite integer silently wraps in 32-bit PHP, so the driver refuses to run rather than corrupt IDs and timestamps.',
			);
		}
	}

	/**
	 * Runs one statement.
	 *
	 * @param string $sql
	 *   The statement, with named or positional placeholders. Named parameters
	 *   are rewritten to positional by the host, which owns that rewriter.
	 * @param array $params
	 *   An associative array keyed by ':name', or a list for '?' placeholders.
	 *
	 * @return array{rows: array, changes: int, rowsRead: int, rowsWritten: int, lastInsertId: string}
	 *   The normalized result.
	 *
	 * @throws SqlErrorException
	 *   If SQLite rejected the statement.
	 * @throws HostBridgeException
	 *   If the host reply was not usable.
	 */
	public function exec(string $sql, array $params = []): array
	{
		$reply = $this->call(
			$this->execFunction,
			[
				'sql' => $sql,
				'params' => $params,
			],
			$sql,
		);

		$result = $this->normalizeResult($reply, $sql);
		$this->lastInsertId = $result['lastInsertId'];

		return $result;
	}

	/**
	 * Replays statements inside one host-side transaction.
	 *
	 * The host wraps the whole list in ctx.storage.transactionSync(), which is
	 * the only transaction primitive ctx.storage.sql offers: issuing BEGIN as SQL
	 * is refused outright by the runtime. Passing $commit as FALSE runs the
	 * statements, captures the optional trailing read, and then rolls the whole
	 * thing back - that is how a read can observe buffered writes without
	 * committing them.
	 *
	 * @param array<int, array{sql: string, params: array}> $statements
	 *   The statements to replay, in order.
	 * @param bool $commit
	 *   TRUE to keep the effects, FALSE to discard them after running.
	 * @param array{sql: string, params: array}|null $read
	 *   (optional) A read to evaluate after the replay, inside the same
	 *   transaction. Only meaningful when $commit is FALSE.
	 *
	 * @return array{results: array<int, array>, readResult: array|null}
	 *   Per-statement results in the same order, plus the trailing read.
	 *
	 * @throws HostBridgeException
	 *   If the host has no transaction entry point.
	 * @throws SqlErrorException
	 *   If SQLite rejected one of the statements. The host will have rolled the
	 *   whole transaction back.
	 */
	public function runTransaction(array $statements, bool $commit, ?array $read = null): array
	{
		if (!$this->supportsTransactions()) {
			throw new HostBridgeException(
				sprintf(
					"The host has not installed '%s', so a buffered Drupal transaction cannot be replayed atomically and a read cannot be evaluated against the buffer.",
					self::TRANSACTION_BRIDGE,
				),
			);
		}

		// counted BEFORE the call, so a host that throws still shows the attempt. A counter that
		// only records successes cannot show a retry loop, which is the shape these exist to expose
		$this->transactionCount++;
		if (!$commit) {
			$this->speculativeCount++;
			$this->replayedStatementCount += count($statements);
		}

		$reply = $this->call(
			$this->transactionFunction,
			[
				'statements' => array_values($statements),
				'commit' => $commit,
				'read' => $read,
			],
			$read['sql'] ?? 'transaction replay',
		);

		$results = [];
		$raw = is_array($reply['results'] ?? null) ? $reply['results'] : [];
		foreach ($raw as $index => $statementResult) {
			$results[$index] = $this->normalizeResult(
				is_array($statementResult) ? $statementResult : [],
				$statements[$index]['sql'] ?? '',
			);
		}

		$readResult = null;
		if (is_array($reply['readResult'] ?? null)) {
			$readResult = $this->normalizeResult($reply['readResult'], $read['sql'] ?? '');
		}

		if ($commit) {
			$last = end($results);
			if (is_array($last)) {
				$this->lastInsertId = $last['lastInsertId'];
			}
		}

		return ['results' => $results, 'readResult' => $readResult];
	}

	/**
	 * Returns whether the host can run a transaction.
	 *
	 * @return bool
	 *   TRUE when the transaction entry point is installed.
	 */
	public function supportsTransactions(): bool
	{
		return self::isInvokable($this->transactionFunction);
	}

	/**
	 * Returns the rowid of the last committed insert.
	 *
	 * @return string
	 *   A decimal string, '0' when nothing has been inserted.
	 */
	public function lastInsertId(): string
	{
		return $this->lastInsertId;
	}

	/**
	 * Returns how many statements have crossed the bridge.
	 *
	 * @return int
	 *   The count, including replays.
	 */
	public function statementCount(): int
	{
		return $this->statementCount;
	}

	/**
	 * Returns how many host transactions have been opened.
	 *
	 * @return int
	 *   The count, committing and speculative together.
	 */
	public function transactionCount(): int
	{
		return $this->transactionCount;
	}

	/**
	 * Returns how many transactions were speculative rather than committed.
	 *
	 * @return int
	 *   The count; always at most transactionCount().
	 */
	public function speculativeCount(): int
	{
		return $this->speculativeCount;
	}

	/**
	 * Returns how many statements were re-sent inside speculative replays.
	 *
	 * This is the O(W*R) term, made observable. A dirty read re-sends the whole buffer, so
	 * this rises quadratically in a write-then-read transaction while statementCount() rises
	 * linearly -- which is why the two are separate numbers rather than one.
	 *
	 * @return int
	 *   The count.
	 */
	public function replayedStatementCount(): int
	{
		return $this->replayedStatementCount;
	}

	/**
	 * Calls a host function with a JSON payload and returns the decoded reply.
	 *
	 * @param mixed $function
	 *   The host function.
	 * @param array $payload
	 *   The request, before encoding.
	 * @param string $context
	 *   SQL to attribute an error to.
	 *
	 * @return array
	 *   The decoded, codec-resolved reply.
	 *
	 * @throws SqlErrorException
	 * @throws HostBridgeException
	 */
	private function call(mixed $function, array $payload, string $context): array
	{
		$this->statementCount++;

		$request = json_encode(pw_encode($payload));
		if (!is_string($request)) {
			throw new HostBridgeException(
				'Could not encode the request for the host: ' . json_last_error_msg(),
			);
		}

		// held in a local because $this->execFunction(...) would resolve as a
		// method call rather than an invocation of the property's value
		$invoke = $function;
		$reply = $invoke($request);

		if (!is_string($reply)) {
			throw new HostBridgeException(
				sprintf(
					'The host bridge returned %s where a JSON string was expected.',
					get_debug_type($reply),
				),
			);
		}

		$decoded = json_decode($reply, true);
		if (!is_array($decoded)) {
			throw new HostBridgeException(
				'The host bridge returned a reply that is not a JSON object: ' .
					substr($reply, 0, 200),
			);
		}

		$decoded = pw_decode($decoded);
		if (!is_array($decoded)) {
			throw new HostBridgeException(
				'The codec resolved the host reply to a non-array value.',
			);
		}

		if (($decoded['ok'] ?? false) !== true) {
			$error = $decoded['error'] ?? 'the host reported a failure without a message';
			throw new SqlErrorException(
				is_string($error) ? $error : 'unreadable host error',
				$context,
			);
		}

		return $decoded;
	}

	/**
	 * Brings a host result into one shape, with every column value a string.
	 *
	 * @param array $raw
	 *   A decoded host result.
	 * @param string $sql
	 *   The statement it belongs to, for error messages.
	 *
	 * @return array{rows: array, changes: int, rowsRead: int, rowsWritten: int, lastInsertId: string}
	 *   The normalized result.
	 *
	 * @throws HostBridgeException
	 *   If a row or a column value is a shape Drupal cannot be handed.
	 */
	private function normalizeResult(array $raw, string $sql): array
	{
		$rows = [];
		$rawRows = is_array($raw['rows'] ?? null) ? $raw['rows'] : [];
		foreach ($rawRows as $row) {
			if (!is_array($row)) {
				throw new HostBridgeException(
					sprintf(
						'The host returned a %s where a result row was expected, for: %s',
						get_debug_type($row),
						$sql,
					),
				);
			}
			$normalized = [];
			foreach ($row as $column => $value) {
				$normalized[$column] = self::stringifyValue($value, $sql);
			}
			$rows[] = $normalized;
		}

		return [
			'rows' => $rows,
			'changes' => self::toInt($raw['changes'] ?? 0),
			'rowsRead' => self::toInt($raw['rowsRead'] ?? 0),
			'rowsWritten' => self::toInt($raw['rowsWritten'] ?? 0),
			'lastInsertId' => self::stringifyValue($raw['lastInsertRowid'] ?? 0, $sql) ?? '0',
		];
	}

	/**
	 * Renders one column value the way ATTR_STRINGIFY_FETCHES would.
	 *
	 * @param mixed $value
	 *   The codec-resolved value.
	 * @param string $sql
	 *   The statement it came from, for error messages.
	 *
	 * @return string|null
	 *   The value as a string, or NULL for SQL NULL.
	 *
	 * @throws HostBridgeException
	 *   If the value is a shape Drupal cannot be handed.
	 */
	private static function stringifyValue(mixed $value, string $sql): ?string
	{
		if ($value === null) {
			return null;
		}
		if (is_string($value)) {
			return $value;
		}
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}
		if (is_array($value)) {
			// an integer wider than 32 bits; resolving it to its digits here is what
			// keeps an envelope array out of entity code
			if (isset($value['__phpint'])) {
				return (string) $value['__phpint'];
			}
			throw new HostBridgeException(
				sprintf(
					'The host returned an unresolved codec envelope [%s] as a column value, for: %s',
					implode(', ', array_keys($value)),
					$sql,
				),
			);
		}

		throw new HostBridgeException(
			sprintf(
				'The host returned a %s as a column value, for: %s',
				get_debug_type($value),
				$sql,
			),
		);
	}

	/**
	 * Coerces a host counter to an int.
	 *
	 * @param mixed $value
	 *   The decoded counter.
	 *
	 * @return int
	 *   The counter.
	 */
	private static function toInt(mixed $value): int
	{
		if (is_int($value)) {
			return $value;
		}
		if (is_array($value)) {
			return isset($value['__phpint']) ? (int) $value['__phpint'] : 0;
		}
		return is_scalar($value) ? (int) $value : 0;
	}

	/**
	 * Reads a host helper off the PHP Module.
	 *
	 * @param string $name
	 *   The Module key.
	 *
	 * @return mixed
	 *   The helper, or NULL when it or vrzno is absent.
	 */
	private static function fromModule(string $name): mixed
	{
		// vrzno_env($name) resolves to Module[$name]
		return function_exists('vrzno_env') ? vrzno_env($name) : null;
	}

	/**
	 * Returns whether a value can be called.
	 *
	 * @param mixed $value
	 *   The candidate.
	 *
	 * @return bool
	 *   TRUE when it is worth attempting to invoke.
	 */
	private static function isInvokable(mixed $value): bool
	{
		// a vrzno-surfaced JS function is an object that is_callable() may not
		// recognise, so an object is accepted and allowed to fail at the call
		return is_object($value) || is_callable($value);
	}
}

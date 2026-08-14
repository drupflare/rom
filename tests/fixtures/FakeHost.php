<?php

/**
 * @file
 * PHP half of the codec, copied verbatim from src/codec.js PHP_CODEC.
 */

if (!function_exists('pw_decode')) {
	/**
	 * Decodes a codec envelope coming back from the JavaScript host.
	 *
	 * @param mixed $v
	 *   The decoded JSON value, which may contain envelopes.
	 * @param int $depth
	 *   Recursion guard; cyclic structures are not supported.
	 *
	 * @return mixed
	 *   The value with every envelope replaced by what it stood for.
	 */
	function pw_decode($v, $depth = 0)
	{
		if ($depth > 32) {
			return $v;
		}
		if (!is_array($v)) {
			return $v;
		}
		if (isset($v['__t']) && is_string($v['__t'])) {
			switch ($v['__t']) {
				case 'u':
					return null;

				case 'i':
					$s = (string) $v['v'];
					$n = (int) $s;
					return (string) $n === $s ? $n : ['__phpint' => $s];

				case 'n':
					if ($v['v'] === 'NaN') {
						return NAN;
					}
					if ($v['v'] === 'Infinity') {
						return INF;
					}
					if ($v['v'] === '-Infinity') {
						return -INF;
					}
					return (float) $v['v'];

				case 'd':
					return ['__phpdate' => (string) $v['v']];

				case 'b':
					return base64_decode((string) $v['v']);

				default:
					return $v;
			}
		}
		$out = [];
		foreach ($v as $k => $vv) {
			$out[$k] = pw_decode($vv, $depth + 1);
		}
		return $out;
	}

	/**
	 * Encodes a PHP value into the envelope form the JavaScript host expects.
	 *
	 * @param mixed $v
	 *   Any value being sent across the bridge.
	 * @param int $depth
	 *   Recursion guard; cyclic structures are not supported.
	 *
	 * @return mixed
	 *   The value, with anything JSON cannot carry wrapped in an envelope.
	 */
	function pw_encode($v, $depth = 0)
	{
		if ($depth > 32) {
			return $v;
		}
		if (is_string($v)) {
			return $v;
		}
		if (is_float($v)) {
			if (is_nan($v)) {
				return ['__t' => 'n', 'v' => 'NaN'];
			}
			if (is_infinite($v)) {
				return ['__t' => 'n', 'v' => $v > 0 ? 'Infinity' : '-Infinity'];
			}
			return $v;
		}
		if (is_array($v)) {
			if (isset($v['__phpdate'])) {
				return ['__t' => 'd', 'v' => (string) $v['__phpdate']];
			}
			if (isset($v['__phpint'])) {
				return ['__t' => 'i', 'v' => (string) $v['__phpint']];
			}
			$out = [];
			foreach ($v as $k => $vv) {
				$out[$k] = pw_encode($vv, $depth + 1);
			}
			return $out;
		}
		return $v;
	}
}

/**
 * A stand-in for the Durable Object, speaking the same JSON contract.
 *
 * Underneath it is PDO SQLite, NOT ctx.storage.sql, so it can only prove things
 * about the PHP half of the driver. It implements the entry point the host
 * already has (cfwSqlExec) and the one it still needs (cfwSqlTxn), which is why
 * the suite can test both the working and the degraded configuration.
 *
 * The transaction entry point is where the shapes differ most: here a rollback
 * is PDO::rollBack(), whereas in the real host it is a throw out of
 * ctx.storage.transactionSync().
 */
final class FakeHost
{
	/**
	 * The real SQLite engine standing in for Durable Object storage.
	 *
	 * @var \PDO
	 */
	public \PDO $pdo;

	/**
	 * How many times the single-statement bridge was entered.
	 *
	 * @var int
	 */
	public int $execCalls = 0;

	/**
	 * How many times the transactional bridge was entered.
	 *
	 * @var int
	 */
	public int $txnCalls = 0;

	/**
	 * How many of those entries were rolled back rather than committed.
	 *
	 * The HOST's own view of the same thing Connection::speculativeCount() reports. Two
	 * independent instruments, so the suite can assert them against each other rather than
	 * asserting the driver against itself.
	 *
	 * @var int
	 */
	public int $speculativeCalls = 0;

	/**
	 * How many statements were executed inside rolled-back transactions.
	 *
	 * @var int
	 */
	public int $replayedStatements = 0;

	/**
	 * Forces the next statement matching this needle to fail.
	 *
	 * @var string|null
	 */
	public ?string $failOn = null;

	/**
	 * Column values to rewrite on the way out, as codec envelopes.
	 *
	 * @var array
	 */
	public array $envelopeColumns = [];

	/**
	 * Every statement the host was asked to run, in order.
	 *
	 * @var array
	 */
	public array $statements = [];

	/**
	 * Opens the in-memory database that backs every bridged statement.
	 */
	public function __construct()
	{
		$this->pdo = new \PDO('sqlite::memory:', '', '', [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
		]);
	}

	/**
	 * Builds the single-statement bridge the driver calls for ordinary queries.
	 *
	 * @return \Closure
	 *   A closure taking a JSON request and returning a JSON reply.
	 */
	public function execBridge(): \Closure
	{
		return function (string $json): string {
			$this->execCalls++;
			$request = pw_decode(json_decode($json, true));
			try {
				return json_encode(
					['ok' => true] + $this->run($request['sql'], $request['params'] ?? []),
				);
			} catch (\Throwable $e) {
				return json_encode(['ok' => false, 'error' => $e->getMessage()]);
			}
		};
	}

	/**
	 * Builds the bridge that runs several statements inside one transaction.
	 *
	 * @return \Closure
	 *   A closure taking a JSON request and returning a JSON reply.
	 */
	public function txnBridge(): \Closure
	{
		return function (string $json): string {
			$this->txnCalls++;
			$request = pw_decode(json_decode($json, true));
			$commit = (bool) ($request['commit'] ?? false);
			if (!$commit) {
				$this->speculativeCalls++;
				$this->replayedStatements += count($request['statements'] ?? []);
			}
			$results = [];
			$readResult = null;
			$this->pdo->beginTransaction();
			try {
				foreach ($request['statements'] ?? [] as $statement) {
					$results[] = $this->run($statement['sql'], $statement['params'] ?? []);
				}
				if (isset($request['read']) && is_array($request['read'])) {
					$readResult = $this->run(
						$request['read']['sql'],
						$request['read']['params'] ?? [],
					);
				}
			} catch (\Throwable $e) {
				$this->pdo->rollBack();
				return json_encode(['ok' => false, 'error' => $e->getMessage()]);
			}
			// throwing inside ctx.storage.transactionSync() is how a rollback is
			// requested; the sentinel is spelled as a rollback here
			$commit ? $this->pdo->commit() : $this->pdo->rollBack();
			return json_encode([
				'ok' => true,
				'results' => $results,
				'readResult' => $readResult,
			]);
		};
	}

	/**
	 * Bound parameters the real host accepts in one statement.
	 *
	 * MEASURED against a deployed `ctx.storage.sql`, by bisection through `/sql`: 100 binds
	 * succeed, 101 throws `too many SQL variables: SQLITE_ERROR`. Enforced here because the
	 * fixture's job is to be the platform, and local PDO's own limit is 32,766 -- so without
	 * this the whole class of "statement too wide" bug passes every test and fails only in
	 * production. It already did: core's sqlite Upsert emits one multi-row statement, and
	 * `DatabaseBackend::setMultiple()` upserts 100 rows over 7 columns, which is 700.
	 */
	const MAX_PLACEHOLDERS = 100;

	/**
	 * Runs one statement, honouring the injected failure and the placeholder cap.
	 *
	 * @param string $sql
	 *   The statement to run.
	 * @param array $params
	 *   Bound parameters, still in envelope form.
	 *
	 * @return array
	 *   Rows and the affected-row count, shaped as the host replies.
	 */
	private function run(string $sql, array $params): array
	{
		$this->statements[] = $sql;
		if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
			throw new \RuntimeException('deliberate host failure');
		}
		if (count($params) > self::MAX_PLACEHOLDERS) {
			// the host's own wording, so a driver that catches it matches on the real text
			throw new \RuntimeException('too many SQL variables: SQLITE_ERROR');
		}
		$statement = $this->pdo->prepare($sql);
		$statement->execute($this->bind($params));
		$rows = [];
		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$out = [];
			foreach ($row as $column => $value) {
				$out[$column] = isset($this->envelopeColumns[$column])
					? ['__t' => 'i', 'v' => $this->envelopeColumns[$column]]
					: $value;
			}
			$rows[] = $out;
		}
		return [
			'rows' => $rows,
			'rowsRead' => count($rows),
			'rowsWritten' => 0,
			'lastInsertRowid' => (int) $this->pdo->lastInsertId(),
			'changes' => $statement->rowCount(),
		];
	}

	/**
	 * Converts bound parameters from envelope form into values PDO accepts.
	 *
	 * @param array $params
	 *   Parameters as they arrived over the bridge.
	 *
	 * @return array
	 *   The same parameters, decoded.
	 */
	private function bind(array $params): array
	{
		$isList = $params === [] || array_keys($params) === range(0, count($params) - 1);
		$bound = [];
		foreach ($params as $key => $value) {
			if (is_array($value) && isset($value['__phpint'])) {
				$value = (string) $value['__phpint'];
			}
			$bound[$key] = $value;
		}
		// the real host spreads positional values into sql.exec(), so order is what
		// matters; PDO wants a 0-indexed array for '?' placeholders
		return $isList ? array_values($bound) : $bound;
	}
}

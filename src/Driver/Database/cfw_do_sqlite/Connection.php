<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\InvalidQueryException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\sqlite\Driver\Database\sqlite\Connection as SqliteDriverConnection;
use Exception;

/**
 * Drupal connection to ctx.storage.sql inside a Durable Object.
 *
 * It extends the core sqlite driver rather than the base Connection because the
 * engine underneath genuinely is SQLite: the SQL generation, the query builders,
 * the schema handling and the LIKE/GLOB operator map are all correct as they
 * stand and are not forked here. What changes is everything that assumed PDO and
 * a file on disk.
 *
 * Four differences are worth reading before using this class.
 *
 * NO PDO. The client connection is a CfwSqlClient, which calls a function the
 * host installed on the PHP Module. PHP runs inside the Durable Object, so a
 * query is a synchronous call across the wasm boundary, not a round trip.
 *
 * NO USER FUNCTIONS OR COLLATIONS. ctx.storage.sql has neither, measured, so the
 * dozen SQL functions the core driver registers through
 * PDO::sqliteCreateFunction() do not exist and NOCASE_UTF8 does not exist.
 * Schema substitutes the builtin NOCASE, which folds ASCII only; see Schema.
 *
 * NO TRANSACTION CONTROL. BEGIN is refused by the runtime. Writes issued while a
 * Drupal transaction is open are buffered here and replayed inside one host-side
 * transactionSync() at commit. That makes reads the interesting case, and
 * runStatement() is where it is handled.
 *
 * NO TABLE PREFIX. The core sqlite driver implements prefixes by attaching one
 * database file per prefix; there are no files and no ATTACH here. One Durable
 * Object is one database, so a non-empty prefix is rejected at construction
 * rather than silently producing "prefix"."table" against a schema that does not
 * exist.
 *
 * @see CfwSqlClient
 * @see TransactionManager
 * @see Schema
 */
class Connection extends SqliteDriverConnection
{
	/**
	 * The writes withheld while a Drupal transaction is open.
	 */
	private ?TransactionBuffer $buffer = null;

	/**
	 * The engine version, cached after the first query that needs it.
	 */
	private ?string $engineVersion = null;

	/**
	 * Whether $engineVersion is a probed floor rather than a reported version.
	 */
	private bool $engineVersionIsFloor = false;

	/**
	 * Marks a placeholder whose value is a LIKE pattern needing GLOB translation.
	 *
	 * Emitted by mapConditionOperator() as the operator's SQL prefix, which
	 * Condition::compile() places immediately before the placeholder. Comment
	 * syntax so it is inert if it ever reaches the engine, and so the
	 * literal-aware scanner in SqlAnalyzer steps over it.
	 */
	private const LIKE_BINARY_MARKER = '/*cfw:l2g*/';

	/**
	 * Longest LIKE or GLOB pattern ctx.storage.sql will accept, in bytes.
	 *
	 * Measured, not from documentation: 50 bytes succeeds and 51 fails with
	 * "LIKE or GLOB pattern too complex: SQLITE_ERROR". SQLite's own default
	 * SQLITE_MAX_LIKE_PATTERN_LENGTH is 50,000, so this is the runtime lowering it
	 * by three orders of magnitude. It applies to plain LIKE as well, which this
	 * driver cannot intercept -- a Views "contains" filter on a long search string
	 * will fail in the engine.
	 */
	public const MAX_LIKE_PATTERN_BYTES = 50;

	/**
	 * Constructs a Connection.
	 *
	 * @param object $connection
	 *   The client connection, a CfwSqlClient. The parameter is widened from the
	 *   core sqlite driver's \PDO because there is no PDO here.
	 * @param array $connection_options
	 *   The connection options. 'database' is accepted and ignored: the Durable
	 *   Object's identity selects the database, so the value is documentation.
	 *
	 * @throws HostBridgeException
	 *   If the client is not a CfwSqlClient, or a table prefix was configured.
	 */
	public function __construct(object $connection, array $connection_options)
	{
		if (!($connection instanceof CfwSqlClient)) {
			throw new HostBridgeException(
				sprintf(
					'The cfw_do_sqlite driver requires a CfwSqlClient as its client connection, got %s.',
					get_debug_type($connection),
				),
			);
		}
		if (($connection_options['prefix'] ?? '') !== '') {
			throw new HostBridgeException(
				sprintf(
					"A table prefix ('%s') is configured, but the core sqlite driver implements prefixes with ATTACH DATABASE and ctx.storage.sql has no second database to attach. Use one Durable Object per site instead.",
					$connection_options['prefix'],
				),
			);
		}

		// the core sqlite constructor types its client as \PDO and runs the prefix
		// attach logic, so the base constructor is invoked directly; with an empty
		// prefix it is all the sqlite one would have done anyway
		DatabaseConnection::__construct($connection, $connection_options);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function open(array &$connection_options = [])
	{
		// init_commands are deliberately not executed. The core driver sets
		// "PRAGMA journal_mode=WAL" here; ctx.storage.sql manages its own
		// journalling and exposes no user-visible WAL, so the pragma is at best
		// ignored and at worst refused.
		return new CfwSqlClient();
	}

	/**
	 * {@inheritdoc}
	 */
	public function __destruct()
	{
		// the sqlite destructor prunes attached database files when a table has been
		// dropped; there are no files and getAttachedDatabases() is synthetic, so
		// only the base cycle-breaking behaviour is wanted
		DatabaseConnection::__destruct();
	}

	/**
	 * {@inheritdoc}
	 */
	public function driver()
	{
		return 'cfw_do_sqlite';
	}

	/**
	 * {@inheritdoc}
	 */
	public function databaseType()
	{
		// the engine is SQLite, and code branching on databaseType() is asking
		// about the dialect rather than about the driver
		return 'sqlite';
	}

	/**
	 * {@inheritdoc}
	 */
	public function createDatabase($database)
	{
		// the Durable Object's storage always exists
	}

	/**
	 * {@inheritdoc}
	 */
	public function attachDatabase(string $database): void
	{
		throw new HostBridgeException(
			sprintf(
				"Cannot attach '%s': ctx.storage.sql exposes exactly one database and has no ATTACH.",
				$database,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function getAttachedDatabases()
	{
		// the one schema that exists, reported so that the inherited
		// Schema::findTables() has something to iterate; the backing property stays
		// empty so the sqlite destructor never tries to prune a file
		return ['main' => 'main'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function prepareStatement(
		string $query,
		array $options,
		bool $allow_row_count = false,
	): StatementInterface {
		assert(
			!isset($options['return']),
			'Passing "return" option to prepareStatement() has no effect. See https://www.drupal.org/node/3185520',
		);

		try {
			$query = $this->preprocessStatement($query, $options);
			// $options['pdo'] is accepted by the interface and ignored: there are no
			// PDO statement attributes to set
			return new Statement($this->client(), $this, $query, $allow_row_count);
		} catch (Exception $e) {
			// the handler always throws, but core types it void rather than never, so
			// the alternative is falling through with no statement to return
			$this->exceptionHandler()->handleStatementException($e, $query, $options);
			throw $e;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	protected function preprocessStatement(string $query, array $options): string
	{
		// the core driver supplies IF/GREATEST/LEAST/RAND through
		// PDO::sqliteCreateFunction(), which does not exist here, so the four that
		// have an exact builtin equivalent are renamed to it
		return SqlAnalyzer::rewriteFunctions(parent::preprocessStatement($query, $options));
	}

	/**
	 * {@inheritdoc}
	 */
	public function mapConditionOperator($operator)
	{
		// The core driver maps this to GLOB and then overrides GLOB with a PHP
		// function so LIKE's % and _ keep meaning "any run" and "any one". There are
		// no user functions here, and builtin GLOB reads % and _ as literals -- so
		// the unmodified mapping returns no rows and says nothing.
		//
		// The seam is the operator's SQL prefix. Condition::compile() emits
		// `field OP prefix placeholder postfix`, so a marker in the prefix lands
		// immediately before the placeholder and names the argument whose value has
		// to be rewritten from LIKE syntax to GLOB syntax. That rewrite happens in
		// PHP in runStatement(), where it can be exact.
		//
		// The postfix is dropped deliberately: core appends " ESCAPE '\'", and
		// `GLOB ? ESCAPE '\'` is a three-argument GLOB, which the builtin refuses
		// with "wrong number of arguments to function GLOB()". Measured.
		if ($operator === 'LIKE BINARY') {
			return ['operator' => 'GLOB', 'prefix' => self::LIKE_BINARY_MARKER, 'postfix' => ''];
		}
		if ($operator === 'NOT LIKE BINARY') {
			return [
				'operator' => 'NOT GLOB',
				'prefix' => self::LIKE_BINARY_MARKER,
				'postfix' => '',
			];
		}

		return parent::mapConditionOperator($operator);
	}

	/**
	 * Rewrites marked LIKE patterns into GLOB patterns.
	 *
	 * Called before anything looks at the statement, so a buffered write stores the
	 * translated form and a speculative replay cannot disagree with the commit.
	 *
	 * @param string $sql
	 *   The statement; markers are removed in place.
	 * @param array $params
	 *   The parameters; marked values are translated in place.
	 *
	 * @throws InvalidQueryException
	 *   If a marker is not followed by a resolvable named placeholder, or if the
	 *   rewrite itself fails. Refusing is the point: an untranslated pattern
	 *   silently matches nothing.
	 */
	private function translateLikeBinary(string &$sql, array &$params): void
	{
		if (!str_contains($sql, self::LIKE_BINARY_MARKER)) {
			return;
		}

		$marker = preg_quote(self::LIKE_BINARY_MARKER, '/');
		$rewritten = preg_replace_callback(
			'/' . $marker . '\s*(:[A-Za-z0-9_]+|\?)/',
			function (array $m) use (&$params, $sql) {
				$placeholder = $m[1];
				if ($placeholder === '?') {
					// Condition::compile() always emits named placeholders, so reaching
					// this means the marker arrived from somewhere else and the argument
					// cannot be identified by name
					throw new InvalidQueryException(
						sprintf(
							'A LIKE BINARY pattern was marked against a positional placeholder, which cannot be identified by name: %s',
							$sql,
						),
					);
				}
				$key = array_key_exists($placeholder, $params)
					? $placeholder
					: (array_key_exists(substr($placeholder, 1), $params)
						? substr($placeholder, 1)
						: null);
				if ($key === null) {
					throw new InvalidQueryException(
						sprintf(
							"A LIKE BINARY pattern was marked for placeholder '%s', which is not bound: %s",
							$placeholder,
							$sql,
						),
					);
				}
				if (!is_string($params[$key])) {
					throw new InvalidQueryException(
						sprintf(
							"A LIKE BINARY pattern for '%s' is a %s, not a string.",
							$placeholder,
							get_debug_type($params[$key]),
						),
					);
				}
				$glob = SqlAnalyzer::likeToGlob($params[$key]);
				// Measured against ctx.storage.sql: 50 bytes passes, 51 fails with
				// "LIKE or GLOB pattern too complex: SQLITE_ERROR". The check is on the
				// TRANSLATED pattern because bracket-quoting expands a metacharacter
				// threefold (`*` -> `[*]`), so a 20-byte LIKE pattern of asterisks
				// becomes a 60-byte GLOB pattern and would fail where the input looked
				// safe. Refusing here names the cause; letting it through surfaces the
				// engine's message from somewhere unrelated.
				if (strlen($glob) > self::MAX_LIKE_PATTERN_BYTES) {
					throw new InvalidQueryException(
						sprintf(
							'This LIKE BINARY pattern is %d bytes once translated to GLOB, and ctx.storage.sql refuses any LIKE or GLOB pattern over %d bytes ("pattern too complex"). Shorten the search term, or filter in PHP. Note plain LIKE has the same ceiling.',
							strlen($glob),
							self::MAX_LIKE_PATTERN_BYTES,
						),
					);
				}
				$params[$key] = $glob;
				return $placeholder;
			},
			$sql,
		);
		if ($rewritten === null) {
			// a PCRE failure here would otherwise blank the statement and send an
			// empty string to the host, which reports something unrelated
			throw new InvalidQueryException(
				sprintf(
					'Rewriting a LIKE BINARY pattern failed (%s): %s',
					preg_last_error_msg(),
					$sql,
				),
			);
		}
		$sql = $rewritten;
	}

	/**
	 * {@inheritdoc}
	 */
	public function exceptionHandler()
	{
		return new ExceptionHandler();
	}

	/**
	 * {@inheritdoc}
	 *
	 * Overridden ONLY to reach this driver's Upsert. Drupal 11 resolves query classes by
	 * standard autoloading inside the method that returns them rather than through
	 * getDriverClass(), which now throws for 'Upsert' -- so without this override, core's
	 * sqlite Connection::upsert() resolves `Upsert` in the sqlite namespace and this
	 * driver's subclass is never constructed.
	 *
	 * That matters because the host caps a statement at 100 bound parameters and core's
	 * sqlite Upsert emits one multi-row statement of rows x fields. See Upsert.php.
	 */
	public function upsert($table, array $options = [])
	{
		return new Upsert($this, $table, $options);
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema()
	{
		if (empty($this->schema)) {
			$this->schema = new Schema($this);
		}
		return $this->schema;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function driverTransactionManager(): TransactionManagerInterface
	{
		return new TransactionManager($this);
	}

	/**
	 * {@inheritdoc}
	 */
	public function version()
	{
		if ($this->engineVersion !== null) {
			return $this->engineVersion;
		}

		try {
			$this->engineVersion = (string) $this->query(
				'SELECT sqlite_version() AS [version]',
			)->fetchField();
			$this->engineVersionIsFloor = false;
		} catch (Exception) {
			// Measured, not inferred: workerd's SQL authorizer refuses the call with
			// "not authorized to use function: sqlite_version". So the engine will not
			// report its own version and the only honest answer is a floor proven by
			// feature probe. Drupal 11.4.5 gates installation on >= 3.45, which a
			// guess could not be trusted to clear.
			$this->engineVersion = $this->probeVersionFloor();
			$this->engineVersionIsFloor = true;
		}

		return $this->engineVersion;
	}

	/**
	 * Establishes a lower bound on the engine version by probing for features.
	 *
	 * Each candidate landed in exactly one SQLite release, so the highest one that
	 * answers is a proven floor rather than an estimate. Ordered ascending; the
	 * last success wins.
	 *
	 * @return string
	 *   The highest proven version, as a three-part string.
	 */
	private function probeVersionFloor(): string
	{
		$ladder = [
			// iif() -- 3.32.0
			'3.32.0' => 'SELECT iif(1,2,3)',
			// math functions -- 3.35.0
			'3.35.0' => 'SELECT pow(2,3)',
			// concat() -- 3.44.0
			'3.44.0' => "SELECT concat('a','b')",
			// jsonb -- 3.45.0
			'3.45.0' => "SELECT hex(jsonb('{}'))",
			// unhex() -- 3.46.0
			'3.46.0' => "SELECT unhex('41')",
		];

		$floor = '3.0.0';
		foreach ($ladder as $release => $sql) {
			try {
				$this->query($sql);
				$floor = $release;
			} catch (Exception) {
				// this release's marker is absent, so the floor stays where it was
				break;
			}
		}

		return $floor;
	}

	/**
	 * Returns whether version() reported a probed floor rather than a real version.
	 *
	 * Anything that logs or displays the engine version should say so, because a
	 * floor is not the version.
	 *
	 * @return bool
	 *   TRUE when the engine refused to report its version.
	 */
	public function engineVersionIsFloor(): bool
	{
		// resolve the version first, or the flag has not been set yet
		$this->version();
		return $this->engineVersionIsFloor;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Ctx.storage.sql refuses CREATE TEMPORARY TABLE with "not authorized:
	 * SQLITE_AUTH", measured. The core sqlite driver implements this and our class
	 * inherits SupportsTemporaryTablesInterface from it, so an `instanceof` check
	 * cannot be made to fail; throwing is the only way a caller learns.
	 *
	 * Auditing core for callers found none outside the interface declaration and
	 * the three driver implementations, so nothing in Drupal 11.4.5 reaches this.
	 */
	public function queryTemporary($query, array $args = [], array $options = [])
	{
		throw new InvalidQueryException(
			'ctx.storage.sql refuses CREATE TEMPORARY TABLE (SQLITE_AUTH), so this driver has no temporary tables. Drupal core has no queryTemporary() callers; materialise the result in PHP or write to a real table you drop.',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function clientVersion()
	{
		// the client and the engine are the same build, linked into the runtime
		return $this->version();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $string
	 *   The string to quote.
	 * @param int $parameter_type
	 *   Ignored. Spelled as an int rather than PDO::PARAM_STR so the driver does
	 *   not require the PDO extension to be present.
	 */
	public function quote($string, $parameter_type = 2)
	{
		return "'" . str_replace("'", "''", (string) $string) . "'";
	}

	/**
	 * {@inheritdoc}
	 */
	public function lastInsertId(?string $name = null): string
	{
		if ($this->buffer !== null && $this->buffer->hasInsert()) {
			return $this->bufferedInsertId();
		}
		return $this->client()->lastInsertId();
	}

	/**
	 * Returns how many statements this connection has sent to the host.
	 *
	 * Includes replays, so a transaction resolved speculatively costs more than
	 * one statement per Drupal query. Useful for the per-request query budget.
	 *
	 * @return int
	 *   The count.
	 */
	public function statementCount(): int
	{
		return $this->client()->statementCount();
	}

	/**
	 * Returns whether a buffered transaction can be committed atomically.
	 *
	 * FALSE means the host has no transaction entry point, so a commit replays
	 * the buffer statement by statement and a failure part-way through leaves the
	 * earlier statements applied.
	 *
	 * @return bool
	 *   TRUE when the host exposes its transaction primitive.
	 */
	public function supportsAtomicCommit(): bool
	{
		return $this->client()->supportsTransactions();
	}

	/**
	 * Returns whether a Drupal transaction is currently buffering writes.
	 *
	 * @return bool
	 *   TRUE when writes are being withheld.
	 */
	public function isBuffering(): bool
	{
		return $this->buffer !== null;
	}

	/**
	 * Runs a statement, or buffers it, and returns what the caller needs.
	 *
	 * This is the whole of the transaction mapping, and the read case is the part
	 * that is not obvious.
	 *
	 * With no Drupal transaction open, everything goes straight to the host. The
	 * Durable Object still wraps each event in an implicit transaction of its own,
	 * so a single statement is atomic without any help.
	 *
	 * With a transaction open:
	 * - a write is buffered, and returns no rows and no row count;
	 * - a read whose tables have no buffered write is proven unaffected, and goes
	 *   straight to the host;
	 * - a read whose tables do have buffered writes has to observe them, so the
	 *   buffer is replayed and the read evaluated inside a host transaction that
	 *   is then rolled back. Without the host's transaction entry point that is
	 *   impossible, and the driver raises UncommittedStateException rather than
	 *   answering from the committed database, which would be quietly wrong;
	 * - a statement that cannot be classified is refused for the same reason.
	 *
	 * @param string $sql
	 *   The statement, prefixes and identifier quotes already resolved.
	 * @param array $params
	 *   The parameters, ready for the host.
	 *
	 * @return array{rows: array, rowCount: int|null, bufferIndex: int|null}
	 *   The rows, the number of rows changed where it is known, and the buffer
	 *   index when the statement was withheld.
	 *
	 * @throws UncommittedStateException
	 *   If the statement cannot be answered without observing buffered state.
	 * @throws SqlErrorException
	 *   If SQLite rejected the statement.
	 */
	public function runStatement(string $sql, array $params): array
	{
		// before classification and before buffering, so every later reader of the
		// statement sees the translated form
		$this->translateLikeBinary($sql, $params);

		$kind = SqlAnalyzer::classify($sql);

		if ($kind === SqlAnalyzer::TRANSACTION_CONTROL) {
			throw new UncommittedStateException(
				sprintf(
					'This driver never issues transaction control, because ctx.storage.sql refuses it; something bypassed the transaction manager with: %s',
					$sql,
				),
			);
		}

		if ($this->buffer === null) {
			return $this->runDirect($sql, $params);
		}

		if ($kind === SqlAnalyzer::WRITE) {
			return [
				'rows' => [],
				'rowCount' => null,
				'bufferIndex' => $this->buffer->add(
					$sql,
					$params,
					SqlAnalyzer::writtenTables($sql),
				),
			];
		}

		if ($kind === SqlAnalyzer::UNKNOWN) {
			throw new UncommittedStateException(
				sprintf(
					'Cannot tell whether this statement reads or writes, and %d write(s) are buffered, so neither running it nor withholding it is safe: %s',
					$this->buffer->count(),
					$sql,
				),
			);
		}

		if ($this->buffer->isEmpty() || !$this->buffer->touches(SqlAnalyzer::readTables($sql))) {
			return $this->runDirect($sql, $params);
		}

		return $this->runSpeculativeRead($sql, $params);
	}

	/**
	 * Opens the write buffer.
	 *
	 * @throws UncommittedStateException
	 *   If a buffer is already open, which would mean the transaction stack and
	 *   the driver disagree about the depth.
	 */
	public function beginBufferedTransaction(): void
	{
		if ($this->buffer !== null) {
			throw new UncommittedStateException(
				'A transaction buffer is already open; nested root transactions are collapsed by the transaction manager and should never reach here.',
			);
		}
		$this->buffer = new TransactionBuffer();
	}

	/**
	 * Discards the write buffer, which is what a rollback is.
	 */
	public function discardBufferedTransaction(): void
	{
		$this->buffer = null;
	}

	/**
	 * Replays the write buffer and closes it.
	 *
	 * The buffer is detached before the replay, so a failure cannot leave the
	 * connection buffering into a transaction Drupal has already finished with.
	 *
	 * @throws SqlErrorException
	 *   If SQLite rejected one of the replayed statements. With the host's
	 *   transaction entry point the whole replay is rolled back; without it, the
	 *   statements before the failure remain applied.
	 */
	public function commitBufferedTransaction(): void
	{
		$buffer = $this->buffer;
		$this->buffer = null;

		if ($buffer === null || $buffer->isEmpty()) {
			return;
		}

		$statements = $buffer->statements();
		if ($this->client()->supportsTransactions()) {
			$this->client()->runTransaction($statements, true);
			return;
		}

		// Degraded path: without the host's transaction primitive the replay is a
		// sequence of independent statements. Rollback stayed correct - nothing was
		// written before this point - but a failure mid-replay is not undone.
		foreach ($statements as $statement) {
			$this->client()->exec($statement['sql'], $statement['params']);
		}
	}

	/**
	 * Records a savepoint at the current buffer position.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function markBufferSavepoint(string $name): void
	{
		$this->requireBuffer('create savepoint ' . $name)->mark($name);
	}

	/**
	 * Discards everything buffered since a savepoint.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function rollbackBufferToSavepoint(string $name): void
	{
		$this->requireBuffer('roll back to savepoint ' . $name)->rollbackTo($name);
	}

	/**
	 * Forgets a savepoint, keeping what was buffered after it.
	 *
	 * @param string $name
	 *   The savepoint name.
	 */
	public function releaseBufferSavepoint(string $name): void
	{
		$this->requireBuffer('release savepoint ' . $name)->release($name);
	}

	/**
	 * Resolves how many rows a buffered write changed.
	 *
	 * Nothing has run, so the only way to know is to replay the buffer up to that
	 * statement and read the counter, then discard the replay. That costs one
	 * host call and O(buffered statements) statement executions inside it, which
	 * is why Statement caches the answer.
	 *
	 * @param int $index
	 *   The buffer index of the statement.
	 *
	 * @return int
	 *   The number of rows the statement would change.
	 *
	 * @throws UncommittedStateException
	 *   If the buffer is gone, or the host cannot run a speculative replay.
	 */
	public function resolveBufferedRowCount(int $index): int
	{
		$buffer = $this->requireBuffer('resolve the row count of a buffered write');

		if (!$this->client()->supportsTransactions()) {
			throw new UncommittedStateException(
				sprintf(
					"The number of rows changed by a buffered write cannot be known without replaying the buffer, and the host has not installed '%s'. Either add that entry point or do not ask for a row count inside a transaction.",
					CfwSqlClient::TRANSACTION_BRIDGE,
				),
			);
		}

		$replay = $this->client()->runTransaction($buffer->statementsUpTo($index), false);
		$result = $replay['results'][$index] ?? null;

		return is_array($result) ? $result['changes'] : 0;
	}

	/**
	 * Sends a statement straight to the host.
	 *
	 * @param string $sql
	 *   The statement.
	 * @param array $params
	 *   The parameters.
	 *
	 * @return array{rows: array, rowCount: int|null, bufferIndex: int|null}
	 *   The outcome.
	 */
	private function runDirect(string $sql, array $params): array
	{
		$result = $this->client()->exec($sql, $params);

		return [
			'rows' => $result['rows'],
			'rowCount' => $result['changes'],
			'bufferIndex' => null,
		];
	}

	/**
	 * Answers a read that has to observe buffered writes.
	 *
	 * The buffer is replayed and the read evaluated inside one host transaction
	 * which is then rolled back, so the read sees the writes and the database
	 * does not. Throwing inside transactionSync() is the measured rollback
	 * mechanism; this is the same door, used deliberately.
	 *
	 * @param string $sql
	 *   The read.
	 * @param array $params
	 *   Its parameters.
	 *
	 * @return array{rows: array, rowCount: int|null, bufferIndex: int|null}
	 *   The outcome.
	 *
	 * @throws UncommittedStateException
	 *   If the host cannot run a speculative replay.
	 */
	private function runSpeculativeRead(string $sql, array $params): array
	{
		$buffer = $this->requireBuffer('read uncommitted state');

		if (!$this->client()->supportsTransactions()) {
			throw new UncommittedStateException(
				sprintf(
					"This read would have to see %d uncommitted buffered write(s) to %s, and the host has not installed '%s', so the buffer cannot be replayed. Answering from the committed database would return data that is wrong without saying so. Read: %s",
					$buffer->count(),
					implode(', ', SqlAnalyzer::readTables($sql)) ?: 'an unknown table',
					CfwSqlClient::TRANSACTION_BRIDGE,
					$sql,
				),
			);
		}

		$replay = $this->client()->runTransaction($buffer->statements(), false, [
			'sql' => $sql,
			'params' => $params,
		]);
		$read = $replay['readResult'];

		if (!is_array($read)) {
			throw new UncommittedStateException(
				'The host ran the speculative replay but returned no result for the read that needed it.',
			);
		}

		return [
			'rows' => $read['rows'],
			'rowCount' => $read['changes'],
			'bufferIndex' => null,
		];
	}

	/**
	 * Returns the rowid a buffered insert would be given.
	 *
	 * @return string
	 *   The rowid as a decimal string.
	 *
	 * @throws UncommittedStateException
	 *   If the host cannot run a speculative replay.
	 */
	private function bufferedInsertId(): string
	{
		$buffer = $this->requireBuffer('report the id of a buffered insert');
		$index = $buffer->lastInsertIndex();

		if ($index === null) {
			return $this->client()->lastInsertId();
		}

		if (!$this->client()->supportsTransactions()) {
			throw new UncommittedStateException(
				sprintf(
					"An insert inside a transaction has been buffered, so SQLite has not assigned it a rowid yet, and the host has not installed '%s' to work it out. Returning a guessed id would corrupt every entity saved in a transaction.",
					CfwSqlClient::TRANSACTION_BRIDGE,
				),
			);
		}

		$replay = $this->client()->runTransaction($buffer->statementsUpTo($index), false);
		$result = $replay['results'][$index] ?? null;

		return is_array($result) ? $result['lastInsertId'] : '0';
	}

	/**
	 * Returns the open buffer.
	 *
	 * @param string $action
	 *   What the caller was trying to do, for the error message.
	 *
	 * @return TransactionBuffer
	 *   The buffer.
	 *
	 * @throws UncommittedStateException
	 *   If no transaction is open.
	 */
	private function requireBuffer(string $action): TransactionBuffer
	{
		if ($this->buffer === null) {
			throw new UncommittedStateException(
				sprintf('Cannot %s: no Drupal transaction is open on this connection.', $action),
			);
		}
		return $this->buffer;
	}

	/**
	 * Returns the client connection, typed.
	 *
	 * @return CfwSqlClient
	 *   The client.
	 *
	 * @throws HostBridgeException
	 *   If the connection has already been torn down.
	 */
	private function client(): CfwSqlClient
	{
		if (!($this->connection instanceof CfwSqlClient)) {
			throw new HostBridgeException(
				'The client connection is gone; the Connection object has been destructed or was built without one.',
			);
		}
		return $this->connection;
	}
}

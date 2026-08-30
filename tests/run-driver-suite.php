<?php

/**
 * @file
 * Drives the cfw_do_sqlite driver against a stand-in host.
 *
 * What this proves and what it does not. The host here is PDO SQLite speaking
 * the same JSON contract as src/do-sqlite.js, not ctx.storage.sql. So it proves
 * the PHP half: the write buffering, the read/write overlap analysis, the
 * savepoint truncation, the function substitution, the codec normalisation and
 * the integration with Drupal's own query builders and schema handling. It does
 * not prove anything about the Durable Object runtime - whether it accepts
 * PRAGMA table_info, whether its SQLite has concat(), or how it behaves across
 * events. Those need the Worker.
 *
 * Usage:
 *   php tests/run-driver-suite.php [/path/to/drupal-root]
 *
 * The Drupal root must be a checkout with vendor/ installed; it is only read.
 */

declare(strict_types=1);

use Drupal\Core\Database\InvalidQueryException;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\Upsert;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\CfwSqlClient;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\Connection;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\HostBridgeException;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\Schema;
use Drupal\sqlite\Driver\Database\sqlite\Connection as CoreSqliteConnection;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\SqlAnalyzer;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\SqlErrorException;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\UncommittedStateException;

require __DIR__ . '/fixtures/FakeHost.php';

$root = $argv[1] ?? getenv('DRUPAL_ROOT') ?: null;
if ($root === null || !is_file($root . '/vendor/autoload.php')) {
	fwrite(STDERR, "Pass a Drupal 11 root with vendor/ installed, or set DRUPAL_ROOT.\n");
	exit(2);
}
if (!is_dir($root . '/core/modules/sqlite/src')) {
	fwrite(
		STDERR,
		"That root has no core/modules/sqlite; this driver extends the core sqlite driver.\n",
	);
	exit(2);
}

$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Drupal\\sqlite\\', $root . '/core/modules/sqlite/src');
$loader->addPsr4('Drupal\\cfw_do_sqlite\\', dirname(__DIR__) . '/src');

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What the assertion claims.
 * @param bool $condition
 *   The result of evaluating that claim.
 * @param string $detail
 *   Extra context printed only on failure.
 */
function ok(string $label, bool $condition, string $detail = ''): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
	}
}

/**
 * Builds a driver Connection wired to a fresh fake host.
 *
 * @param bool $withTransactionBridge
 *   Whether to supply the transactional bridge; FALSE exercises the fallback
 *   path a host without transactionSync() would take.
 *
 * @return array
 *   The fake host and the Connection bound to it, in that order.
 */
function connect(bool $withTransactionBridge = true): array
{
	$host = new FakeHost();
	$client = new CfwSqlClient(
		$host->execBridge(),
		$withTransactionBridge ? $host->txnBridge() : null,
	);
	$connection = new Connection($client, ['prefix' => '', 'database' => 'do']);
	return [$host, $connection];
}

// --- SqlAnalyzer -----------------------------------------------------------
echo "SqlAnalyzer\n";
ok('select is a read', SqlAnalyzer::classify('SELECT 1') === SqlAnalyzer::READ);
ok(
	'insert is a write',
	SqlAnalyzer::classify(' insert INTO "node" (a) VALUES (1)') === SqlAnalyzer::WRITE,
);
ok(
	'BEGIN is transaction control',
	SqlAnalyzer::classify('BEGIN') === SqlAnalyzer::TRANSACTION_CONTROL,
);
ok(
	'SAVEPOINT is transaction control',
	SqlAnalyzer::classify('SAVEPOINT x') === SqlAnalyzer::TRANSACTION_CONTROL,
);
ok(
	'WITH + INSERT is a write',
	SqlAnalyzer::classify('WITH c AS (SELECT 1) INSERT INTO "n" SELECT * FROM c') ===
		SqlAnalyzer::WRITE,
);
ok(
	'WITH + SELECT is a read',
	SqlAnalyzer::classify('WITH c AS (SELECT 1) SELECT * FROM c') === SqlAnalyzer::READ,
);
ok(
	'assigning pragma is unknown',
	SqlAnalyzer::classify('PRAGMA journal_mode=WAL') === SqlAnalyzer::UNKNOWN,
);
ok(
	'reading pragma is a read',
	SqlAnalyzer::classify('PRAGMA "main".table_info("node")') === SqlAnalyzer::READ,
);
ok('gibberish is unknown', SqlAnalyzer::classify('FLUMMOX "node"') === SqlAnalyzer::UNKNOWN);

ok('insert target', SqlAnalyzer::writtenTables('INSERT INTO "node" (a) VALUES (1)') === ['node']);
ok(
	'insert or ignore target',
	SqlAnalyzer::writtenTables('INSERT OR IGNORE INTO "node" (a) VALUES (1)') === ['node'],
);
ok(
	'update target',
	SqlAnalyzer::writtenTables(
		'UPDATE "users_field_data" SET a = 1 WHERE b IN (SELECT c FROM "node")',
	) === ['users_field_data'],
);
ok(
	'delete target',
	SqlAnalyzer::writtenTables('DELETE FROM "cache_render" WHERE cid = ?') === ['cache_render'],
);
ok(
	'insert-select writes only the target',
	SqlAnalyzer::writtenTables('INSERT INTO "node_0" (a) SELECT a FROM "node"') === ['node_0'],
);
ok(
	'create table dirties the schema too',
	SqlAnalyzer::writtenTables('CREATE TABLE "node" (a INT)') === ['node', 'sqlite_master'],
);
ok(
	'create index target is the table',
	SqlAnalyzer::writtenTables('CREATE UNIQUE INDEX "main"."node_x" ON "node" (a)') === [
		'node',
		'sqlite_master',
	],
);
ok(
	'drop table target',
	SqlAnalyzer::writtenTables('DROP TABLE "node"') === ['node', 'sqlite_master'],
);
ok(
	'rename dirties everything',
	SqlAnalyzer::writtenTables('ALTER TABLE "node_0" RENAME TO "node"') === [
		SqlAnalyzer::ALL_TABLES,
	],
);
ok(
	'drop index dirties everything',
	SqlAnalyzer::writtenTables('DROP INDEX "node_x"') === [SqlAnalyzer::ALL_TABLES],
);

ok(
	'read tables from joins',
	SqlAnalyzer::readTables('SELECT * FROM "node" n INNER JOIN "users" u ON n.uid = u.uid') === [
		'node',
		'users',
	],
);
ok(
	'literal is not a table',
	SqlAnalyzer::readTables("SELECT * FROM \"node\" WHERE title = ' FROM users '") === ['node'],
);
ok(
	'comment is not a table',
	SqlAnalyzer::readTables("SELECT * FROM \"node\" -- don't join users\n") === ['node'],
);
ok(
	'block comment is not a table',
	SqlAnalyzer::readTables('SELECT /* FROM users */ * FROM "node"') === ['node'],
);
ok(
	'literal holding a dash pair does not eat the query',
	SqlAnalyzer::readTables(
		"SELECT * FROM \"node\" WHERE a = 'x--y' AND b IN (SELECT c FROM \"users\")",
	) === ['node', 'users'],
);
ok(
	'subquery table is found',
	SqlAnalyzer::readTables('SELECT * FROM (SELECT a FROM "taxonomy_index")') === [
		'taxonomy_index',
	],
);
ok('no table at all', SqlAnalyzer::readTables('SELECT sqlite_version()') === []);

// --- plain queries ---------------------------------------------------------
echo "\nPlain queries\n";
[$host, $connection] = connect();
$connection->schema()->createTable('node', [
	'fields' => [
		'nid' => ['type' => 'serial', 'not null' => true],
		'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'binary' => false],
		'created' => ['type' => 'int', 'not null' => true, 'default' => 0],
	],
	'primary key' => ['nid'],
	'indexes' => ['node_created' => ['created']],
]);
ok('table created', $connection->schema()->tableExists('node'));
$ddl = $host->pdo->query("SELECT sql FROM sqlite_master WHERE name = 'node'")->fetchColumn();
ok(
	'NOCASE_UTF8 was replaced with builtin NOCASE',
	str_contains($ddl, 'COLLATE NOCASE') && !str_contains($ddl, 'NOCASE_UTF8'),
	$ddl,
);
ok(
	'engine version reported',
	version_compare($connection->version(), '3.0', '>='),
	$connection->version(),
);

$id = $connection
	->insert('node')
	->fields(['title' => 'First', 'created' => 100])
	->execute();
ok('insert returns a rowid', $id === '1', var_export($id, true));
$connection
	->insert('node')
	->fields(['title' => 'Second', 'created' => 200])
	->execute();

$rows = $connection
	->select('node', 'n')
	->fields('n', ['nid', 'title'])
	->orderBy('nid')
	->execute()
	->fetchAll();
ok('two rows read back', count($rows) === 2);
ok(
	'columns arrive as strings',
	$rows[0]->nid === '1' && $rows[0]->title === 'First',
	var_export($rows[0], true),
);

$count = $connection->query('SELECT COUNT(*) FROM {node}')->fetchField();
ok('aggregate reads', (int) $count === 2);

$affected = $connection
	->update('node')
	->fields(['created' => 300])
	->condition('nid', 2)
	->execute();
ok('update reports rows changed', $affected === 1, var_export($affected, true));

$connection->query('SELECT * FROM {node} WHERE title LIKE :t', [':t' => 'fir%']);
ok('named parameters bind', true);

// case-insensitive match through builtin NOCASE
$hit = $connection
	->select('node', 'n')
	->fields('n', ['nid'])
	->condition('title', 'FIRST')
	->execute()
	->fetchField();
ok('ASCII case-insensitive match works via NOCASE', (int) $hit === 1, var_export($hit, true));

// --- transactions: commit and rollback -------------------------------------
echo "\nTransactions\n";
[$host, $connection] = connect();
$connection->query('CREATE TABLE {t} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$connection->query('CREATE TABLE {other} (id INTEGER PRIMARY KEY, v TEXT)');
$connection
	->insert('other')
	->fields(['id' => 1, 'v' => 'committed'])
	->execute();

$before = (int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
$transaction = $connection->startTransaction();
$connection
	->insert('t')
	->fields(['v' => 'buffered'])
	->execute();
ok(
	'write is withheld while the transaction is open',
	(int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === $before,
);
$transaction->commitOrRelease();
unset($transaction);
ok(
	'commit replays the buffer',
	(int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === $before + 1,
);

$before = (int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
$transaction = $connection->startTransaction();
$connection
	->insert('t')
	->fields(['v' => 'doomed'])
	->execute();
$transaction->rollBack();
unset($transaction);
ok(
	'rollback writes nothing',
	(int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === $before,
);

// read-your-writes
$transaction = $connection->startTransaction();
$connection
	->insert('t')
	->fields(['v' => 'seen-by-its-own-transaction'])
	->execute();
$seen = $connection
	->query("SELECT v FROM {t} WHERE v = 'seen-by-its-own-transaction'")
	->fetchField();
ok(
	'a read sees the buffered write',
	$seen === 'seen-by-its-own-transaction',
	var_export($seen, true),
);
$clean = $connection->query('SELECT v FROM {other} WHERE id = 1')->fetchField();
ok(
	'a read of an untouched table passes straight through',
	$clean === 'committed',
	var_export($clean, true),
);
$bufferedId = $connection->lastInsertId();
ok(
	'lastInsertId resolves for a buffered insert',
	$bufferedId !== '0' && ctype_digit($bufferedId),
	var_export($bufferedId, true),
);
$transaction->rollBack();
unset($transaction);
ok(
	'the speculative read did not commit anything',
	$connection
		->query("SELECT COUNT(*) FROM {t} WHERE v = 'seen-by-its-own-transaction'")
		->fetchField() === '0',
);

// buffered row count
$connection
	->insert('t')
	->fields(['v' => 'a'])
	->execute();
$connection
	->insert('t')
	->fields(['v' => 'a'])
	->execute();
$transaction = $connection->startTransaction();
$changed = $connection
	->update('t')
	->fields(['v' => 'b'])
	->condition('v', 'a')
	->execute();
ok('rowCount of a buffered update resolves', $changed === 2, var_export($changed, true));
$transaction->rollBack();
unset($transaction);
ok(
	'resolving the row count did not commit it',
	(int) $host->pdo->query("SELECT COUNT(*) FROM t WHERE v = 'a'")->fetchColumn() === 2,
);

// savepoints
$transaction = $connection->startTransaction();
$connection
	->insert('t')
	->fields(['v' => 'keep'])
	->execute();
$savepoint = $connection->startTransaction();
$connection
	->insert('t')
	->fields(['v' => 'discard'])
	->execute();
$savepoint->rollBack();
unset($savepoint);
$transaction->commitOrRelease();
unset($transaction);
$kept = (int) $host->pdo->query("SELECT COUNT(*) FROM t WHERE v = 'keep'")->fetchColumn();
$discarded = (int) $host->pdo->query("SELECT COUNT(*) FROM t WHERE v = 'discard'")->fetchColumn();
ok('savepoint rollback keeps the outer write', $kept === 1, "kept=$kept");
ok('savepoint rollback discards the inner write', $discarded === 0, "discarded=$discarded");

// failure inside the replay rolls the whole commit back
[$host, $connection] = connect();
$connection->query('CREATE TABLE {t} (id INTEGER PRIMARY KEY, v TEXT)');
$transaction = $connection->startTransaction();
$connection->query('INSERT INTO {t} (id, v) VALUES (1, :v)', [':v' => 'one']);
// the same primary key, so the second statement genuinely fails during replay
$connection->query('INSERT INTO {t} (id, v) VALUES (1, :v)', [':v' => 'clash']);
$threw = false;
try {
	$transaction->commitOrRelease();
} catch (\Exception $e) {
	$threw = true;
}
ok('a failed replay throws', $threw);
ok(
	'a failed replay commits nothing',
	(int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 0,
);
// core leaves the stack item in place after a failed commit, for every driver;
// clearing it here is harness hygiene, not driver behaviour
$connection->transactionManager()->voidClientTransaction();
unset($transaction);

// --- host without the transaction entry point ------------------------------
echo "\nDegraded host (no cfwSqlTxn)\n";
[$host, $connection] = connect(false);
ok('atomic commit is reported unavailable', $connection->supportsAtomicCommit() === false);
$connection->query('CREATE TABLE {t} (id INTEGER PRIMARY KEY, v TEXT)');
$connection->query('CREATE TABLE {u} (id INTEGER PRIMARY KEY, v TEXT)');
$connection->query('CREATE TABLE {other} (id INTEGER PRIMARY KEY, v TEXT)');
$connection
	->insert('other')
	->fields(['id' => 1, 'v' => 'committed'])
	->execute();
$transaction = $connection->startTransaction();
// AN UNPREDICTABLE INSERT, chosen deliberately. This block used to insert
// `(id, v) VALUES (1, ...)`, which supplies its own rowid, and a plain append is predicted by
// arithmetic -- so neither shape provokes the refusal it is here to assert. A conditional UPDATE
// after the insert blocks the table, which leaves a replay as the only way to learn the id, and a
// replay is exactly what a host with no `cfwSqlTxn` cannot do
$connection->query('INSERT INTO {t} (v) VALUES (:v)', [':v' => 'x']);
$connection->query('UPDATE {t} SET v = :v WHERE v = :old', [':v' => 'x2', ':old' => 'x']);
$clean = $connection->query('SELECT v FROM {other} WHERE id = 1')->fetchField();
ok('a clean read still works', $clean === 'committed');
$refused = false;
try {
	$connection->query('SELECT v FROM {t} WHERE id = 1')->fetchField();
} catch (UncommittedStateException $e) {
	$refused = str_contains($e->getMessage(), 'cfwSqlTxn');
}
ok('a dirty read is refused rather than answered wrongly', $refused);
$refusedId = false;
try {
	$connection->lastInsertId();
} catch (UncommittedStateException $e) {
	$refusedId = true;
}
ok('a buffered lastInsertId is refused rather than guessed', $refusedId);
// Insert::execute() always returns lastInsertId(), so the whole query builder is
// unusable inside a transaction on a host without the transaction entry point
$builderRefused = false;
try {
	$connection
		->insert('t')
		->fields(['v' => 'y'])
		->execute();
} catch (UncommittedStateException $e) {
	$builderRefused = true;
}
ok('the insert query builder cannot run in a transaction on a degraded host', $builderRefused);
// and what the refusal above is really about is the REPLAY, not the host: an insert naming its own
// id is answerable with no transaction entry point at all. On `u` rather than `t`, because the
// conditional UPDATE above blocks `t` for everything after it
$suppliedOnDegraded = $connection
	->insert('u')
	->fields(['id' => 9, 'v' => 'supplied'])
	->execute();
ok(
	'but a supplied rowid is answered even on a degraded host, because nothing has to be replayed',
	(string) $suppliedOnDegraded === '9',
	(string) $suppliedOnDegraded,
);
$transaction->commitOrRelease();
unset($transaction);
// two rows: the append, and the refused builder insert -- already buffered before its id was asked
// for, so committing keeps it
ok(
	'the degraded commit still replays',
	(int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 2,
	(string) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(),
);

// --- errors ----------------------------------------------------------------
echo "\nError mapping\n";
[$host, $connection] = connect();
$connection->query('CREATE TABLE {t} (id INTEGER PRIMARY KEY, v TEXT NOT NULL)');
$connection
	->insert('t')
	->fields(['id' => 1, 'v' => 'one'])
	->execute();
$mapped = null;
try {
	$connection
		->insert('t')
		->fields(['id' => 1, 'v' => 'again'])
		->execute();
} catch (\Exception $e) {
	$mapped = $e;
}
ok(
	'a unique collision maps to IntegrityConstraintViolationException',
	$mapped instanceof IntegrityConstraintViolationException,
	get_debug_type($mapped),
);
$notNull = null;
try {
	$connection->query('INSERT INTO {t} (id, v) VALUES (9, NULL)');
} catch (\Exception $e) {
	$notNull = $e;
}
ok(
	'a NOT NULL violation maps too',
	$notNull instanceof IntegrityConstraintViolationException,
	get_debug_type($notNull),
);
$syntax = null;
try {
	$connection->query('SELECT * FROM {nope}');
} catch (\Exception $e) {
	$syntax = $e;
}
ok(
	'a missing table stays a plain database error',
	$syntax !== null && !($syntax instanceof IntegrityConstraintViolationException),
	get_debug_type($syntax),
);
$control = false;
try {
	$connection->query('BEGIN', [], ['allow_delimiter_in_query' => true]);
} catch (UncommittedStateException $e) {
	$control = true;
}
ok('transaction control SQL is refused by the driver', $control);

// --- wide integers ---------------------------------------------------------
echo "\nWide integers\n";
[$host, $connection] = connect();
$connection->query('CREATE TABLE {t} (id INTEGER PRIMARY KEY, big INTEGER)');
$connection
	->insert('t')
	->fields(['id' => 1, 'big' => '9223372036854775807'])
	->execute();
$host->envelopeColumns = ['big' => '99999999999999999999999'];
$big = $connection->query('SELECT big FROM {t} WHERE id = 1')->fetchField();
ok(
	'an integer too wide for the platform arrives as exact digits',
	$big === '99999999999999999999999',
	var_export($big, true),
);
$host->envelopeColumns = [];
$stored = $connection->query('SELECT big FROM {t} WHERE id = 1')->fetchField();
ok(
	'a 64-bit value stored from a decimal string comes back exact',
	$stored === '9223372036854775807',
	var_export($stored, true),
);
$roundTrip = $connection
	->query('SELECT id FROM {t} WHERE big = :b', [':b' => '9223372036854775807'])
	->fetchField();
ok(
	'a wide value read out as a string binds back and matches',
	(int) $roundTrip === 1,
	var_export($roundTrip, true),
);
// core refuses an array argument before the driver sees it, so a codec envelope
// cannot travel through Connection::query(); the string form above is the path
$envelopeRefused = false;
try {
	$connection->query('SELECT id FROM {t} WHERE big = :b', [
		':b' => ['__phpint' => '9223372036854775807'],
	]);
} catch (\InvalidArgumentException $e) {
	$envelopeRefused = true;
}
ok('core rejects a codec envelope as a query argument', $envelopeRefused);

// --- schema and the heavier query builders ---------------------------------
echo "\nSchema and query builders\n";
[$host, $connection] = connect();
$schema = $connection->schema();
$schema->createTable('node', [
	'fields' => [
		'nid' => ['type' => 'serial', 'not null' => true],
		'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'binary' => false],
	],
	'primary key' => ['nid'],
]);
$connection
	->insert('node')
	->fields(['title' => 'one'])
	->execute();
$connection
	->insert('node')
	->fields(['title' => 'two'])
	->execute();

$tables = $schema->findTables('%');
ok('findTables sees the table', isset($tables['node']), implode(',', array_keys($tables)));
ok(
	'fieldExists introspects columns',
	$schema->fieldExists('node', 'title') && !$schema->fieldExists('node', 'nope'),
);

// a NOT NULL column with no default forces the create-copy-drop-rename rebuild
$schema->addField('node', 'status', ['type' => 'int', 'not null' => true, 'initial' => 1]);
ok('addField rebuilt the table', $schema->fieldExists('node', 'status'));
ok(
	'the rebuild kept the rows',
	(int) $connection->query('SELECT COUNT(*) FROM {node}')->fetchField() === 2,
);
ok(
	'the rebuild applied the initial value',
	(int) $connection->query('SELECT status FROM {node} WHERE nid = 1')->fetchField() === 1,
);

$schema->addIndex(
	'node',
	'node_title',
	['title'],
	['fields' => ['title' => ['type' => 'varchar', 'length' => 255]]],
);
ok('addIndex works', $schema->indexExists('node', 'node_title'));
$schema->dropIndex('node', 'node_title');
ok('dropIndex works', !$schema->indexExists('node', 'node_title'));

$connection
	->upsert('node')
	->key('nid')
	->fields(['nid', 'title', 'status'])
	->values([1, 'one-upserted', 1])
	->values([9, 'nine-inserted', 1])
	->execute();
ok(
	'upsert updated the existing row',
	$connection->query('SELECT title FROM {node} WHERE nid = 1')->fetchField() === 'one-upserted',
);
ok(
	'upsert inserted the new row',
	$connection->query('SELECT title FROM {node} WHERE nid = 9')->fetchField() === 'nine-inserted',
);

$connection
	->merge('node')
	->key('nid', 9)
	->fields(['title' => 'nine-merged', 'status' => 0])
	->execute();
ok(
	'merge updates through the upsert path',
	$connection->query('SELECT title FROM {node} WHERE nid = 9')->fetchField() === 'nine-merged',
);

// #region the 100-bound-parameter ceiling
//
// Durable Object SQLite refuses a statement with more than 100 bound parameters: 100 works,
// 101 throws "too many SQL variables". Bisected against a real ctx.storage.sql, and an
// earlier claim in TECHNICAL_REPORT.md that the limit does not exist here is refuted.
//
// This is the cache write path, not a corner case. DatabaseBackend::setMultiple() upserts in
// chunks of MAX_ITEMS_PER_CACHE_SET = 100 rows over 7 columns -- 700 placeholders, so core's
// own chunking is 7x over -- and core's sqlite Upsert emits ONE multi-row statement. A cold
// cache_discovery write made every render 500.
$wideStart = count($host->statements);
$wide = $connection
	->upsert('node')
	->key('nid')
	->fields(['nid', 'title', 'status']);
for ($i = 100; $i < 160; $i++) {
	$wide->values([$i, 'row-' . $i, 1]);
}
$wide->execute();
$wideStatements = array_slice($host->statements, $wideStart);

ok(
	'a 60-row x 3-field upsert (180 placeholders) lands every row',
	(int) $connection->query('SELECT COUNT(*) FROM {node} WHERE nid >= 100')->fetchField() === 60,
	(string) $connection->query('SELECT COUNT(*) FROM {node} WHERE nid >= 100')->fetchField(),
);
// the property that matters is per-statement width, not how many statements it took.
// Counted on the NAMED form: placeholders are still `:db_insert_placeholder_N` at this
// boundary and only become positional `?` in the JS bridge, so counting `?` here reads 0
// and the assertion would pass while measuring nothing
$widest = 0;
foreach ($wideStatements as $sql) {
	$text = (string) $sql;
	$widest = max(
		$widest,
		substr_count($text, ':db_insert_placeholder_'),
		substr_count($text, '?'),
	);
}
ok(
	'no statement carries more than 100 bound parameters',
	$widest > 0 && $widest <= Upsert::MAX_PLACEHOLDERS,
	"widest was $widest",
);
ok(
	'it was split rather than sent as one statement',
	count($wideStatements) > 1,
	count($wideStatements) . ' statements',
);
// re-running the same values must update rather than duplicate, so the split did not break
// ON CONFLICT
$connection
	->upsert('node')
	->key('nid')
	->fields(['nid', 'title', 'status'])
	->values([100, 'row-100-again', 0])
	->execute();
ok(
	'a split upsert still upserts: a repeat updates rather than duplicating',
	$connection->query('SELECT title FROM {node} WHERE nid = 100')->fetchField() ===
		'row-100-again' &&
		(int) $connection->query('SELECT COUNT(*) FROM {node} WHERE nid = 100')->fetchField() === 1,
);
// a single narrow upsert must not acquire a transaction it does not need
ok(
	'the common one-row upsert still returns an affected-row count',
	$connection
		->upsert('node')
		->key('nid')
		->fields(['nid', 'title', 'status'])
		->values([200, 'single', 1])
		->execute() !== null,
);
$connection->delete('node')->condition('nid', 100, '>=')->execute();
// #endregion
$ranged = $connection->queryRange('SELECT nid FROM {node} ORDER BY nid', 1, 1, [])->fetchCol();
ok(
	'queryRange limits and offsets',
	count($ranged) === 1 && (int) $ranged[0] === 2,
	implode(',', $ranged),
);

$connection->delete('node')->condition('nid', 9)->execute();
ok('delete works', (int) $connection->query('SELECT COUNT(*) FROM {node}')->fetchField() === 2);
$connection->truncate('node')->execute();
ok(
	'truncate empties the table',
	(int) $connection->query('SELECT COUNT(*) FROM {node}')->fetchField() === 0,
);

// DDL inside a transaction: buffered, and reads of it need the replay
$transaction = $connection->startTransaction();
$connection->query('CREATE TABLE {late} (id INTEGER PRIMARY KEY)');
ok(
	'buffered DDL is not visible to the host yet',
	$host->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'late'")->fetchColumn() ===
		0,
);
ok('tableExists sees buffered DDL through the replay', $schema->tableExists('late'));
$transaction->rollBack();
unset($transaction);
ok(
	'rolled-back DDL left no table',
	$host->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'late'")->fetchColumn() ===
		0,
);
ok('and Drupal agrees the table is gone', !$schema->tableExists('late'));

$transaction = $connection->startTransaction();
$connection->query('CREATE TABLE {kept} (id INTEGER PRIMARY KEY)');
$transaction->commitOrRelease();
unset($transaction);
ok('committed DDL creates the table', $schema->tableExists('kept'));

// --- SQL function substitution ---------------------------------------------
echo "\nFunction substitution\n";
ok(
	'GREATEST becomes max',
	SqlAnalyzer::rewriteFunctions('SELECT GREATEST(a, b) FROM "t"') === 'SELECT max(a, b) FROM "t"',
	SqlAnalyzer::rewriteFunctions('SELECT GREATEST(a, b) FROM "t"'),
);
ok('LEAST becomes min', SqlAnalyzer::rewriteFunctions('SELECT LEAST(a,b)') === 'SELECT min(a,b)');
ok(
	'RAND becomes random',
	SqlAnalyzer::rewriteFunctions('SELECT RAND() AS r') === 'SELECT random() AS r',
);
ok('IF becomes iif', SqlAnalyzer::rewriteFunctions('SELECT IF(a, 1, 2)') === 'SELECT iif(a, 1, 2)');
ok(
	'a literal is not rewritten',
	SqlAnalyzer::rewriteFunctions("SELECT 'GREATEST(x)' AS a") === "SELECT 'GREATEST(x)' AS a",
);
ok(
	'a comment is not rewritten',
	SqlAnalyzer::rewriteFunctions('SELECT a /* GREATEST(x) */ FROM "t"') ===
		'SELECT a /* GREATEST(x) */ FROM "t"',
);
ok(
	'a quoted identifier is not rewritten',
	SqlAnalyzer::rewriteFunctions('SELECT "rand" FROM "t"') === 'SELECT "rand" FROM "t"',
);
ok(
	'random( is not re-rewritten',
	SqlAnalyzer::rewriteFunctions('SELECT random()') === 'SELECT random()',
);
ok(
	'a word ending in if is left alone',
	SqlAnalyzer::rewriteFunctions('SELECT notify(a)') === 'SELECT notify(a)',
);
ok(
	'SUBSTRING is left alone (builtin)',
	SqlAnalyzer::rewriteFunctions('SELECT SUBSTRING(a,1,2)') === 'SELECT SUBSTRING(a,1,2)',
);
ok(
	'MD5 is left alone (no equivalent)',
	SqlAnalyzer::rewriteFunctions('SELECT MD5(a)') === 'SELECT MD5(a)',
);

[$host, $connection] = connect();
$connection->query('CREATE TABLE {t} (a INTEGER, b INTEGER)');
$connection->query('INSERT INTO {t} (a, b) VALUES (1, 5)');
$greatest = $connection->query('SELECT GREATEST([a], [b]) AS g FROM {t}')->fetchField();
ok('GREATEST runs end to end', (int) $greatest === 5, var_export($greatest, true));
$least = $connection->query('SELECT LEAST([a], [b]) AS l FROM {t}')->fetchField();
ok('LEAST runs end to end', (int) $least === 1, var_export($least, true));
$random = $connection
	->select('t', 't')
	->fields('t', ['a'])
	->orderRandom()
	->execute()
	->fetchAll();
ok('orderRandom runs end to end', count($random) === 1);
$literal = $connection->query("SELECT 'GREATEST(' AS lit")->fetchField();
ok(
	'a literal survives the rewrite end to end',
	$literal === 'GREATEST(',
	var_export($literal, true),
);

// Insert::execute() with several rows opens its own transaction, reuses one
// statement object across the rows, and asks for the id after committing
$multi = $connection->insert('t')->fields(['a', 'b']);
$multi
	->values([10, 11])
	->values([12, 13])
	->values([14, 15]);
$multiId = $multi->execute();
ok(
	'a multi-row insert lands every row',
	(int) $connection->query('SELECT COUNT(*) FROM {t} WHERE [a] >= 10')->fetchField() === 3,
);
ok(
	'a multi-row insert reports an id after its own commit',
	$multiId !== null && ctype_digit((string) $multiId),
	var_export($multiId, true),
);
ok('the buffer is closed again afterwards', $connection->isBuffering() === false);

ok(
	'plain LIKE is still mapped with an ESCAPE clause',
	($m = $connection->mapConditionOperator('LIKE')) !== null &&
		str_contains($m['postfix'], 'ESCAPE'),
	var_export($connection->mapConditionOperator('LIKE'), true),
);

// --- LIKE BINARY -----------------------------------------------------------
//
// This used to throw. It is mapped onto builtin GLOB now, with the pattern
// translated from LIKE syntax in PHP, because the two wildcard languages
// disagree in BOTH directions and disagree silently: % and _ are literals under
// GLOB, while *, ? and [ are wildcards under GLOB and literals under LIKE.
echo "\nLIKE BINARY\n";
$lbMap = $connection->mapConditionOperator('LIKE BINARY');
ok('LIKE BINARY maps to GLOB', ($lbMap['operator'] ?? null) === 'GLOB', var_export($lbMap, true));
ok(
	'NOT LIKE BINARY maps to NOT GLOB',
	($connection->mapConditionOperator('NOT LIKE BINARY')['operator'] ?? null) === 'NOT GLOB',
);
// core appends " ESCAPE '\'", and `GLOB ? ESCAPE '\'` is a three-argument GLOB
// that the builtin refuses with "wrong number of arguments to function GLOB()"
ok(
	'the ESCAPE postfix core adds is dropped',
	($lbMap['postfix'] ?? 'x') === '',
	var_export($lbMap, true),
);
ok(
	'the pattern placeholder is marked',
	str_contains($lbMap['prefix'] ?? '', 'cfw:l2g'),
	var_export($lbMap, true),
);

ok(
	'likeToGlob maps % to *',
	SqlAnalyzer::likeToGlob('a%b') === 'a*b',
	SqlAnalyzer::likeToGlob('a%b'),
);
ok(
	'likeToGlob maps _ to ?',
	SqlAnalyzer::likeToGlob('a_b') === 'a?b',
	SqlAnalyzer::likeToGlob('a_b'),
);
ok(
	'likeToGlob quotes a literal *',
	SqlAnalyzer::likeToGlob('a*b') === 'a[*]b',
	SqlAnalyzer::likeToGlob('a*b'),
);
ok(
	'likeToGlob quotes a literal ?',
	SqlAnalyzer::likeToGlob('a?b') === 'a[?]b',
	SqlAnalyzer::likeToGlob('a?b'),
);
ok(
	'likeToGlob quotes a literal [',
	SqlAnalyzer::likeToGlob('a[b') === 'a[[]b',
	SqlAnalyzer::likeToGlob('a[b'),
);
ok(
	'likeToGlob leaves multibyte bytes alone',
	SqlAnalyzer::likeToGlob("\u{00FC}%") === "\u{00FC}*",
	SqlAnalyzer::likeToGlob("\u{00FC}%"),
);

// The differential. Core implements LIKE BINARY as a PHP callback registered on
// the connection, so parity with THAT is the contract -- not parity with MySQL.
// Its quirks are inherited on purpose: it runs preg_quote() over the pattern and
// then replaces % and _ unconditionally, so escapeLike()'s backslashes are
// literal backslashes to be matched, and there is no ESCAPE handling at all.
$differentialPdo = new \PDO('sqlite::memory:', null, null, [
	\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
$globStmt = $differentialPdo->prepare('SELECT (? GLOB ?) AS m');

// the same hostile alphabet and the same LCG as src/glob-differential.js, so the
// JS and PHP halves cover identical cases
$alphabet = ['a', 'b', '%', '_', '*', '?', '[', ']', '\\', '^', '-', '.'];
$seed = 1;
$rnd = function () use (&$seed) {
	$seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
	return $seed / 0x7fffffff;
};
$mkString = function (int $max) use ($rnd, $alphabet) {
	$n = (int) floor($rnd() * $max);
	$s = '';
	for ($i = 0; $i < $n; $i++) {
		$s .= $alphabet[(int) floor($rnd() * count($alphabet))];
	}
	return $s;
};

$cases = 9000;
$mismatches = [];
$matchedAtLeastOnce = 0;
for ($i = 0; $i < $cases; $i++) {
	$pattern = $mkString(5);
	$subject = $mkString(5);
	$globStmt->execute([$subject, SqlAnalyzer::likeToGlob($pattern)]);
	$viaGlob = (int) $globStmt->fetchColumn();
	$viaCore = (int) CoreSqliteConnection::sqlFunctionLikeBinary($pattern, $subject);
	if ($viaGlob !== $viaCore) {
		if (count($mismatches) < 5) {
			$mismatches[] = sprintf(
				'pattern=%s subject=%s glob=%d core=%d',
				var_export($pattern, true),
				var_export($subject, true),
				$viaGlob,
				$viaCore,
			);
		}
	}
	$matchedAtLeastOnce += $viaCore;
}
ok(
	sprintf('%d differential cases agree with core sqlFunctionLikeBinary()', $cases),
	$mismatches === [],
	implode(' | ', $mismatches),
);
// a differential over patterns that never match anything would pass vacuously
ok(
	'the differential corpus contains real matches',
	$matchedAtLeastOnce > 100,
	"matches: $matchedAtLeastOnce",
);

// the control: without the translation the two DO disagree, so a passing
// differential above is not measuring nothing
$untranslatedMismatch = false;
foreach ([['a%', 'ab'], ['a_', 'ab'], ['a*', 'a*']] as [$p, $s]) {
	$globStmt->execute([$s, $p]);
	if (
		(int) $globStmt->fetchColumn() !== (int) CoreSqliteConnection::sqlFunctionLikeBinary($p, $s)
	) {
		$untranslatedMismatch = true;
	}
}
ok('CONTROL: an untranslated pattern disagrees with core', $untranslatedMismatch);

// end to end, through Drupal's own query builder against the stand-in host
[$lbHost, $lbConnection] = connect();
$lbConnection->query('CREATE TABLE {lb} ([id] INTEGER PRIMARY KEY, [name] TEXT)');
foreach (['Alpha%Beta', 'Alpha*Beta', 'alphaxbeta', 'AlphaZBeta'] as $index => $name) {
	$lbConnection
		->insert('lb')
		->fields(['id' => $index + 1, 'name' => $name])
		->execute();
}
$lbCount = function (string $pattern, string $operator = 'LIKE BINARY') use ($lbConnection) {
	return (int) $lbConnection
		->select('lb', 'l')
		->condition('name', $pattern, $operator)
		->countQuery()
		->execute()
		->fetchField();
};
ok('LIKE BINARY runs instead of throwing', $lbCount('Alpha%') === 3, (string) $lbCount('Alpha%'));
ok('LIKE BINARY is case-sensitive', $lbCount('alpha%') === 1, (string) $lbCount('alpha%'));
ok(
	'LIKE BINARY _ matches exactly one character',
	$lbCount('Alpha_Beta') === 3,
	(string) $lbCount('Alpha_Beta'),
);
ok('LIKE BINARY matches a literal asterisk', $lbCount('%*%') === 1, (string) $lbCount('%*%'));
ok(
	'NOT LIKE BINARY negates',
	$lbCount('Alpha%', 'NOT LIKE BINARY') === 1,
	(string) $lbCount('Alpha%', 'NOT LIKE BINARY'),
);
ok(
	'the marker never reaches the host',
	!str_contains(implode(' ', $lbHost->statements), 'cfw:l2g'),
);

// The 50-byte ceiling, measured against real ctx.storage.sql: 50 bytes succeeds,
// 51 fails with "LIKE or GLOB pattern too complex". The driver refuses first so the
// message names the cause instead of surfacing an engine error from elsewhere.
ok(
	'MAX_LIKE_PATTERN_BYTES records the measured ceiling',
	Connection::MAX_LIKE_PATTERN_BYTES === 50,
	(string) Connection::MAX_LIKE_PATTERN_BYTES,
);

$tooLong = false;
$tooLongMessage = '';
try {
	$lbCount(str_repeat('a', 51));
} catch (InvalidQueryException $e) {
	$tooLong = true;
	$tooLongMessage = $e->getMessage();
}
ok('a 51-byte LIKE BINARY pattern is refused', $tooLong, $tooLongMessage);
ok('the refusal explains the ceiling', str_contains($tooLongMessage, '50 bytes'), $tooLongMessage);

$atLimit = true;
try {
	$lbCount(str_repeat('a', 50));
} catch (\Throwable $e) {
	$atLimit = false;
	$tooLongMessage = get_class($e) . ': ' . $e->getMessage();
}
ok('a 50-byte pattern is allowed through', $atLimit, $tooLongMessage);

// bracket-quoting expands a metacharacter threefold, so a SHORT input can become
// an over-length GLOB pattern; the check has to be on the translated form
ok('likeToGlob triples an asterisk', SqlAnalyzer::likeToGlob('**') === '[*][*]');
$expanded = false;
$expandedMessage = '';
try {
	$lbCount(str_repeat('*', 20));
} catch (InvalidQueryException $e) {
	$expanded = true;
	$expandedMessage = $e->getMessage();
}
ok('20 asterisks become a 60-byte GLOB pattern and are refused', $expanded, $expandedMessage);
ok(
	'the refusal reports the TRANSLATED length',
	str_contains($expandedMessage, '60 bytes'),
	$expandedMessage,
);

// an unbound marker must refuse rather than run an untranslated pattern
$markerRefused = false;
try {
	$lbConnection->query('SELECT [name] FROM {lb} WHERE [name] GLOB /*cfw:l2g*/:missing', [
		':other' => 'x',
	]);
} catch (\Throwable $e) {
	$markerRefused =
		$e instanceof InvalidQueryException || str_contains($e->getMessage(), 'not bound');
}
ok('a marked placeholder that is not bound refuses', $markerRefused);

// #region the cost counters, which the Cost table is built on
//
// These are assertions and not an instrumented run. The three figures in README's
// Cost table -- transactions opened, of which speculative, statements executed inside replays --
// came from an ad-hoc instrumented build and could not be reproduced from the shipping class.
// A replay cache is the next planned change to `readThroughReplay()`, and a cache with no
// counter is a change you cannot measure: statementCount() alone CANNOT see it, because a
// replay is one bridge crossing however many statements the host runs inside it.
//
// Everything below is a DELTA around a controlled block, so it does not depend on anything
// earlier in this file and cannot break when a test is inserted above it.
//
// Each driver counter is asserted against FakeHost's own count of the same thing. Two
// independent instruments agreeing is the proof; a counter asserted against itself is circular.
echo "\nCost counters\n";

// its OWN tables. `t` and `other` are recreated with different columns several times above this
// point, so borrowing them is how a self-contained region stops being self-contained
$connection->query('DROP TABLE IF EXISTS {cost}');
$connection->query('DROP TABLE IF EXISTS {costclean}');
$connection->query('CREATE TABLE {cost} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$connection->query('CREATE TABLE {costclean} (id INTEGER PRIMARY KEY, v TEXT)');
$connection
	->insert('costclean')
	->fields(['id' => 1, 'v' => 'committed'])
	->execute();

$before = [
	'statements' => $connection->statementCount(),
	'transactions' => $connection->transactionCount(),
	'speculative' => $connection->speculativeCount(),
	'replayed' => $connection->replayedStatementCount(),
	'hostTxn' => $host->txnCalls,
	'hostSpeculative' => $host->speculativeCalls,
	'hostReplayed' => $host->replayedStatements,
];

// one write, then one dirty read of the table it wrote, then commit. That is exactly one
// speculative replay of a 1-statement buffer, plus one committing transaction
$transaction = $connection->startTransaction();
$connection
	->insert('cost')
	->fields(['v' => 'counter-one'])
	->execute();
$connection->query("SELECT v FROM {cost} WHERE v = 'counter-one'")->fetchField();
$transaction->commitOrRelease();
unset($transaction);

$afterOne = [
	'transactions' => $connection->transactionCount() - $before['transactions'],
	'speculative' => $connection->speculativeCount() - $before['speculative'],
	'replayed' => $connection->replayedStatementCount() - $before['replayed'],
	'statements' => $connection->statementCount() - $before['statements'],
];

ok(
	'transactionCount() agrees with the host bridge it went through',
	$afterOne['transactions'] === $host->txnCalls - $before['hostTxn'],
	sprintf('%d driver, %d host', $afterOne['transactions'], $host->txnCalls - $before['hostTxn']),
);
ok(
	'speculativeCount() agrees with the host on which were rolled back',
	$afterOne['speculative'] === $host->speculativeCalls - $before['hostSpeculative'],
	sprintf(
		'%d driver, %d host',
		$afterOne['speculative'],
		$host->speculativeCalls - $before['hostSpeculative'],
	),
);
ok(
	'replayedStatementCount() agrees with what the host executed inside rollbacks',
	$afterOne['replayed'] === $host->replayedStatements - $before['hostReplayed'],
	sprintf(
		'%d driver, %d host',
		$afterOne['replayed'],
		$host->replayedStatements - $before['hostReplayed'],
	),
);
// MEASURED, not predicted, and these numbers have now moved TWICE. They first read 3/2/2: one
// speculative replay to resolve the buffered insert's id and one for the read. The id replay is
// gone -- `predictBufferedInsertId()` now derives an AUTOINCREMENT id from `sqlite_sequence`
// instead of refusing -- so the read is the only speculative transaction left.
ok(
	'a write, a dirty read and a commit is 2 host transactions',
	$afterOne['transactions'] === 2,
	(string) $afterOne['transactions'],
);
ok(
	'one of them is speculative: the read, since the insert id needs no replay now',
	$afterOne['speculative'] === 1,
	(string) $afterOne['speculative'],
);
ok('it replays 1 statement', $afterOne['replayed'] === 1, (string) $afterOne['replayed']);
ok(
	'speculativeCount() never exceeds transactionCount()',
	$afterOne['speculative'] <= $afterOne['transactions'],
	sprintf('%d of %d', $afterOne['speculative'], $afterOne['transactions']),
);

// #region the O(W*R) term, made visible
//
// This is the assertion the counters exist for. `readThroughReplay()` re-sends
// TransactionBuffer::statements() IN FULL for every dirty read, so N writes each followed by a
// read replay 1 + 2 + ... + N = N(N+1)/2 statements. At N=3 that is 6, from 3 writes.
//
// IT USED TO BE N(N+1), twice that, because each pair ALSO paid an id-resolution replay of the
// same buffer. Predicting an AUTOINCREMENT id removed the doubling; the triangular term is
// inherent to answering a read against a buffer and is still here.
//
// statementCount() cannot see the triangular term at all -- it counts bridge crossings, one per
// replay -- so the gap between the two numbers IS the finding, and it is why these are separate
// counters rather than one.
$midpoint = [
	'replayed' => $connection->replayedStatementCount(),
	'statements' => $connection->statementCount(),
	'speculative' => $connection->speculativeCount(),
	'hostReplayed' => $host->replayedStatements,
];

// N=4 rather than 3. At N=3 the triangular total is 6 and the crossing count is also 6, so the
// control below -- that the two counters are not derivable from each other -- read as a failure
// on a coincidence. The shapes differ; the sample size was hiding it
$transaction = $connection->startTransaction();
for ($i = 1; $i <= 4; $i++) {
	$connection
		->insert('cost')
		->fields(['v' => "wr-$i"])
		->execute();
	$connection->query("SELECT v FROM {cost} WHERE v = 'wr-$i'")->fetchField();
}
$transaction->commitOrRelease();
unset($transaction);

$grewReplayed = $connection->replayedStatementCount() - $midpoint['replayed'];
$grewStatements = $connection->statementCount() - $midpoint['statements'];
$grewSpeculative = $connection->speculativeCount() - $midpoint['speculative'];

ok(
	'four write-then-read pairs make FOUR speculative replays, one per pair',
	$grewSpeculative === 4,
	(string) $grewSpeculative,
);
ok(
	'they re-send 10 statements, which is N(N+1)/2 and no longer N(N+1)',
	$grewReplayed === 10,
	(string) $grewReplayed,
);
ok(
	'statementCount() cannot see that, rising by 7 over the same block',
	$grewStatements === 7,
	(string) $grewStatements,
);
// the control: if the two counters moved together, one of them would be redundant and a replay
// cache could be "proved" to work by the wrong number
ok(
	'CONTROL: the replay counter and the statement counter disagree, so both are needed',
	$grewReplayed !== $grewStatements,
	sprintf('%d replayed, %d statements', $grewReplayed, $grewStatements),
);
ok(
	'the host agrees on the replayed total for this block too',
	$grewReplayed === $host->replayedStatements - $midpoint['hostReplayed'],
	sprintf(
		'%d driver, %d host',
		$grewReplayed,
		$host->replayedStatements - $midpoint['hostReplayed'],
	),
);
// #endregion
// a read of an untouched table must NOT open a speculative transaction, or every read inside a
// transaction would pay for a replay and the counter would be measuring the wrong thing.
// Still isolated by DIFFERENCE even though a bare insert now costs zero: the difference is what
// attributes a cost to the READ, and it stays correct whatever the insert costs
$insertOnlyBefore = $connection->speculativeCount();
$transaction = $connection->startTransaction();
$connection
	->insert('cost')
	->fields(['v' => 'insert-only'])
	->execute();
$transaction->commitOrRelease();
unset($transaction);
$insertOnly = $connection->speculativeCount() - $insertOnlyBefore;

$withCleanReadBefore = $connection->speculativeCount();
$transaction = $connection->startTransaction();
$connection
	->insert('cost')
	->fields(['v' => 'untouched-probe'])
	->execute();
$connection->query('SELECT v FROM {costclean} WHERE id = 1')->fetchField();
$transaction->commitOrRelease();
unset($transaction);
$withCleanRead = $connection->speculativeCount() - $withCleanReadBefore;

ok(
	'a buffered insert into an AUTOINCREMENT table now costs NO speculative replay',
	$insertOnly === 0,
	(string) $insertOnly,
);
ok(
	'a read of an UNTOUCHED table adds nothing on top of it',
	$withCleanRead === $insertOnly,
	sprintf('%d with the read, %d without', $withCleanRead, $insertOnly),
);
// the control: a read of the WRITTEN table does add one, so the assertion above is not vacuous
$dirtyReadBefore = $connection->speculativeCount();
$transaction = $connection->startTransaction();
$connection
	->insert('cost')
	->fields(['v' => 'dirty-probe'])
	->execute();
$connection->query("SELECT v FROM {cost} WHERE v = 'dirty-probe'")->fetchField();
$transaction->commitOrRelease();
unset($transaction);
$withDirtyRead = $connection->speculativeCount() - $dirtyReadBefore;
ok(
	'CONTROL: a read of the written table DOES add one, so the check above has teeth',
	$withDirtyRead === $insertOnly + 1,
	sprintf('%d with a dirty read, %d without', $withDirtyRead, $insertOnly),
);
// #endregion
// #region the replay cache
//
// What it closes and what it does not, because the second half is easy to
// overclaim. A replay always starts from the same committed state and runs buffer[0..k] in
// order, so the result of statement i depends on the buffer's first i+1 entries and nothing
// else -- and those never change while they are buffered. So an answer learned once is still
// true later, and every replay hands back one result per statement it ran.
//
// That removes REPEATED resolutions. It does NOT remove the first resolution of a newly
// buffered statement, because the newest index is the one no earlier replay covered, and it
// does not remove a dirty read, which has to be evaluated inside a transaction with the buffer
// applied. The assertions below say which is which rather than quoting one number.
echo "\nReplay cache\n";

[$cacheHost, $cacheConnection] = connect();
$cacheConnection->query('CREATE TABLE {cache} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$cacheConnection->query('CREATE TABLE {cacheother} (id INTEGER PRIMARY KEY, v TEXT)');
$cacheConnection
	->insert('cacheother')
	->fields(['id' => 1, 'v' => 'committed'])
	->execute();

$cacheTransaction = $cacheConnection->startTransaction();
$cacheConnection
	->insert('cache')
	->fields(['v' => 'first'])
	->execute();

// Insert::execute() already resolved this id once; asking again must not open a second
// host transaction
$repeatBefore = [
	'speculative' => $cacheConnection->speculativeCount(),
	'replayed' => $cacheConnection->replayedStatementCount(),
	'hostSpeculative' => $cacheHost->speculativeCalls,
	'hostReplayed' => $cacheHost->replayedStatements,
];
$repeatId = $cacheConnection->lastInsertId();
ok(
	'a repeated lastInsertId() opens no host transaction',
	$cacheConnection->speculativeCount() === $repeatBefore['speculative'],
	sprintf(
		'%d before, %d after',
		$repeatBefore['speculative'],
		$cacheConnection->speculativeCount(),
	),
);
ok(
	'and the host agrees it was never entered',
	$cacheHost->speculativeCalls === $repeatBefore['hostSpeculative'],
	sprintf('%d before, %d after', $repeatBefore['hostSpeculative'], $cacheHost->speculativeCalls),
);
ok(
	'so it re-sends no statements either',
	$cacheHost->replayedStatements === $repeatBefore['hostReplayed'],
	sprintf('%d before, %d after', $repeatBefore['hostReplayed'], $cacheHost->replayedStatements),
);
ok(
	'the cached id is the id the replay reported',
	$repeatId !== '0' && ctype_digit($repeatId),
	var_export($repeatId, true),
);

// CONTROL: a resolution the PREDICTOR refuses still costs one, so the three assertions above are
// about the cache rather than about lastInsertId() having become free everywhere.
//
// The shape has to be one prediction cannot answer, and an unconditional DELETE is exactly that
// for an AUTOINCREMENT table: it empties the rows and leaves `sqlite_sequence` alone, so the next
// id depends on whether that row was cleared too -- which the buffer does not track. A plain
// insert here would now be predicted and the control would pass on a vacuous zero.
$cacheConnection->query('DELETE FROM {cache}');
$freshBefore = $cacheConnection->speculativeCount();
$cacheConnection
	->insert('cache')
	->fields(['v' => 'second'])
	->execute();
ok(
	'CONTROL: an insert the predictor refuses still costs one speculative replay',
	$cacheConnection->speculativeCount() === $freshBefore + 1,
	sprintf('%d before, %d after', $freshBefore, $cacheConnection->speculativeCount()),
);
$cacheTransaction->rollBack();
unset($cacheTransaction);

// the case worth the most: a dirty read replays the WHOLE buffer, so it answers every
// outstanding row count as a side effect of work already being paid for. Statement::rowCount()
// is deferred by design, which is what makes this reachable through the public API rather than
// only by calling resolveBufferedRowCount() directly
$cacheConnection
	->insert('cache')
	->fields(['v' => 'row-a'])
	->execute();
$cacheConnection
	->insert('cache')
	->fields(['v' => 'row-b'])
	->execute();

$cacheTransaction = $cacheConnection->startTransaction();
$deferred = $cacheConnection->prepareStatement(
	'UPDATE {cache} SET [v] = :v WHERE [v] LIKE :like',
	[],
	true,
);
$deferred->execute([':v' => 'row-updated', ':like' => 'row-%']);
$cacheConnection->query("SELECT [v] FROM {cache} WHERE [v] = 'row-updated'")->fetchField();

$deferredBefore = [
	'speculative' => $cacheConnection->speculativeCount(),
	'hostSpeculative' => $cacheHost->speculativeCalls,
];
$deferredRows = $deferred->rowCount();
ok(
	'a deferred rowCount() after a dirty read is free',
	$cacheConnection->speculativeCount() === $deferredBefore['speculative'],
	sprintf(
		'%d before, %d after',
		$deferredBefore['speculative'],
		$cacheConnection->speculativeCount(),
	),
);
ok(
	'and the host was not entered for it',
	$cacheHost->speculativeCalls === $deferredBefore['hostSpeculative'],
	sprintf(
		'%d before, %d after',
		$deferredBefore['hostSpeculative'],
		$cacheHost->speculativeCalls,
	),
);
ok(
	'the free answer is the right answer: 2 rows would change',
	$deferredRows === 2,
	(string) $deferredRows,
);
$cacheTransaction->rollBack();
unset($cacheTransaction);

// CONTROL for the same shape: with no dirty read to populate it, the deferred rowCount pays
$cacheTransaction = $cacheConnection->startTransaction();
$uncached = $cacheConnection->prepareStatement(
	'UPDATE {cache} SET [v] = :v WHERE [v] LIKE :like',
	[],
	true,
);
$uncached->execute([':v' => 'row-updated', ':like' => 'row-%']);
$uncachedBefore = $cacheConnection->speculativeCount();
$uncachedRows = $uncached->rowCount();
ok(
	'CONTROL: without a dirty read first, the same rowCount() costs one replay',
	$cacheConnection->speculativeCount() === $uncachedBefore + 1,
	sprintf('%d before, %d after', $uncachedBefore, $cacheConnection->speculativeCount()),
);
ok('CONTROL: and reports the same 2 rows', $uncachedRows === 2, (string) $uncachedRows);
$cacheTransaction->rollBack();
unset($cacheTransaction);

// A savepoint rollback is the one way the buffer shrinks, so it is the one way a cached answer
// can go stale: a fresh statement written at a discarded index would otherwise inherit the
// discarded one's row count. The two updates below change a different number of rows on
// purpose, so a stale answer is visible rather than merely possible.
$cacheConnection
	->insert('cache')
	->fields(['v' => 'sp-1'])
	->execute();
$cacheConnection
	->insert('cache')
	->fields(['v' => 'sp-2'])
	->execute();

$cacheTransaction = $cacheConnection->startTransaction();
$savepoint = $cacheConnection->startTransaction();
$wide = $cacheConnection->prepareStatement(
	'UPDATE {cache} SET [v] = :v WHERE [v] LIKE :like',
	[],
	true,
);
$wide->execute([':v' => 'sp-wide', ':like' => 'sp-%']);
ok(
	'the discarded write would have changed 2 rows',
	$wide->rowCount() === 2,
	(string) $wide->rowCount(),
);
$savepoint->rollBack();
unset($savepoint);

$narrow = $cacheConnection->prepareStatement(
	'UPDATE {cache} SET [v] = :v WHERE [v] = :match',
	[],
	true,
);
$narrow->execute([':v' => 'sp-narrow', ':match' => 'sp-1']);
ok(
	'a statement written at the discarded index does NOT inherit its row count',
	$narrow->rowCount() === 1,
	(string) $narrow->rowCount(),
);
$cacheTransaction->rollBack();
unset($cacheTransaction);
// #endregion
// #region predicting a buffered rowid instead of replaying for it
//
// SQLite gives an ordinary rowid table max(rowid) + 1, so the answer is arithmetic over state
// the driver already holds. What makes that safe rather than clever is the refusal: every shape
// RowidPlan cannot read falls back to the replay that was always there, so the failure mode is
// slow rather than wrong. The AUTOINCREMENT control below is the proof these assertions have
// teeth -- it runs the identical block on a serial table and still pays.
echo "\nPredicted rowids\n";

[$rowidHost, $rowidConnection] = connect();
// the router's own shape: a text primary key and no serial column
$rowidConnection->query('CREATE TABLE {rt} (name VARCHAR(255) PRIMARY KEY, path VARCHAR(255))');
$rowidConnection->query('CREATE TABLE {rtserial} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$rowidConnection->query('CREATE TABLE {rtchosen} (id INTEGER PRIMARY KEY, v TEXT)');
// seeded so the committed maximum is not zero, which is the case a delete-all has to override
for ($i = 1; $i <= 4; $i++) {
	$rowidConnection
		->insert('rt')
		->fields(['name' => "seed.$i", 'path' => "/seed/$i"])
		->execute();
}

/**
 * Runs the dumper's shape: one transaction, a delete-all, then chunked inserts.
 *
 * @param \Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\Connection $connection
 *   The connection to drive.
 * @param string $table
 *   The table to rebuild.
 * @param int $routes
 *   How many rows to write.
 * @param int $chunk
 *   Rows per Insert::execute(), which is where lastInsertId() is asked for.
 *
 * @return array{speculative: int, replayed: int, transactions: int, lastId: string}
 *   The deltas over the block, and the id the final chunk reported.
 */
function rebuild(Connection $connection, string $table, int $routes, int $chunk): array
{
	$before = [
		'speculative' => $connection->speculativeCount(),
		'replayed' => $connection->replayedStatementCount(),
		'transactions' => $connection->transactionCount(),
	];

	$transaction = $connection->startTransaction();
	$connection->delete($table)->execute();
	$lastId = '';
	for ($offset = 0; $offset < $routes; $offset += $chunk) {
		$insert = $connection->insert($table)->fields(['name', 'path']);
		for ($i = $offset; $i < min($routes, $offset + $chunk); $i++) {
			$insert->values(['name' => "route.$i", 'path' => "/route/$i"]);
		}
		$lastId = (string) $insert->execute();
	}
	$transaction->commitOrRelease();
	unset($transaction);

	return [
		'speculative' => $connection->speculativeCount() - $before['speculative'],
		'replayed' => $connection->replayedStatementCount() - $before['replayed'],
		'transactions' => $connection->transactionCount() - $before['transactions'],
		'lastId' => $lastId,
	];
}

$rebuilt = rebuild($rowidConnection, 'rt', 20, 5);

// ONE replay survives and it is not an insert: `Delete::execute()` returns affected rows, and a
// buffered delete's count is a question only the engine can answer. It replays a one-statement
// buffer once per rebuild, so it is constant where the insert ids were quadratic. Predicting it
// would mean deciding what `changes()` reports after SQLite's truncate optimisation, which is
// not something to settle against a PDO stand-in.
ok(
	'a router-shaped rebuild opens ONE speculative transaction, for the delete row count',
	$rebuilt['speculative'] === 1,
	(string) $rebuilt['speculative'],
);
ok(
	'and re-sends ONE statement, where four chunks of five used to re-send 55',
	$rebuilt['replayed'] === 1,
	(string) $rebuilt['replayed'],
);
ok(
	'so it opens two host transactions: that one and the commit',
	$rebuilt['transactions'] === 2,
	(string) $rebuilt['transactions'],
);
ok(
	'the host agrees only one was rolled back',
	$rowidHost->speculativeCalls === 1,
	(string) $rowidHost->speculativeCalls,
);

// the delete emptied the table, so the rows start at rowid 1 again rather than continuing from
// the four seeds -- getting this wrong is the difference between a prediction and a guess
ok(
	'the delete-all resets the count, so the last of 20 rows is rowid 20',
	$rebuilt['lastId'] === '20',
	$rebuilt['lastId'],
);
ok(
	'and the rebuild actually landed',
	(int) $rowidConnection->query('SELECT COUNT(*) FROM {rt}')->fetchField() === 20,
	(string) $rowidConnection->query('SELECT COUNT(*) FROM {rt}')->fetchField(),
);
ok(
	'the rows are the ones written, not a replay artefact',
	$rowidConnection
		->query('SELECT path FROM {rt} WHERE name = :n', [':n' => 'route.19'])
		->fetchField() === '/route/19',
);

// The shape, not the number. A replay per chunk over a growing buffer is quadratic in the rows,
// so doubling both the rows and the chunks would take 55 re-sent statements to 210. Measuring it
// at two sizes is what tells a constant from a small quadratic; one size cannot.
$rebuiltWide = rebuild($rowidConnection, 'rt', 40, 5);
ok(
	'doubling the rebuild re-sends exactly as much, so the cost is constant in the rows',
	$rebuiltWide['replayed'] === $rebuilt['replayed'],
	sprintf('%d at 20 rows, %d at 40', $rebuilt['replayed'], $rebuiltWide['replayed']),
);
ok(
	'and the wider rebuild lands every row and ends at rowid 40',
	(int) $rowidConnection->query('SELECT COUNT(*) FROM {rt}')->fetchField() === 40 &&
		$rebuiltWide['lastId'] === '40',
	$rebuiltWide['lastId'],
);

// The agreement check. A predicted id that the engine disagrees with would be silently wrong
// everywhere, so the two are made to answer the same question: the prediction first, then a
// dirty read, which replays the whole buffer and overwrites the answer with the engine's.
$agreeTransaction = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rt')
	->fields(['name' => 'agree.1', 'path' => '/agree/1'])
	->execute();
$predictedId = $rowidConnection->lastInsertId();
$rowidConnection->query('SELECT path FROM {rt} WHERE name = :n', [':n' => 'agree.1'])->fetchField();
$engineId = $rowidConnection->lastInsertId();
$agreeTransaction->commitOrRelease();
unset($agreeTransaction);
ok(
	'the predicted rowid is the one the engine assigns',
	$predictedId === $engineId && $predictedId === '41',
	sprintf('predicted %s, engine %s', $predictedId, $engineId),
);

// An AUTOINCREMENT table is predicted too, from `sqlite_sequence` rather than `max(rowid)`, and
// this used to be a control asserting the opposite. Its agreement with the engine is checked the
// same way the rowid case is -- a prediction the engine disagrees with is silently wrong
// everywhere, and that is a worse failure than the replay it removes.
$serialBefore = $rowidConnection->speculativeCount();
$serialTransaction = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rtserial')
	->fields(['v' => 'serial.1'])
	->execute();
$serialPredicted = $rowidConnection->lastInsertId();
$rowidConnection
	->query('SELECT v FROM {rtserial} WHERE v = :v', [':v' => 'serial.1'])
	->fetchField();
$serialEngine = $rowidConnection->lastInsertId();
$serialTransaction->commitOrRelease();
unset($serialTransaction);
ok(
	'an AUTOINCREMENT insert needs no replay of its own for its id',
	$serialBefore === $rowidConnection->speculativeCount() - 1,
	sprintf(
		'%d replays, of which the dirty read is 1',
		$rowidConnection->speculativeCount() - $serialBefore,
	),
);
ok(
	'and the predicted AUTOINCREMENT id is the one the engine assigns',
	$serialPredicted === $serialEngine && $serialPredicted === '1',
	sprintf('predicted %s, engine %s', $serialPredicted, $serialEngine),
);
// CONTROL: the sequence base is not just max(rowid). A delete leaves sqlite_sequence alone, so
// the next id after removing the only row is 2 rather than 1 -- taking max(rowid) would collide
$rowidConnection->query('DELETE FROM {rtserial}');
$gapTransaction = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rtserial')
	->fields(['v' => 'serial.2'])
	->execute();
$gapPredicted = $rowidConnection->lastInsertId();
$gapTransaction->commitOrRelease();
unset($gapTransaction);
ok(
	'CONTROL: the base is sqlite_sequence, so an id is not reused after a delete',
	$gapPredicted === '2' &&
		(string) $rowidConnection->query('SELECT id FROM {rtserial}')->fetchField() === '2',
	sprintf('predicted %s', $gapPredicted),
);

$chosenBefore = $rowidConnection->speculativeCount();
$chosenTransaction = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rtchosen')
	->fields(['id' => 77, 'v' => 'chosen'])
	->execute();
$chosenTransaction->commitOrRelease();
unset($chosenTransaction);
ok(
	'an insert that supplies the rowid itself needs no replay: the id is in its own parameters',
	$rowidConnection->speculativeCount() - $chosenBefore === 0,
	(string) ($rowidConnection->speculativeCount() - $chosenBefore),
);
ok(
	'and the id it reports is the one that was supplied',
	(string) $rowidConnection->query('SELECT id FROM {rtchosen}')->fetchField() === '77',
);

// THE COLLISION THIS FIX ALSO CLOSES. A supplied rowid is not an append, so counting it as one
// predicted an id that already exists: insert 400 into a table whose maximum is 1, then append,
// and the append is 401 rather than the 2 an offset counter would say.
$rowidConnection->query('CREATE TABLE {rtmixed} (id INTEGER PRIMARY KEY, v TEXT)');
$rowidConnection
	->insert('rtmixed')
	->fields(['id' => 1, 'v' => 'seed'])
	->execute();
$mixedTransaction = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rtmixed')
	->fields(['id' => 400, 'v' => 'chosen'])
	->execute();
$rowidConnection
	->insert('rtmixed')
	->fields(['v' => 'appended'])
	->execute();
$mixedPredicted = $rowidConnection->lastInsertId();
$mixedTransaction->commitOrRelease();
unset($mixedTransaction);
ok(
	'an append AFTER a supplied rowid is predicted past it, not past the committed maximum',
	$mixedPredicted === '401',
	sprintf('predicted %s', $mixedPredicted),
);
ok(
	'and the engine agrees',
	(string) $rowidConnection
		->query("SELECT id FROM {rtmixed} WHERE v = 'appended'")
		->fetchField() === '401',
);

// A CONDITIONAL delete leaves an unknown maximum behind, unlike the delete-all above, so the
// insert after it has to replay. Read as a DIFFERENCE against the same block with an
// unconditional delete: both pay one replay for the delete's own row count, which is the
// subtrahend, so the extra one belongs to the insert and to nothing else.
$rowidConnection->query(
	'CREATE TABLE {rtwhere} (name VARCHAR(255) PRIMARY KEY, path VARCHAR(255))',
);
$rowidConnection
	->insert('rtwhere')
	->fields(['name' => 'seed', 'path' => '/seed'])
	->execute();

$wholeBefore = $rowidConnection->speculativeCount();
$wholeTransaction = $rowidConnection->startTransaction();
$rowidConnection->delete('rtwhere')->execute();
$rowidConnection
	->insert('rtwhere')
	->fields(['name' => 'after.whole', 'path' => '/after/whole'])
	->execute();
$wholeTransaction->commitOrRelease();
unset($wholeTransaction);
$wholeCost = $rowidConnection->speculativeCount() - $wholeBefore;

$partialBefore = $rowidConnection->speculativeCount();
$partialTransaction = $rowidConnection->startTransaction();
$rowidConnection->delete('rtwhere')->condition('name', 'after.whole')->execute();
$rowidConnection
	->insert('rtwhere')
	->fields(['name' => 'after.partial', 'path' => '/after/partial'])
	->execute();
$partialTransaction->commitOrRelease();
unset($partialTransaction);
$partialCost = $rowidConnection->speculativeCount() - $partialBefore;

ok(
	'an unconditional delete then an insert replays once, for the delete row count alone',
	$wholeCost === 1,
	(string) $wholeCost,
);
ok(
	'CONTROL: making that delete conditional blocks the table, so the insert replays too',
	$partialCost === $wholeCost + 1,
	sprintf('%d conditional, %d unconditional', $partialCost, $wholeCost),
);

// a savepoint rollback removes statements a later insert counted, so the plan has to be rebuilt
// rather than adjusted -- an offset carried over the discarded rows would be too high
$rollbackTransaction = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rt')
	->fields(['name' => 'keep.1', 'path' => '/keep/1'])
	->execute();
$savepoint = $rowidConnection->startTransaction();
$rowidConnection
	->insert('rt')
	->fields(['name' => 'drop.1', 'path' => '/drop/1'])
	->execute();
$rowidConnection
	->insert('rt')
	->fields(['name' => 'drop.2', 'path' => '/drop/2'])
	->execute();
$savepoint->rollBack();
unset($savepoint);
$afterRollback = $rowidConnection
	->insert('rt')
	->fields(['name' => 'keep.2', 'path' => '/keep/2'])
	->execute();
$rollbackTransaction->commitOrRelease();
unset($rollbackTransaction);
$committedMax = (int) $rowidConnection->query('SELECT MAX(_rowid_) FROM {rt}')->fetchField();
ok(
	'a savepoint rollback un-counts the rows it discarded',
	(int) $afterRollback === $committedMax,
	sprintf('reported %s, table holds %d', (string) $afterRollback, $committedMax),
);
// #endregion
// #endregion
// #region a lane mints from its own slice of the rowid space
//
// A read-replica lane runs a write, rolls its own copy back and forwards the statements to a
// primary that replays them. A prediction alone is then only the id the caller was TOLD: the
// primary's SQLite appends by its own maximum, so the redirect and the row disagree and nothing
// errors. Two halves close that and neither works alone -- the prediction strides, and the buffered
// statement carries the value it minted. The unpartitioned control at the end is what proves the
// primary is untouched.
echo "\nPartitioned rowids\n";

/**
 * Builds a Connection that mints as one lane of a pool.
 *
 * @param int $lane
 *   The lane number; 0 is the primary and partitions nothing.
 * @param int $lanes
 *   How many lanes the pool has beyond the primary.
 *
 * @return array
 *   The fake host and the Connection bound to it, in that order.
 */
function lane_connect(int $lane, int $lanes): array
{
	$host = new FakeHost();
	$client = new CfwSqlClient($host->execBridge(), $host->txnBridge());
	$connection = new Connection($client, [
		'prefix' => '',
		'database' => 'do',
		'lane' => $lane,
		'lanes' => $lanes,
	]);
	$connection->query('CREATE TABLE {ln} (id INTEGER PRIMARY KEY, v TEXT)');
	$connection->query('CREATE TABLE {lnserial} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
	// five committed rows, so a plain append would be 6 and a strided one cannot be
	for ($i = 1; $i <= 5; $i++) {
		$connection
			->insert('ln')
			->fields(['id' => $i, 'v' => "seed.$i"])
			->execute();
		$connection
			->insert('lnserial')
			->fields(['v' => "seed.$i"])
			->execute();
	}

	return [$host, $connection];
}

/**
 * Buffers inserts into one table, commits them, and reports what each half did.
 *
 * @param FakeHost $host
 *   The host, read for the statements it was actually sent.
 * @param Connection $connection
 *   The connection to drive.
 * @param string $table
 *   The table to insert into.
 * @param int $rows
 *   How many rows to buffer inside the one transaction.
 *
 * @return array{predicted: string[], committed: int[], sql: string[]}
 *   The ids reported, the ids the engine gave, and the INSERT statements as sent.
 */
function lane_insert(FakeHost $host, Connection $connection, string $table, int $rows = 1): array
{
	$mark = count($host->statements);
	$predicted = [];
	$transaction = $connection->startTransaction();
	for ($i = 0; $i < $rows; $i++) {
		$predicted[] = (string) $connection
			->insert($table)
			->fields(['v' => "lane.$i"])
			->execute();
	}
	$transaction->commitOrRelease();
	unset($transaction);

	$sql = [];
	foreach (array_slice($host->statements, $mark) as $statement) {
		if (preg_match('/^\s*INSERT\s+INTO/i', $statement) === 1) {
			$sql[] = $statement;
		}
	}
	$committed = [];
	$landed = $connection->query("SELECT id FROM {{$table}} WHERE v LIKE 'lane.%' ORDER BY id");
	foreach ($landed as $row) {
		$committed[] = (int) $row->id;
	}

	return ['predicted' => $predicted, 'committed' => $committed, 'sql' => $sql];
}

[, $primaryConnection] = lane_connect(0, 3);
ok(
	'lane 0 is the primary and strides on nothing',
	$primaryConnection->idPartition() === ['offset' => 0, 'stride' => 1],
	json_encode($primaryConnection->idPartition()),
);
[, $unpooledConnection] = lane_connect(1, 0);
ok(
	'and a lane with no pool behind it strides on nothing either',
	$unpooledConnection->idPartition() === ['offset' => 0, 'stride' => 1],
	json_encode($unpooledConnection->idPartition()),
);
[$laneHost, $laneConnection] = lane_connect(1, 3);
ok(
	'lane 1 of 3 takes every fourth id, offset 1',
	$laneConnection->idPartition() === ['offset' => 1, 'stride' => 4],
	json_encode($laneConnection->idPartition()),
);

// THE DIVERGENCE ITSELF. Base 5, so a plain append is 6 and every lane but the one whose residue
// that is has to say something else -- which is exactly the number the primary would not have
// assigned, and why the statement has to carry it.
$laneRun = lane_insert($laneHost, $laneConnection, 'ln');
ok(
	'a lane predicts the next id in its own residue class, not the next id',
	$laneRun['predicted'] === ['9'],
	implode(',', $laneRun['predicted']),
);
ok(
	'the buffered INSERT was rewritten to name the key column and carry that id',
	count($laneRun['sql']) === 1 &&
		preg_match('/INSERT INTO "ln" \("id", /i', $laneRun['sql'][0]) === 1 &&
		str_contains($laneRun['sql'][0], 'VALUES (9, '),
	$laneRun['sql'][0] ?? 'no insert reached the host',
);
ok(
	'so the id the caller was told is the id the engine committed',
	$laneRun['committed'] === [9],
	implode(',', array_map('strval', $laneRun['committed'])),
);

// A SECOND ROW IN THE SAME BUFFER STRIDES TOO. The rewritten statement supplies its own id, so the
// next one bases off that rather than off a committed maximum the buffer has not moved.
[$twoHost, $twoConnection] = lane_connect(1, 3);
$twoRun = lane_insert($twoHost, $twoConnection, 'ln', 2);
ok(
	'consecutive buffered inserts step by the stride rather than by one',
	$twoRun['predicted'] === ['9', '13'] && $twoRun['committed'] === [9, 13],
	sprintf(
		'predicted %s, committed %s',
		implode(',', $twoRun['predicted']),
		implode(',', array_map('strval', $twoRun['committed'])),
	),
);

// A SECOND MINT IN A LATER BUFFER HAS NOTHING TO BASE OFF, and that is what the mark is for. The
// lane rolls its forwarded write back and the committed batch is never applied here, so its own
// maximum is still 5 and the arithmetic above repeats 9. The host writes what it forwarded into
// cfw_meta; this reads it.
[$markHost, $markConnection] = lane_connect(1, 3);
$markConnection->query('CREATE TABLE cfw_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
$markConnection->query("INSERT INTO cfw_meta (k, v) VALUES ('lane_high:ln', '9')");
$markRun = lane_insert($markHost, $markConnection, 'ln');
ok(
	'a lane mints above the id it forwarded, rather than repeating it',
	$markRun['predicted'] === ['13'] && $markRun['committed'] === [13],
	sprintf(
		'predicted %s, committed %s',
		implode(',', $markRun['predicted']),
		implode(',', array_map('strval', $markRun['committed'])),
	),
);

// A mark catch-up has overtaken is inert rather than wrong, which is why nothing clears it.
[$staleHost, $staleConnection] = lane_connect(1, 3);
$staleConnection->query('CREATE TABLE cfw_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
$staleConnection->query("INSERT INTO cfw_meta (k, v) VALUES ('lane_high:ln', '2')");
$staleRun = lane_insert($staleHost, $staleConnection, 'ln');
ok(
	'and a mark below the committed maximum changes nothing',
	$staleRun['predicted'] === ['9'],
	implode(',', $staleRun['predicted']),
);

// CONTROL: the primary commits its own writes, so it has no mark and must not pay a read for one.
[$noMarkHost, $noMarkConnection] = lane_connect(0, 3);
$noMarkConnection->query('CREATE TABLE cfw_meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
$noMarkConnection->query("INSERT INTO cfw_meta (k, v) VALUES ('lane_high:ln', '99')");
$noMarkAt = count($noMarkHost->statements);
$noMarkRun = lane_insert($noMarkHost, $noMarkConnection, 'ln');
$noMarkReads = array_filter(
	array_slice($noMarkHost->statements, $noMarkAt),
	static fn(string $statement): bool => str_contains($statement, 'cfw_meta'),
);
ok(
	'CONTROL: an unpartitioned connection ignores the mark and never reads it',
	$noMarkRun['predicted'] === ['6'] && $noMarkReads === [],
	sprintf('predicted %s, %d read(s)', implode(',', $noMarkRun['predicted']), count($noMarkReads)),
);

// The property the partition exists for: two lanes reading the same committed database cannot mint
// the same id, so a forwarded batch from either commits without colliding with the other.
$minted = [];
for ($lane = 1; $lane <= 3; $lane++) {
	[$eachHost, $eachConnection] = lane_connect($lane, 3);
	$run = lane_insert($eachHost, $eachConnection, 'ln');
	$minted[$lane] = (int) $run['predicted'][0];
}
ok(
	'three lanes on the same base mint three different ids',
	count(array_unique($minted)) === 3,
	implode(',', array_map('strval', $minted)),
);
ok(
	'and each one is in its own residue class, which is what keeps that true forever',
	$minted[1] % 4 === 1 && $minted[2] % 4 === 2 && $minted[3] % 4 === 3,
	implode(',', array_map('strval', $minted)),
);

// AUTOINCREMENT is the same arithmetic on a different base, so it partitions the same way; these
// are the tables whose id a redirect actually carries.
// lane 3 rather than lane 2, whose residue happens to BE the next append: an arm that agrees with
// the unpartitioned answer asserts nothing
[$serialHost, $serialConnection] = lane_connect(3, 3);
$serialRun = lane_insert($serialHost, $serialConnection, 'lnserial');
ok(
	'an AUTOINCREMENT table strides from sqlite_sequence rather than from max(rowid)',
	$serialRun['predicted'] === ['7'] && $serialRun['committed'] === [7],
	sprintf(
		'predicted %s, committed %s',
		implode(',', $serialRun['predicted']),
		implode(',', array_map('strval', $serialRun['committed'])),
	),
);

// CONTROL, and it is the compatibility requirement rather than a nicety: an unpartitioned
// connection must buffer the statement Drupal wrote and report the id SQLite would have assigned.
// Everything above is reachable only from a lane.
[$controlHost, $controlConnection] = lane_connect(0, 3);
$controlRun = lane_insert($controlHost, $controlConnection, 'ln');
ok(
	'CONTROL: the primary still appends, and still reports the appended id',
	$controlRun['predicted'] === ['6'] && $controlRun['committed'] === [6],
	sprintf(
		'predicted %s, committed %s',
		implode(',', $controlRun['predicted']),
		implode(',', array_map('strval', $controlRun['committed'])),
	),
);
ok(
	'CONTROL: and its INSERT is the one Drupal wrote, with no key column spliced in',
	count($controlRun['sql']) === 1 &&
		preg_match('/INSERT INTO "ln" \("v"\)/i', $controlRun['sql'][0]) === 1,
	$controlRun['sql'][0] ?? 'no insert reached the host',
);
// #endregion
// #region statements the engine rejects
//
// A buffered write is reported as successful before anything has run, so a statement SQLite
// would refuse sits in the buffer looking fine. Left there it is fatal twice over: every later
// replay re-runs it, and so does the commit, so the transaction can never succeed even after
// the reason for the refusal is gone.
//
// This is not hypothetical and it is not a corner. It is the exact shape of Drupal core's own
// lazy-table idiom -- write, catch the failure, create the table, carry on -- which
// MatcherDumper::dump() runs INSIDE a transaction. Before Connection::speculate() existed, a
// stock `standard` install died there: `DELETE FROM router` was buffered instead of failing,
// so ensureTableExists() never fired, and the buffered delete then poisoned every replay. See
// tests/run-installer.php, which is what found it.
echo "\nRejected statements\n";

[$rejectHost, $rejectConnection] = connect();
$rejectConnection->query('CREATE TABLE {kept} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$rejectConnection
	->insert('kept')
	->fields(['v' => 'committed'])
	->execute();

// the idiom, statement for statement
$rejectTransaction = $rejectConnection->startTransaction();
$missingDelete = $rejectConnection->prepareStatement('DELETE FROM {absent}', [], true);
$missingDelete->execute();
ok(
	'a write to a table that does not exist is buffered rather than refused',
	$rejectConnection->isBuffering(),
);

$rejected = null;
try {
	$missingDelete->rowCount();
} catch (\Exception $e) {
	$rejected = $e;
}
ok('asking what it changed is what surfaces the refusal', $rejected instanceof SqlErrorException);
ok(
	'and the message is the engine naming the table, which is what core catches on',
	$rejected !== null && str_contains($rejected->getMessage(), 'no such table: absent'),
	$rejected === null ? 'nothing was thrown' : $rejected->getMessage(),
);

// the whole point: the transaction is still usable, so core's recovery can run
$absentBefore = $rejectConnection->speculativeCount();
ok(
	'asking a second time reports 0 rows changed, because the statement did not happen',
	$missingDelete->rowCount() === 0,
	(string) $missingDelete->rowCount(),
);
ok(
	'and costs no replay, because a refused statement is not sent again',
	$rejectConnection->speculativeCount() === $absentBefore,
	sprintf('%d before, %d after', $absentBefore, $rejectConnection->speculativeCount()),
);

$rejectConnection->query('CREATE TABLE {absent} (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
$rejectConnection
	->insert('absent')
	->fields(['v' => 'created inside the same transaction'])
	->execute();
$rejectTransaction->commitOrRelease();
unset($rejectTransaction);
ok(
	'the transaction survives the refusal and commits',
	(int) $rejectHost->pdo->query('SELECT COUNT(*) FROM absent')->fetchColumn() === 1,
);
ok(
	'the refused statement was sent exactly once, so nothing replayed it afterwards',
	count(
		array_filter($rejectHost->statements, fn($s) => str_contains($s, 'DELETE FROM "absent"')),
	) === 1,
	(string) count(
		array_filter($rejectHost->statements, fn($s) => str_contains($s, 'DELETE FROM "absent"')),
	),
);

// WHICH statement failed has to be recovered by bisection: the host reports one error for the
// whole replay and never says where. Four buffered writes, none of them resolved yet, and the
// third is the bad one -- so a scheme that simply blamed the newest or the oldest is wrong.
$rejectConnection->query('CREATE TABLE {gone} (id INTEGER PRIMARY KEY, v TEXT)');
$rejectConnection->query('DROP TABLE {gone}');

$rejectTransaction = $rejectConnection->startTransaction();
$rejectConnection->query('UPDATE {kept} SET [v] = :v', [':v' => 'first']);
$rejectConnection->query('UPDATE {kept} SET [v] = :v', [':v' => 'second']);
$rejectConnection->query('DELETE FROM {gone}');
$rejectConnection->query('UPDATE {kept} SET [v] = :v', [':v' => 'third']);

$bisectBefore = $rejectHost->speculativeCalls;
$bisected = null;
try {
	$rejectConnection->query('SELECT [v] FROM {kept}')->fetchField();
} catch (\Exception $e) {
	$bisected = $e;
}
ok(
	'a dirty read over a poisoned buffer reports the statement that is actually bad',
	$bisected !== null && str_contains($bisected->getMessage(), 'no such table: gone'),
	$bisected === null ? 'nothing was thrown' : $bisected->getMessage(),
);
ok(
	'bisection is bounded: 4 buffered writes cost 4 host transactions to place the bad one',
	$rejectHost->speculativeCalls - $bisectBefore === 4,
	sprintf('%d transactions', $rejectHost->speculativeCalls - $bisectBefore),
);

$retried = $rejectConnection->query('SELECT [v] FROM {kept}')->fetchField();
ok(
	'the same read then succeeds, and observes every write except the refused one',
	$retried === 'third',
	var_export($retried, true),
);

// index stability: the update below sits at buffer index 3, behind a discarded slot. A replay
// sends it at POSITION 2, so a driver that read the host's results positionally would attribute
// this row count to the wrong statement and report 0.
$behind = $rejectConnection->prepareStatement('UPDATE {kept} SET [v] = :v', [], true);
$behind->execute([':v' => 'fourth']);
ok(
	'a statement buffered behind a discarded one still gets its own row count',
	$behind->rowCount() === 1,
	(string) $behind->rowCount(),
);
$rejectTransaction->commitOrRelease();
unset($rejectTransaction);
ok(
	'and the commit replays the survivors without the discarded one',
	$rejectHost->pdo->query('SELECT v FROM kept')->fetchColumn() === 'fourth',
);

// CONTROL: when the READ is what SQLite refuses, nothing buffered is at fault and nothing may be
// discarded. Without this the assertions above would also pass on a driver that threw away a
// statement every time anything went wrong.
$rejectTransaction = $rejectConnection->startTransaction();
$rejectConnection->query('UPDATE {kept} SET [v] = :v', [':v' => 'intact']);
$badRead = null;
try {
	$rejectConnection->query('SELECT [v] FROM {kept}, {nosuchtable}')->fetchField();
} catch (\Exception $e) {
	$badRead = $e;
}
ok(
	'CONTROL: a read naming a missing table fails on the read',
	$badRead !== null && str_contains($badRead->getMessage(), 'no such table: nosuchtable'),
	$badRead === null ? 'nothing was thrown' : $badRead->getMessage(),
);
ok(
	'CONTROL: and the buffer is untouched, so the write is still there to be seen',
	$rejectConnection->query('SELECT [v] FROM {kept}')->fetchField() === 'intact',
);
$rejectTransaction->rollBack();
unset($rejectTransaction);

// a discarded INSERT must stop counting as one, or lastInsertId() keeps asking about a rowid
// that will never be assigned
$rejectTransaction = $rejectConnection->startTransaction();
$rejectConnection->query('INSERT INTO {stillabsent} ([v]) VALUES (:v)', [':v' => 'x']);
$insertRejected = null;
try {
	$rejectConnection->lastInsertId();
} catch (\Exception $e) {
	$insertRejected = $e;
}
ok(
	'a buffered insert into a missing table is refused when its id is asked for',
	$insertRejected instanceof SqlErrorException,
	$insertRejected === null ? 'nothing was thrown' : get_class($insertRejected),
);
ok(
	'and the buffer stops claiming an insert, so the next call answers from the database',
	ctype_digit($rejectConnection->lastInsertId()),
	var_export($rejectConnection->lastInsertId(), true),
);
$rejectTransaction->rollBack();
unset($rejectTransaction);
// #endregion
// #region a non-empty table prefix
//
// The core sqlite driver implements a prefix with ATTACH DATABASE, which has no analogue on
// ctx.storage.sql: one Durable Object is one database and there is nothing to attach. The base
// Connection's own mechanism does have one -- setPrefix() folds the prefix into the identifier
// -- so that is what this driver uses, reached by calling the grandparent constructor.
//
// The point of these assertions is ISOLATION: two connections over the SAME host must not see
// each other's tables. Both connections below share one FakeHost on purpose; a fresh host per
// connection would make the isolation trivially true and prove nothing.
echo "\nTable prefix\n";

$prefixHost = new FakeHost();
$prefixed = new Connection(new CfwSqlClient($prefixHost->execBridge(), $prefixHost->txnBridge()), [
	'prefix' => 'site1_',
	'database' => 'do',
]);
$plain = new Connection(new CfwSqlClient($prefixHost->execBridge(), $prefixHost->txnBridge()), [
	'prefix' => '',
	'database' => 'do',
]);

ok('getPrefix() reports the configured prefix', $prefixed->getPrefix() === 'site1_');
ok(
	'a curly-brace placeholder resolves to the mangled identifier',
	$prefixed->prefixTables('SELECT * FROM {node}') === 'SELECT * FROM "site1_node"',
	$prefixed->prefixTables('SELECT * FROM {node}'),
);
ok(
	'and to the bare identifier without a prefix',
	$plain->prefixTables('SELECT * FROM {node}') === 'SELECT * FROM "node"',
	$plain->prefixTables('SELECT * FROM {node}'),
);
ok(
	'getFullQualifiedTableName() carries the prefix and no database name',
	$prefixed->getFullQualifiedTableName('node') === 'site1_node',
	$prefixed->getFullQualifiedTableName('node'),
);

$prefixed->schema()->createTable('iso', [
	'fields' => [
		'id' => ['type' => 'int', 'not null' => true],
		'v' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
	],
	'primary key' => ['id'],
	'indexes' => ['byv' => ['v']],
]);
$plain->schema()->createTable('iso', [
	'fields' => [
		'id' => ['type' => 'int', 'not null' => true],
		'v' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
	],
	'primary key' => ['id'],
]);

$rawNames = [];
foreach ($prefixHost->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
	$rawNames[] = $row['name'];
}
ok(
	'the prefixed CREATE TABLE lands under the mangled name',
	in_array('site1_iso', $rawNames, true),
	implode(', ', $rawNames),
);
ok(
	'the unprefixed one lands beside it, not on top of it',
	in_array('iso', $rawNames, true),
	implode(', ', $rawNames),
);

$prefixed
	->insert('iso')
	->fields(['id' => 1, 'v' => 'prefixed-row'])
	->execute();
$plain
	->insert('iso')
	->fields(['id' => 1, 'v' => 'plain-row'])
	->execute();
ok(
	'the prefixed connection reads its own row',
	$prefixed->query('SELECT [v] FROM {iso} WHERE [id] = 1')->fetchField() === 'prefixed-row',
);
ok(
	'the unprefixed connection reads its own row',
	$plain->query('SELECT [v] FROM {iso} WHERE [id] = 1')->fetchField() === 'plain-row',
);

$prefixed->schema()->createTable('onlyhere', [
	'fields' => ['id' => ['type' => 'int', 'not null' => true]],
	'primary key' => ['id'],
]);
ok('tableExists() sees its own table', $prefixed->schema()->tableExists('onlyhere'));
ok('and the other connection does not see it at all', !$plain->schema()->tableExists('onlyhere'));
ok('indexExists() finds the prefixed index', $prefixed->schema()->indexExists('iso', 'byv'));
ok('and the unprefixed connection has no such index', !$plain->schema()->indexExists('iso', 'byv'));

// findTables() is the ONE inherited method that had to be replaced: the core sqlite version
// matches the expression against the bare sqlite_master name, because there a prefixed table
// lives in its own ATTACHed schema. Here the name carries the prefix, so inheriting it returns
// nothing at all.
$foundPrefixed = $prefixed->schema()->findTables('%');
ok(
	'findTables() returns UNPREFIXED names',
	isset($foundPrefixed['iso'], $foundPrefixed['onlyhere']),
	implode(', ', array_keys($foundPrefixed)),
);
ok(
	'findTables() does not leak the other connection tables',
	!isset($foundPrefixed['site1_iso']),
	implode(', ', array_keys($foundPrefixed)),
);
$foundPlain = $plain->schema()->findTables('%');
ok(
	'the unprefixed connection sees only its own',
	isset($foundPlain['iso']) && !isset($foundPlain['onlyhere']),
	implode(', ', array_keys($foundPlain)),
);
ok(
	'findTables() honours the expression as well as the prefix',
	array_keys($prefixed->schema()->findTables('only%')) === ['onlyhere'],
	implode(', ', array_keys($prefixed->schema()->findTables('only%'))),
);

$prefixed->schema()->renameTable('onlyhere', 'renamed');
ok('renameTable() keeps the table prefixed', $prefixed->schema()->tableExists('renamed'));
ok('and the old name is gone', !$prefixed->schema()->tableExists('onlyhere'));
ok('the rename did not create an unprefixed table', !$plain->schema()->tableExists('renamed'));
$prefixed->schema()->dropTable('renamed');
ok('dropTable() removes the prefixed table', !$prefixed->schema()->tableExists('renamed'));
ok('and leaves the other connection alone', $plain->schema()->tableExists('iso'));

// the transaction machinery has to keep working under a prefix, because SqlAnalyzer sees the
// MANGLED names and the dirty-table set is keyed on whatever it sees
$prefixTransaction = $prefixed->startTransaction();
$prefixed
	->insert('iso')
	->fields(['id' => 2, 'v' => 'buffered'])
	->execute();
ok(
	'a dirty read under a prefix still sees the buffered write',
	$prefixed->query('SELECT [v] FROM {iso} WHERE [id] = 2')->fetchField() === 'buffered',
);
ok(
	'and the other connection cannot see it, because nothing was committed',
	$plain->query('SELECT COUNT(*) FROM {iso} WHERE [id] = 2')->fetchField() === '0',
);
$prefixTransaction->rollBack();
unset($prefixTransaction);

// A period is the one prefix character that cannot work. Core allows it and every other driver
// reads it as a schema selector; there is no second schema here to select.
ok(
	'PREFIX_PATTERN accepts what can be mangled',
	Connection::isSupportedPrefix('') &&
		Connection::isSupportedPrefix('site1_') &&
		Connection::isSupportedPrefix('Aa0_'),
);
$periodRefused = false;
$periodMessage = '';
try {
	new Connection(new CfwSqlClient($prefixHost->execBridge(), $prefixHost->txnBridge()), [
		'prefix' => 'other.',
		'database' => 'do',
	]);
} catch (HostBridgeException $e) {
	$periodRefused = true;
	$periodMessage = $e->getMessage();
}
ok('a prefix containing a period is refused', $periodRefused, $periodMessage);
ok(
	'and the refusal names the reason rather than the symptom',
	str_contains($periodMessage, 'selects a schema'),
	$periodMessage,
);

// the prefix is part of the LIKE pattern findTables() sends, and the platform refuses any
// pattern over 50 bytes -- so a long prefix can push a short expression over on its own
$longPattern = false;
$longPatternMessage = '';
try {
	$prefixed->schema()->findTables(str_repeat('a', 50) . '%');
} catch (InvalidQueryException $e) {
	$longPattern = true;
	$longPatternMessage = $e->getMessage();
}
ok('findTables() refuses an over-length LIKE pattern', $longPattern, $longPatternMessage);
ok(
	'and the refusal says the prefix counts towards the length',
	str_contains($longPatternMessage, 'The prefix is part of that length'),
	$longPatternMessage,
);

// the schema needs THIS driver's connection: it reads the synthetic schema list, which the
// base Connection does not have
$wrongSchema = false;
try {
	new Schema(new stdClass());
} catch (HostBridgeException $e) {
	$wrongSchema = str_contains($e->getMessage(), 'stdClass');
}
ok('the schema refuses a connection that is not this driver', $wrongSchema);
// #endregion
// #region P43: three platform limits the driver now accommodates or declares
//
// Each one was found by a REAL module rather than by reading the limits page, and each keeps the
// shape `splitPointFor()` established: refuse far more than you accept, and say why in the
// refusal. The one that is DECLARED rather than accommodated is declared because the
// accommodation was priced and lost.
echo "\nP43: LIKE widening, split writes, and the record cap\n";

// --- the 50-byte LIKE cap ---------------------------------------------------
//
// `escapeLike()` shortens nothing, so "%$search%" with a 60-character query is 62 bytes and the
// host answers "pattern too complex". Six mantle2 controllers trip it.
ok(
	'widenLikePattern leaves a pattern that already fits alone',
	SqlAnalyzer::widenLikePattern('%short%', 50) === '%short%',
);
$long = '%' . str_repeat('n', 80) . '%';
$widened = SqlAnalyzer::widenLikePattern($long, 50);
ok(
	'an oversized pattern is widened rather than refused',
	is_string($widened),
	var_export($widened, true),
);
ok(
	'and the widened form fits the ceiling',
	strlen(SqlAnalyzer::likeToGlob((string) $widened)) <= 50,
	(string) strlen(SqlAnalyzer::likeToGlob((string) $widened)),
);
// THE PROPERTY THAT MAKES THE POST-FILTER SOUND: everything the original matched, the widened
// pattern matches too. Asserted rather than argued, over the same hostile alphabet the GLOB
// differential uses
$supersetHolds = true;
$seedP43 = 7;
$rndP43 = function () use (&$seedP43) {
	$seedP43 = ($seedP43 * 1103515245 + 12345) & 0x7fffffff;
	return $seedP43 / 0x7fffffff;
};
for ($i = 0; $i < 400; $i++) {
	$needle = '';
	$n = 60 + (int) floor($rndP43() * 20);
	for ($j = 0; $j < $n; $j++) {
		$needle .= chr(97 + (int) floor($rndP43() * 26));
	}
	$original = '%' . $needle . '%';
	$wide = SqlAnalyzer::widenLikePattern($original, 50);
	if (!is_string($wide)) {
		$supersetHolds = false;
		break;
	}
	// a subject the ORIGINAL matches must also be matched by the widened pattern
	$subject = 'xx' . $needle . 'yy';
	$toRegex = static function (string $like): string {
		$out = '';
		for ($k = 0; $k < strlen($like); $k++) {
			$ch = $like[$k];
			$out .= $ch === '%' ? '.*' : ($ch === '_' ? '.' : preg_quote($ch, '/'));
		}
		return '/^' . $out . '$/sD';
	};
	if (
		preg_match($toRegex($original), $subject) !== 1 ||
		preg_match($toRegex($wide), $subject) !== 1
	) {
		$supersetHolds = false;
		break;
	}
}
ok('the widened pattern is a SUPERSET of the original, over 400 cases', $supersetHolds);

// THE SELECTIVITY FLOOR, and it is what stops the accommodation being worse than the limit.
// Bracket-quoting triples every metacharacter, so a pattern of 40 asterisks widens only by
// throwing most of itself away -- and `%a%` scans the table and ships nearly every row across the
// bridge for PHP to discard. Below the floor the driver refuses, exactly as it did before.
ok(
	'a pattern that cannot keep enough of itself returns NULL rather than a table scan',
	SqlAnalyzer::widenLikePattern(str_repeat('*', 40), 50, 20) === null,
);
ok(
	'the floor is what refuses it, not the ceiling: a lower floor widens the same pattern',
	is_string(SqlAnalyzer::widenLikePattern(str_repeat('*', 40), 50, 4)),
);
ok(
	'and a needle of real text keeps far more than the floor',
	strlen(rtrim((string) SqlAnalyzer::widenLikePattern($long, 50), '%')) >= 40,
	(string) strlen(rtrim((string) SqlAnalyzer::widenLikePattern($long, 50), '%')),
);

// UTF-8: cutting mid-codepoint would leave a broken sequence that matches nothing, which is the
// opposite of a superset
$utf8 = str_repeat("\u{00E9}", 40);
$utf8Wide = SqlAnalyzer::widenLikePattern($utf8, 50);
ok('a multibyte pattern widens on a character boundary', is_string($utf8Wide));
ok(
	'and the widened form is still valid UTF-8',
	is_string($utf8Wide) && SqlAnalyzer::endsOnUtf8Boundary(rtrim((string) $utf8Wide, '%')),
);
ok('endsOnUtf8Boundary rejects a truncated sequence', !SqlAnalyzer::endsOnUtf8Boundary("a\xC3"));
ok('and accepts a complete one', SqlAnalyzer::endsOnUtf8Boundary("a\u{00E9}"));

// --- the split write --------------------------------------------------------
//
// `splitPointFor()` refused every non-SELECT, so strata's compactor -- one
// `DELETE ... WHERE hash IN (...)` over the whole removed set -- died on a 101-frame sweep.
[$p43Host, $p43Conn] = connect();
$p43Conn->runStatement('CREATE TABLE "j" ("hash" TEXT, "payload" TEXT)', []);
$names = [];
$args = [];
for ($i = 0; $i < 130; $i++) {
	$names[] = ':h' . $i;
	$args[':h' . $i] = 'hash-' . $i;
}
$deleteSql = 'DELETE FROM "j" WHERE "hash" IN (' . implode(', ', $names) . ')';
$deleteStmt = $p43Conn->prepareStatement($deleteSql, [], true);
$beforeCalls = $p43Host->execCalls;
$deleteStmt->execute($args);
ok(
	'an oversized DELETE ... IN is split rather than refused',
	$p43Host->execCalls - $beforeCalls >= 2,
	(string) ($p43Host->execCalls - $beforeCalls),
);
// the narrowing is exactly two statement kinds wide, and everything else keeps the old refusal
$insertSql = 'INSERT INTO "j" ("hash") VALUES (' . implode('), (', $names) . ')';
$insertThrew = false;
try {
	$p43Conn->prepareStatement($insertSql, [], true)->execute($args);
} catch (Throwable) {
	$insertThrew = true;
}
ok('an oversized INSERT is still refused, because there is no IN list to partition', $insertThrew);

$limited = 'DELETE FROM "j" WHERE "hash" IN (' . implode(', ', $names) . ') LIMIT 5';
$limitThrew = false;
try {
	$p43Conn->prepareStatement($limited, [], true)->execute($args);
} catch (Throwable) {
	$limitThrew = true;
}
ok('a DELETE ... LIMIT is still refused, because the cut cannot be reconstructed', $limitThrew);

// --- the record cap, DECLARED ----------------------------------------------
[$capHost, $capConn] = connect();
$capConn->runStatement('CREATE TABLE "j" ("hash" TEXT, "payload" TEXT)', []);
$capMessage = '';
try {
	$capConn->runStatement('INSERT INTO "j" ("payload") VALUES (:p)', [
		':p' => str_repeat('x', Connection::MAX_RECORD_BYTES + 1),
	]);
} catch (Throwable $e) {
	$capMessage = $e->getMessage();
}
ok('an oversized value is refused rather than truncated', $capMessage !== '');
ok('the refusal names the placeholder', str_contains($capMessage, ':p'), $capMessage);
ok('and the cap', str_contains($capMessage, (string) Connection::MAX_RECORD_BYTES), $capMessage);
ok(
	'and says the driver deliberately does not chunk it',
	str_contains($capMessage, 'rows-written'),
	$capMessage,
);
// CONTROL: a value one byte under the cap is not refused, or the check would be a blanket ban
$underThrew = false;
try {
	$capConn->runStatement('INSERT INTO "j" ("payload") VALUES (:p)', [
		':p' => str_repeat('x', Connection::MAX_RECORD_BYTES),
	]);
} catch (Throwable) {
	$underThrew = true;
}
ok('CONTROL: a value AT the cap is accepted', !$underThrew);
// #endregion
echo "\nhost calls: {$host->execCalls} single, {$host->txnCalls} transactional ({$host->speculativeCalls} rolled back over {$host->replayedStatements} replayed statements)\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

<?php

/**
 * @file
 * Drives the cfw_do_sqlite driver against a stand-in host.
 *
 * WHAT THIS PROVES AND WHAT IT DOES NOT. The host here is PDO SQLite speaking
 * the same JSON contract as src/do-sqlite.js, not ctx.storage.sql. So it proves
 * the PHP half: the write buffering, the read/write overlap analysis, the
 * savepoint truncation, the function substitution, the codec normalisation and
 * the integration with Drupal's own query builders and schema handling. It does
 * NOT prove anything about the Durable Object runtime - whether it accepts
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
use Drupal\sqlite\Driver\Database\sqlite\Connection as CoreSqliteConnection;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\SqlAnalyzer;
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
$connection->query('CREATE TABLE {other} (id INTEGER PRIMARY KEY, v TEXT)');
$connection
	->insert('other')
	->fields(['id' => 1, 'v' => 'committed'])
	->execute();
$transaction = $connection->startTransaction();
// a raw INSERT never asks for the new rowid, so it can still be buffered
$connection->query('INSERT INTO {t} (id, v) VALUES (1, :v)', [':v' => 'x']);
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
		->fields(['id' => 2, 'v' => 'y'])
		->execute();
} catch (UncommittedStateException $e) {
	$builderRefused = true;
}
ok('the insert query builder cannot run in a transaction on a degraded host', $builderRefused);
$transaction->commitOrRelease();
unset($transaction);
// two rows: the refused builder insert was already buffered before the id was
// asked for, and committing keeps it
ok(
	'the degraded commit still replays',
	(int) $host->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn() === 2,
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

echo "\nhost calls: {$host->execCalls} single, {$host->txnCalls} transactional\n";
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

<?php

/**
 * @file
 * Runs Drupal's own installer against the driver, end to end.
 *
 * Separate from the driver suite because run-driver-suite.php drives the
 * driver directly, in transactions of five or six statements. An install is a
 * different shape and the only one that exercises it: hundreds of statements in
 * one transaction, DDL and DML interleaved, and Drupal's own error-recovery
 * idioms firing inside an open transaction. It found a defect nothing else
 * could - see Connection::speculate().
 *
 * What it proves and what it does not. The host is FakeHost, so it proves the
 * PHP half against a real SQLite. It says nothing about ctx.storage.sql itself.
 *
 * The acceptance check is a CONTROL rather than a hard-coded expectation: the
 * same profile is installed a second time in a child process through Drupal's
 * own sqlite driver, and the two databases are compared table by table. A
 * hard-coded table list would only track whatever Drupal 11 happened to install
 * on the day it was written.
 *
 * Usage:
 *   php tests/run-installer.php [/path/to/drupal-root] [--keep]
 *
 * The Drupal root is only READ; everything is installed into a throwaway copy of
 * it under the system temp directory. --keep leaves that copy in place.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\Connection;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/fixtures/FakeHost.php';

// an install of the standard profile peaks well above the 128M some CLI builds
// default to, and the failure reads as an unrelated fatal
if (
	($limit = trim((string) ini_get('memory_limit'))) !== '-1' &&
	(int) $limit < 512 &&
	!str_ends_with($limit, 'G')
) {
	ini_set('memory_limit', '512M');
}

// #region arguments
$arguments = array_slice($argv, 1);
$GLOBALS['cfwFlags'] = array_values(
	array_filter($arguments, static fn($a) => str_starts_with($a, '--')),
);
$positional = array_values(array_filter($arguments, static fn($a) => !str_starts_with($a, '--')));

/**
 * Reads a --name=value flag.
 *
 * @param string $name
 *   The flag name, without the leading dashes.
 * @param string|null $default
 *   What to return when the flag is absent.
 *
 * @return string|null
 *   The value.
 */
function flag_value(string $name, ?string $default = null): ?string
{
	foreach ($GLOBALS['cfwFlags'] as $flag) {
		if (str_starts_with($flag, "--$name=")) {
			return substr($flag, strlen($name) + 3);
		}
	}
	return $default;
}

/**
 * Returns whether a bare flag was passed.
 *
 * @param string $name
 *   The flag name, without the leading dashes.
 *
 * @return bool
 *   TRUE when it is present.
 */
function has_flag(string $name): bool
{
	return in_array("--$name", $GLOBALS['cfwFlags'], true);
}

$source = $positional[0] ?? (getenv('DRUPAL_ROOT') ?: null);
if ($source === null || !is_file($source . '/vendor/autoload.php')) {
	fwrite(STDERR, "Pass a Drupal 11 root with vendor/ installed, or set DRUPAL_ROOT.\n");
	exit(2);
}
if (!is_dir($source . '/core/modules/sqlite/src')) {
	fwrite(STDERR, "That root has no core/modules/sqlite; this driver extends the core one.\n");
	exit(2);
}
$source = (string) realpath($source);

// the control installs the same profile through Drupal's own sqlite driver, so the
// two results can be compared; it runs in a child process because a second
// install_drupal() in one process would inherit the first one's statics
$isControl = has_flag('control');
$censusFile = flag_value('census');
$siteDirectory = $isControl ? 'sites/control' : 'sites/driver';

// #endregion
// #region the throwaway Drupal root
$root = (string) flag_value('shadow', sys_get_temp_dir() . '/cfw-do-sqlite-installer');

/**
 * Deletes a directory and everything under it.
 *
 * @param string $path
 *   The directory; a symlink is unlinked rather than followed.
 */
function remove_tree(string $path): void
{
	if (is_link($path) || is_file($path)) {
		@unlink($path);
		return;
	}
	if (!is_dir($path)) {
		return;
	}
	// a finished install leaves its site directory 0555 and settings.php 0444, which
	// is what stops a second run from cleaning up after the first
	@chmod($path, 0777);
	foreach (scandir($path) ?: [] as $entry) {
		if ($entry !== '.' && $entry !== '..') {
			@chmod($path . '/' . $entry, 0777);
			remove_tree($path . '/' . $entry);
		}
	}
	@rmdir($path);
}

/**
 * Copies a Drupal root into a writable throwaway, minus its sites directory.
 *
 * A copy rather than a symlink farm, because core resolves the application root
 * from its own __DIR__: symlink core/ and every path core computes points back at
 * the tree this is trying not to touch, so the driver module would never be
 * discovered. Block cloning makes the copy near-free where the filesystem has it.
 *
 * @param string $source
 *   The Drupal root to copy. Only read.
 * @param string $target
 *   Where to put the copy.
 *
 * @return string
 *   Which copy strategy was used, for the report.
 */
function clone_drupal_root(string $source, string $target): string
{
	remove_tree($target);
	mkdir($target, 0775, true);

	// sites/ is excluded deliberately: the source may hold an installed site, and
	// inheriting its settings.php would make the installer refuse
	$entries = array_diff(scandir($source) ?: [], ['.', '..', 'sites']);
	$strategies = ['cp -R --reflink=auto', 'cp -Rc', 'cp -R'];
	$used = 'cp -R';
	foreach ($strategies as $strategy) {
		$status = 0;
		$probe = [];
		exec(
			$strategy .
				' ' .
				escapeshellarg($source . '/core') .
				' ' .
				escapeshellarg($target . '/core') .
				' 2>/dev/null',
			$probe,
			$status,
		);
		if ($status === 0) {
			$used = $strategy;
			break;
		}
		remove_tree($target . '/core');
	}

	foreach ($entries as $entry) {
		if ($entry === 'core') {
			continue;
		}
		$status = 0;
		$output = [];
		exec(
			$used .
				' ' .
				escapeshellarg($source . '/' . $entry) .
				' ' .
				escapeshellarg($target . '/' . $entry) .
				' 2>/dev/null',
			$output,
			$status,
		);
	}

	mkdir($target . '/sites/default', 0775, true);
	foreach (['default.settings.php', 'default.services.yml'] as $file) {
		copy($source . '/sites/default/' . $file, $target . '/sites/default/' . $file);
	}

	// the driver has to be discoverable as a module, because that is how Drupal 11
	// finds a database driver: DatabaseDriverList scans for modules carrying
	// src/Driver/Database/<name>/Install/Tasks.php. src is symlinked rather than
	// copied so the installed site runs THIS working tree
	$module = $target . '/modules/custom/cfw_do_sqlite';
	mkdir($module, 0775, true);
	symlink(dirname(__DIR__) . '/src', $module . '/src');
	copy(dirname(__DIR__) . '/cfw_do_sqlite.info.yml', $module . '/cfw_do_sqlite.info.yml');

	return $used;
}

$copyStrategy = 'reused';
if (!$isControl) {
	$copyStrategy = clone_drupal_root($source, $root);
}
$root = (string) realpath($root);

$site = $root . '/' . $siteDirectory;
remove_tree($site);
mkdir($site . '/files', 0775, true);
copy($root . '/sites/default/default.settings.php', $site . '/settings.php');
chmod($site . '/settings.php', 0664);

// #endregion
// #region the host
$host = new FakeHost();
$execBridge = $host->execBridge();
$transactionBridge = $host->txnBridge();

$GLOBALS['recovered'] = [];
$GLOBALS['widest'] = ['commit' => 0, 'speculative' => 0];

// wrapped rather than installed raw, so the widest transaction and the errors Drupal
// recovered from can be counted without changing FakeHost, which has to stay
// byte-identical to the worker's copy
$GLOBALS['cfwBridges'] = [
	'cfwSqlExec' => static function (string $json) use ($execBridge): string {
		$reply = $execBridge($json);
		$decoded = json_decode($reply, true);
		if (($decoded['ok'] ?? false) !== true) {
			$GLOBALS['recovered'][] = (string) ($decoded['error'] ?? '?');
		}
		return $reply;
	},
	'cfwSqlTxn' => static function (string $json) use ($transactionBridge): string {
		$request = json_decode($json, true);
		$width = count($request['statements'] ?? []);
		$key = $request['commit'] ?? false ? 'commit' : 'speculative';
		$GLOBALS['widest'][$key] = max($GLOBALS['widest'][$key], $width);
		$reply = $transactionBridge($json);
		$decoded = json_decode($reply, true);
		if (($decoded['ok'] ?? false) !== true) {
			$GLOBALS['recovered'][] = (string) ($decoded['error'] ?? '?');
		}
		return $reply;
	},
];

/**
 * Stands in for vrzno's accessor onto the PHP Module.
 *
 * The driver resolves both bridges through this, and Install\Tasks decides the
 * driver is installable by asking it for the exec bridge - so defining it is what
 * makes cfw_do_sqlite appear on the installer's driver list at all.
 *
 * @param string $name
 *   The Module key.
 *
 * @return mixed
 *   The bridge, or NULL when the host has not installed one.
 */
function vrzno_env(string $name): mixed
{
	return $GLOBALS['cfwBridges'][$name] ?? null;
}

// #endregion
// #region the install
chdir($root);
if (!defined('MAINTENANCE_MODE')) {
	define('MAINTENANCE_MODE', 'install');
}
$classLoader = require_once $root . '/autoload.php';
// a tree packed for the wasm runtime aliases Fiber; a native install must not trip
// over the alias being absent
if (!class_exists('PhpWasmSyncFiber', false)) {
	class_alias(Fiber::class, 'PhpWasmSyncFiber');
}
require_once $root . '/core/includes/install.core.inc';

$driver = $isControl
	? 'Drupal\\sqlite\\Driver\\Database\\sqlite'
	: 'Drupal\\cfw_do_sqlite\\Driver\\Database\\cfw_do_sqlite';

$parameters = [
	'interactive' => false,
	'site_path' => $siteDirectory,
	'parameters' => ['profile' => 'standard', 'langcode' => 'en'],
	'forms' => [
		'install_settings_form' => [
			'driver' => $driver,
			$driver => [
				'database' => $isControl ? $siteDirectory . '/files/.sqlite' : 'durable-object',
			],
		],
		'install_configure_form' => [
			'site_name' => 'Durable Object Install',
			'site_mail' => 'drupal@example.com',
			'account' => [
				'name' => 'admin',
				'mail' => 'admin@example.com',
				'pass' => ['pass1' => 'installer-probe', 'pass2' => 'installer-probe'],
			],
			'enable_update_status_module' => true,
			// Checkboxes::valueCallback() wants NULL rather than FALSE here
			'enable_update_status_emails' => null,
		],
	],
];

$tasks = [];
$failure = null;
$started = microtime(true);
try {
	install_drupal($classLoader, $parameters, static function (array $state) use (&$tasks): void {
		$tasks[] = (string) ($state['active_task'] ?? '?');
	});
} catch (Throwable $e) {
	$failure = $e;
}
$elapsed = microtime(true) - $started;

$measured = [
	'statements' => count($host->statements),
	'execCalls' => $host->execCalls,
	'transactions' => $host->txnCalls,
	'speculative' => $host->speculativeCalls,
	'replayedStatements' => $host->replayedStatements,
	'widestCommit' => $GLOBALS['widest']['commit'],
	'widestSpeculative' => $GLOBALS['widest']['speculative'],
	'recoveredErrors' => count($GLOBALS['recovered']),
];

// #endregion
// #region the census
$built = null;

/**
 * Hands the error and exception handlers back to PHP.
 *
 * Drupal installs its own, and they load core/includes/errors.inc lazily. This
 * script deletes the tree it installed into, so any diagnostic raised afterwards
 * would send that handler looking for a file that no longer exists and turn a
 * warning into a fatal with an unrelated message.
 */
function release_drupal_error_handlers(): void
{
	while (set_error_handler(static fn(): bool => false) !== null) {
		restore_error_handler();
		restore_error_handler();
	}
	restore_error_handler();
	set_exception_handler(null);
}

/**
 * Counts the rows in every table of a database.
 *
 * @param PDO $database
 *   An open connection.
 *
 * @return array<string, int>
 *   Row count keyed by table name, in name order.
 */
function census(PDO $database): array
{
	$tables = $database
		->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
		->fetchAll(PDO::FETCH_COLUMN);
	$counts = [];
	foreach ($tables as $table) {
		$counts[$table] = (int) $database
			->query('SELECT COUNT(*) FROM "' . $table . '"')
			->fetchColumn();
	}
	return $counts;
}

if ($isControl) {
	release_drupal_error_handlers();
}
if ($failure === null) {
	if ($isControl) {
		// through Drupal's own connection rather than a fresh PDO: core's sqlite driver
		// declares its text columns COLLATE NOCASE_UTF8, a collation it registers per
		// connection, so a raw handle cannot even COUNT(*) those tables
		$client = Database::getConnection()->getClientConnection();
		$built = $client instanceof PDO ? census($client) : [];
	} else {
		$built = census($host->pdo);
	}
}

if ($isControl) {
	file_put_contents((string) $censusFile, json_encode($built ?? []));
	if ($failure !== null) {
		fwrite(STDERR, 'control install failed: ' . $failure->getMessage() . "\n");
		exit(1);
	}
	exit(0);
}

// #endregion
// #region the render
$render = ['status' => 0, 'bytes' => 0, 'siteName' => false, 'error' => null];
$counters = [];
if ($failure === null) {
	try {
		$request = Request::create('/', 'GET');
		$kernel = new DrupalKernel('prod', $classLoader);
		DrupalKernel::bootEnvironment();
		$kernel->setSitePath($siteDirectory);
		Settings::initialize($root, $siteDirectory, $classLoader);
		$kernel->boot();
		$response = $kernel->handle($request);
		$body = (string) $response->getContent();
		$render = [
			'status' => $response->getStatusCode(),
			'bytes' => strlen($body),
			'siteName' => str_contains($body, 'Durable Object Install'),
			'error' => null,
		];
		$connection = Drupal::database();
		if ($connection instanceof Connection) {
			$counters = [
				'statementCount' => $connection->statementCount(),
				'transactionCount' => $connection->transactionCount(),
				'speculativeCount' => $connection->speculativeCount(),
				'replayedStatementCount' => $connection->replayedStatementCount(),
			];
		}
		$kernel->terminate($request, $response);
	} catch (Throwable $e) {
		$render['error'] = get_class($e) . ': ' . $e->getMessage();
	}
}
release_drupal_error_handlers();

// #endregion
// #region the control run
$control = null;
$controlError = null;
if ($failure === null) {
	$censusPath = $root . '/control-census.json';
	$command =
		escapeshellarg(PHP_BINARY) .
		' -d opcache.enable_cli=0 -d xdebug.mode=off ' .
		escapeshellarg(__FILE__) .
		' ' .
		escapeshellarg($source) .
		' --control --shadow=' .
		escapeshellarg($root) .
		' --census=' .
		escapeshellarg($censusPath) .
		' 2>&1';
	$output = [];
	$status = 0;
	exec($command, $output, $status);
	if ($status === 0 && is_file($censusPath)) {
		$decoded = json_decode((string) file_get_contents($censusPath), true);
		$control = is_array($decoded) ? $decoded : null;
	}
	if ($control === null) {
		$controlError = implode("\n", array_slice($output, -5));
	}
}

// #endregion
// #region the report
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

echo "Install\n";
ok(
	'install_drupal() completed',
	$failure === null,
	$failure === null ? '' : get_class($failure) . ': ' . $failure->getMessage(),
);
ok('it ran the whole task list', in_array('install_finished', $tasks, true));
ok('the driver module installed itself', ($built['key_value'] ?? 0) > 0);
ok(
	'the router table exists and is populated',
	($built['router'] ?? 0) > 0,
	'router is created by MatcherDumper inside a transaction, which is the case that failed',
);
ok('user 1 exists', ($built['users'] ?? 0) > 0);
ok('config was written', ($built['config'] ?? 0) > 100);

echo "\nFront page\n";
ok(
	'a cold render returns 200',
	$render['status'] === 200,
	(string) ($render['error'] ?? $render['status']),
);
ok('and the response carries the site name', $render['siteName'] === true);

echo "\nAgainst core's own sqlite driver\n";
if ($control === null) {
	ok('the control install produced a census', false, (string) $controlError);
} else {
	$builtTables = array_keys($built ?? []);
	$controlTables = array_keys($control);
	ok(
		'the same tables exist',
		$builtTables === $controlTables,
		'only here: ' .
			implode(',', array_diff($builtTables, $controlTables)) .
			' | only there: ' .
			implode(',', array_diff($controlTables, $builtTables)),
	);

	// watchdog is deliberately not compared: a PHP deprecation raised by the runtime
	// rather than by Drupal lands in it, so it differs by PHP version and not by driver
	$differing = [];
	foreach ($built ?? [] as $table => $count) {
		if ($table === 'watchdog' || $table === 'key_value') {
			continue;
		}
		if (($control[$table] ?? null) !== $count) {
			$differing[] = $table . ' ' . $count . ' vs ' . ($control[$table] ?? 'absent');
		}
	}
	ok(
		'every other table holds the same number of rows',
		$differing === [],
		implode(', ', $differing),
	);
	ok(
		'key_value holds exactly one more row here',
		($built['key_value'] ?? 0) === ($control['key_value'] ?? 0) + 1,
		'the extra row is system.schema for cfw_do_sqlite, which core sqlite does not install',
	);
}

echo "\nCounters\n";
ok('the driver reported its counters', $counters !== []);
ok(
	'speculativeCount() agrees with the host',
	($counters['speculativeCount'] ?? -1) === $host->speculativeCalls,
	($counters['speculativeCount'] ?? -1) . ' vs ' . $host->speculativeCalls,
);
ok(
	'replayedStatementCount() agrees with the host',
	($counters['replayedStatementCount'] ?? -1) === $host->replayedStatements,
	($counters['replayedStatementCount'] ?? -1) . ' vs ' . $host->replayedStatements,
);
ok(
	'transactionCount() agrees with the host',
	($counters['transactionCount'] ?? -1) === $host->txnCalls,
	($counters['transactionCount'] ?? -1) . ' vs ' . $host->txnCalls,
);
ok(
	'replays outnumber first-time statements',
	$measured['replayedStatements'] > $measured['execCalls'],
	'if this ever stops holding, the O(W*R) term has been fixed and the README is stale',
);

printf(
	"\ninstall: %d statements in %.1fs -- %d single, %d transactional (%d rolled back over %d" .
		" replayed statements)\n",
	$measured['statements'],
	$elapsed,
	$measured['execCalls'],
	$measured['transactions'],
	$measured['speculative'],
	$measured['replayedStatements'],
);
printf(
	"widest transaction: %d statements committed, %d replayed speculatively\n",
	$measured['widestCommit'],
	$measured['widestSpeculative'],
);
printf(
	"%d error(s) raised and recovered by Drupal's own retry idioms\n",
	$measured['recoveredErrors'],
);
printf(
	"site: %d tables, %d rows; front page %d bytes\n",
	count($built ?? []),
	array_sum($built ?? []),
	$render['bytes'],
);
printf("throwaway root built with: %s\n", $copyStrategy);

if (has_flag('keep')) {
	echo "kept: $root\n";
} else {
	remove_tree($root);
	// Drupal registers a shutdown function that chdirs back to the application root,
	// so the directory itself has to survive or PHP reports a warning after the
	// summary; the contents are what matter
	@mkdir($root, 0775, true);
	echo "removed: $root\n";
}

printf("\n%d passed, %d failed\n", $pass, $fail);
// #endregion
exit($fail === 0 ? 0 : 1);

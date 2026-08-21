<?php

/**
 * @file
 * Runs every test suite in this repository under a coverage driver and writes one merged report.
 *
 * The suites are plain PHP scripts rather than PHPUnit, so there is no runner to ask for coverage.
 *
 * **It used to run one suite and the other two reported 0%.** `run-driver-suite.php` was the only
 * thing required here, so `src/pdo-shim.php` and
 * `src/Driver/Database/cfw_do_sqlite/Install/Tasks.php`
 * showed as untested while `tests/pdo-shim.php` (61 assertions) and `tests/run-installer.php` (16)
 * were passing all along. A coverage report that misses a whole runner does not read as
 * "unmeasured",
 * it reads as "untested", and that sent a backlog item after tests that already existed.
 *
 * **One child process per suite**, and that is forced rather than chosen. Every suite ends in
 * `exit()`, so requiring a second one after the first is unreachable; and `ok()` is declared by
 * both
 * `run-driver-suite.php` and `pdo-shim.php`, so requiring them into one process is a fatal
 * redeclare
 * even without the exits. Each child collects its own data, serialises it, and the parent merges.
 *
 * Only src/ is measured. tests/ is the instrument, and measuring the instrument inflates the number
 * without covering anything a consumer installs.
 *
 * Usage:
 *   php tests/coverage.php [/path/to/drupal-root]
 *   php tests/coverage.php --suite=<name> <root>    # one child; not for direct use
 *
 * Exits 2 without running anything when the Drupal root or the coverage driver is missing, exits 2
 * when a suite produces no data, and exits 2 when a report writer fails -- so a CI job cannot
 * report
 * a pass it did not measure. Exits 1 when a suite itself failed.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Node\Directory;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

/**
 * Every suite in this repository, the file each one drives, and whether it can be measured.
 *
 * Adding a suite here is the whole wiring. A suite absent from this list is a suite whose source
 * reports 0% while passing, which is the defect this list exists to prevent.
 *
 * `measured: false` means the suite is still RUN -- a failure still fails this command -- but no
 * coverage is collected over it, and the runner says so rather than letting its source read as
 * untested. There is exactly one, and the reason is structural:
 *
 * **`run-installer.php` needs two Drupal cores in one process and they collide.** Collecting
 * coverage requires `rom/vendor/autoload.php` for php-code-coverage, and this repo vendors its own
 * `drupal/core` for type checks; the suite clones a Drupal root and boots it. The second core to
 * load dies on `Cannot redeclare _system_default_theme_features()`. Unregistering the loader before
 * the suite does not help, because the collision is between two copies of core rather than between
 * two autoloaders. So `Install/Tasks.php` reads 0% and is NOT untested -- 16 assertions drive it.
 * Lifting this means either dropping `drupal/core` from this repo's require-dev, or collecting from
 * a driver that does not need the composer tree in-process.
 */
function coverage_suites(): array
{
	return [
		'driver' => ['file' => 'run-driver-suite.php', 'measured' => true],
		'pdo-shim' => ['file' => 'pdo-shim.php', 'measured' => true],
		'installer' => ['file' => 'run-installer.php', 'measured' => false],
	];
}

/**
 * The files coverage is collected over: everything under src/, and nothing else.
 *
 * @return list<string>
 *   Absolute paths, sorted.
 */
function coverage_measured_files(string $repo): array
{
	$walk = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($repo . '/src', FilesystemIterator::SKIP_DOTS),
	);
	$measured = [];
	foreach ($walk as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			$measured[] = $file->getPathname();
		}
	}
	sort($measured);
	return $measured;
}

/**
 * The value of `--name=` on the command line.
 *
 * @return string|null
 *   The value, or NULL when the flag is absent.
 */
function coverage_flag(string $name): ?string
{
	foreach ($GLOBALS['argv'] as $arg) {
		if (str_starts_with($arg, "--$name=")) {
			return substr($arg, strlen($name) + 3);
		}
	}
	return null;
}

/**
 * The first positional argument, so `--suite=x /root` and `/root` both resolve.
 *
 * @return string|null
 *   The Drupal root, falling back to `DRUPAL_ROOT`, or NULL when neither is set.
 */
function coverage_root(): ?string
{
	foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
		if (!str_starts_with($arg, '--')) {
			return $arg;
		}
	}
	return getenv('DRUPAL_ROOT') ?: null;
}

/**
 * Validates the environment and answers where everything is.
 *
 * @return array{repo: string, root: string, suite: ?string, out: string}
 *   Where everything is, and which suite this process is.
 */
function coverage_context(): array
{
	$repo = dirname(__DIR__);
	$root = coverage_root();

	if (!is_file($repo . '/vendor/autoload.php')) {
		fwrite(STDERR, "Run composer install first; vendor/autoload.php is missing.\n");
		exit(2);
	}
	if ($root === null || !is_file($root . '/vendor/autoload.php')) {
		fwrite(
			STDERR,
			"Pass a Drupal 11.2+ root with vendor/ installed, or set DRUPAL_ROOT.\n" .
				"Refusing to report coverage for a suite that cannot run.\n",
		);
		exit(2);
	}
	if (!extension_loaded('xdebug') && !extension_loaded('pcov')) {
		fwrite(
			STDERR,
			"No coverage driver: install xdebug or pcov.\n" .
				"Refusing to write an empty report that would read as 0% rather than as unmeasured.\n",
		);
		exit(2);
	}

	$out = $repo . '/coverage';
	if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
		fwrite(STDERR, "Could not create $out.\n");
		exit(2);
	}

	return ['repo' => $repo, 'root' => $root, 'suite' => coverage_flag('suite'), 'out' => $out];
}

$cfwCoverage = coverage_context();

/**
 * The child: collect over one suite and serialise the data for the parent.
 *
 * **Everything below runs at file scope**, and that is a requirement. A suite
 * required from inside a function gets that function's scope for its top-level variables, so
 * `$argv`
 * arrives null and `$pass`/`$fail` are local while `ok()` increments the globals -- which produced
 * a
 * suite that printed every assertion and then reported "0 passed, 0 failed".
 *
 * The parent's autoloader is not loaded here beyond this repo's own. Each suite bootstraps the
 * Drupal root it wants: `run-driver-suite.php` requires the root's, and `run-installer.php`
 * requires
 * the one in the throwaway root it clones. Loading the root's autoloader first made the installer
 * die on `Cannot redeclare ComposerAutoloaderInit...` -- the clone carries the same hash.
 */
if ($cfwCoverage['suite'] !== null) {
	$cfwSuite = coverage_suites()[$cfwCoverage['suite']] ?? null;
	if ($cfwSuite === null) {
		fwrite(STDERR, "Unknown suite: {$cfwCoverage['suite']}\n");
		exit(2);
	}
	$cfwSuiteFile = $cfwSuite['file'];

	// an unmeasured suite runs with nothing loaded ahead of it, which is the whole point: the
	// composer tree is what it collides with
	if (!$cfwSuite['measured']) {
		$argv = [__DIR__ . '/' . $cfwSuiteFile, $cfwCoverage['root']];
		$argc = 2;
		$GLOBALS['argv'] = $argv;
		$GLOBALS['argc'] = $argc;
		$_SERVER['argv'] = $argv;
		$_SERVER['argc'] = $argc;
		putenv('DRUPAL_ROOT=' . $cfwCoverage['root']);
		require __DIR__ . '/' . $cfwSuiteFile;
		return;
	}

	$cfwLoader = require $cfwCoverage['repo'] . '/vendor/autoload.php';
	// a guard rather than a type annotation: composer returns the loader, and a repo whose autoload
	// does not should fail here instead of fataling later on a missing class
	if (!($cfwLoader instanceof ClassLoader)) {
		fwrite(STDERR, "{$cfwCoverage['repo']}/vendor/autoload.php returned no ClassLoader.\n");
		exit(2);
	}

	$cfwFilter = new Filter();
	$cfwMeasured = coverage_measured_files($cfwCoverage['repo']);
	if ($cfwMeasured === []) {
		fwrite(STDERR, "No PHP files under {$cfwCoverage['repo']}/src to measure.\n");
		exit(2);
	}
	$cfwFilter->includeFiles($cfwMeasured);

	$cfwCollector = new CodeCoverage((new Selector())->forLineCoverage($cfwFilter), $cfwFilter);
	$cfwCollector->start($cfwCoverage['suite']);

	// the suite ends in exit(), which still runs shutdown handlers, so this is the only place the
	// data can be handed back from without editing the suite
	register_shutdown_function(static function () use (
		$cfwCollector,
		$cfwCoverage,
		$cfwLoader,
	): void {
		// re-registered here rather than warmed by hand below: stopping the collector autoloads
		// classes that are not reachable from the ones already in memory, and guessing that list is
		// how this failed on `Test\TestStatus` first. The suite is over, so nothing can collide now
		$cfwLoader->register();
		$cfwCollector->stop();
		file_put_contents(
			"{$cfwCoverage['out']}/.{$cfwCoverage['suite']}.cov",
			serialize([
				'lines' => $cfwCollector->getData()->lineCoverage(),
				'functions' => $cfwCollector->getData()->functionCoverage(),
			]),
		);
	});

	// `rom` vendors its own `drupal/core`, so a suite that boots a Drupal root with this loader
	// still registered dies on `Cannot redeclare _system_default_theme_features()`
	$cfwLoader->unregister();

	// every suite reads `$argv[1]` for the Drupal root, and `--suite=` is this wrapper's argument
	$argv = [__DIR__ . '/' . $cfwSuiteFile, $cfwCoverage['root']];
	$argc = 2;
	$GLOBALS['argv'] = $argv;
	$GLOBALS['argc'] = $argc;
	$_SERVER['argv'] = $argv;
	$_SERVER['argc'] = $argc;
	putenv('DRUPAL_ROOT=' . $cfwCoverage['root']);

	require __DIR__ . '/' . $cfwSuiteFile;
	return;
}

/**
 * The parent: run every suite in its own process, merge the data, write one report. */
(static function (array $context): void {
	['repo' => $repo, 'root' => $root, 'out' => $out] = $context;

	require $repo . '/vendor/autoload.php';

	$filter = new Filter();
	$measured = coverage_measured_files($repo);
	if ($measured === []) {
		fwrite(STDERR, "No PHP files under $repo/src to measure.\n");
		exit(2);
	}
	$filter->includeFiles($measured);

	$merged = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
	$data = $merged->getData();
	$failed = [];

	$unmeasured = [];
	foreach (coverage_suites() as $name => $suite) {
		$cov = "$out/.$name.cov";
		@unlink($cov);

		$command = sprintf(
			'%s -d opcache.enable_cli=0 %s --suite=%s %s',
			escapeshellarg(PHP_BINARY),
			escapeshellarg(__FILE__),
			escapeshellarg($name),
			escapeshellarg($root),
		);
		echo "\n=== $name ===\n";
		$status = 0;
		passthru($command, $status);
		if ($status !== 0) {
			$failed[] = $name;
		}

		if (!$suite['measured']) {
			$unmeasured[] = $name;
			continue;
		}

		// a suite that was SUPPOSED to be measured and produced no data is a broken instrument, and
		// reporting the others as if the set were complete is the failure this file exists to stop
		if (!is_file($cov)) {
			fwrite(STDERR, "\nSuite $name wrote no coverage data; refusing a partial set.\n");
			exit(2);
		}
		// arrays rather than a serialized ProcessedCodeCoverageData: `allowed_classes: false` makes
		// the handoff unable to instantiate anything, so a tampered .cov cannot become an object
		$parsed = unserialize((string) file_get_contents($cov), ['allowed_classes' => false]);
		if (!is_array($parsed)) {
			fwrite(STDERR, "\nSuite $name wrote unreadable coverage data.\n");
			exit(2);
		}
		$suiteData = new ProcessedCodeCoverageData();
		$suiteData->setLineCoverage($parsed['lines'] ?? []);
		$suiteData->setFunctionCoverage($parsed['functions'] ?? []);
		$data->merge($suiteData);
		@unlink($cov);
	}

	// BEFORE the report, not after. A failing suite covers less than a passing one, so writing the
	// numbers first and the failure second publishes a coverage figure for a run that did not pass
	if ($failed !== []) {
		fwrite(STDERR, "\nsuites failed: " . implode(', ', $failed) . "\n");
		fwrite(STDERR, "refusing to write a coverage report for a run that did not pass.\n");
		exit(1);
	}
	$merged->setData($data);

	// php-code-coverage 14 moved the writers off the collector and onto the Directory node it
	// builds; 11 and 12 still take the collector, so ask the signature rather than the version.
	// getReport() walks the whole tree, so it is built at most once
	$node = null;
	$reportFor = static function (object $writer) use ($merged, &$node): CodeCoverage|Directory {
		$first = (new ReflectionMethod($writer, 'process'))->getParameters()[0] ?? null;
		$type = $first?->getType();
		if ($type instanceof ReflectionNamedType && $type->getName() === Directory::class) {
			return $node ??= $merged->getReport();
		}
		return $merged;
	};

	try {
		$clover = new Clover();
		$text = new Text(Thresholds::default(), false, true);
		$clover->process($reportFor($clover), $out . '/rom.clover.xml');
		$summary = $text->process($reportFor($text), false);
	} catch (Throwable $e) {
		// the docblock above promises a job cannot report a pass it did not measure, and a writer
		// that threw measured nothing
		fwrite(STDERR, "\nCoverage report failed: " . $e->getMessage() . "\n");
		exit(2);
	}
	file_put_contents($out . '/rom.coverage.txt', $summary);
	echo "\n" . $summary;
	$measuredCount = count(coverage_suites()) - count($unmeasured);
	echo "merged $measuredCount suites into $out/rom.clover.xml and $out/rom.coverage.txt\n";

	// never a silent reduction: a source file that reads 0% because nothing collected over it looks
	// exactly like one nobody tested, and that difference is what sent a backlog item after tests
	// that already existed
	if ($unmeasured !== []) {
		echo 'RAN BUT NOT MEASURED: ' .
			implode(', ', $unmeasured) .
			" -- see coverage_suites() for why. Their source reads 0% and is not untested.\n";
	}
})($cfwCoverage);

<?php

/**
 * @file
 * Runs tests/run-driver-suite.php under a coverage driver and writes reports.
 *
 * The suite is a plain PHP script rather than PHPUnit, so there is no runner to ask
 * for coverage. This wraps it: start collection, require the suite, and write the
 * reports from a shutdown handler because the suite ends with exit().
 *
 * Only src/ is measured. tests/ is the instrument, and measuring the instrument
 * inflates the number without covering anything a consumer installs.
 *
 * Usage:
 *   php tests/coverage.php [/path/to/drupal-root]
 *
 * Exits 2 without running anything when the Drupal root or the coverage driver is
 * missing, so a CI job cannot report a pass it did not measure.
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

// the shutdown handler registered below is what keeps the collector alive, so this
// returns nothing and leaves no unused handle behind
(static function (): void {
	$repo = dirname(__DIR__);
	$root = $GLOBALS['argv'][1] ?? getenv('DRUPAL_ROOT') ?: null;

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

	// order matters: the Drupal root's autoloader has to register Drupal\Core first, or
	// this repo's own vendored drupal/core answers instead and the suite runs against a
	// different core than the one it was pointed at. Composer's autoload file is
	// idempotent, so the suite requiring it again gets this same loader back
	require $root . '/vendor/autoload.php';
	require $repo . '/vendor/autoload.php';

	$filter = new Filter();
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
	if ($measured === []) {
		fwrite(STDERR, "No PHP files under $repo/src to measure.\n");
		exit(2);
	}
	$filter->includeFiles($measured);

	$out = $repo . '/coverage';
	if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
		fwrite(STDERR, "Could not create $out.\n");
		exit(2);
	}

	$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
	$coverage->start('driver-suite');

	// the suite ends in exit(), which still runs shutdown handlers, so this is the only
	// place the reports can be written from without editing the suite
	register_shutdown_function(static function () use ($coverage, $out): void {
		$coverage->stop();
		try {
			(new Clover())->process($coverage, $out . '/rom.clover.xml');
			$summary = (new Text(Thresholds::default(), false, true))->process($coverage, false);
		} catch (\Throwable $e) {
			fwrite(STDERR, "\nCoverage report failed: " . $e->getMessage() . "\n");
			return;
		}
		file_put_contents($out . '/rom.coverage.txt', $summary);
		echo "\n" . $summary;
		echo "wrote $out/rom.clover.xml and $out/rom.coverage.txt\n";
	});
})();

require __DIR__ . '/run-driver-suite.php';

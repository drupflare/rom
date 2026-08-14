<?php

/**
 * @file
 * Runs php -l over every PHP file in the module.
 *
 * A syntax error in a Drupal module is not caught by anything else here: there is
 * no build step, and the class-loading gate needs a Drupal root while this needs
 * nothing but PHP. So this is the check that can always run.
 *
 * Usage:
 *   php tests/lint.php              # every PHP file in the repository
 *   php tests/lint.php <file>...    # only those files, for the pre-commit hook
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$skip = ['vendor', 'node_modules', 'drupal-root', '.git'];
// one source of truth for both the walk and the explicit-path branch below
$extensions = ['php'];

// Explicit paths win over the walk. The pre-commit hook passes the STAGED files, so a commit
// lints what it is committing rather than the whole tree; CI passes nothing and gets everything.
$argFiles = array_slice($argv, 1);
if ($argFiles !== []) {
	$files = [];
	foreach ($argFiles as $arg) {
		$path = realpath($arg);
		if ($path === false || !is_file($path)) {
			continue;
		}
		if (in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
			$files[] = $path;
		}
	}
	sort($files);
	// nothing lintable staged is a pass, not the "no PHP files" error below
	if ($files === []) {
		echo "no php files to lint\n";
		exit(0);
	}
} else {
	$files = [];
	$walk = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			static function (SplFileInfo $file) use ($skip): bool {
				return !($file->isDir() && in_array($file->getFilename(), $skip, true));
			},
		),
	);
	foreach ($walk as $file) {
		if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
			$files[] = $file->getPathname();
		}
	}
	sort($files);
}

if ($files === []) {
	fwrite(STDERR, "No PHP files found under $root.\n");
	exit(2);
}

$failed = 0;
foreach ($files as $file) {
	$output = [];
	$status = 0;
	// -n skips php.ini so the result does not depend on which extensions are loaded
	exec(
		escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($file) . ' 2>&1',
		$output,
		$status,
	);
	$relative = substr($file, strlen($root) + 1);
	if ($status === 0) {
		echo "  ok   $relative\n";
	} else {
		$failed++;
		echo "  FAIL $relative -- " . implode(' ', $output) . "\n";
	}
}

printf("\n%d file%s linted, %d failed\n", count($files), count($files) === 1 ? '' : 's', $failed);
exit($failed === 0 ? 0 : 1);

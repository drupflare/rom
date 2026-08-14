<?php

/**
 * @file
 * Checks src/pdo-shim.php against the real ext-pdo and against core's own usage.
 *
 * Usage:
 *   php tests/pdo-shim.php [/path/to/drupal-root]
 *
 * The Drupal root must be a checkout with core/ present; it is only read.
 */

declare(strict_types=1);

$root = $argv[1] ?? getenv('DRUPAL_ROOT') ?: null;
if ($root === null || !is_dir($root . '/core/lib/Drupal/Core/Database')) {
	fwrite(STDERR, "Pass a Drupal 11 root, or set DRUPAL_ROOT.\n");
	exit(2);
}
if (!extension_loaded('pdo')) {
	fwrite(STDERR, "This host has no ext-pdo, so there is no oracle to compare against.\n");
	exit(2);
}

$shim_path = dirname(__DIR__) . '/src/pdo-shim.php';

// the throwaway namespace the shim is re-declared into, so it can be compared
$probe_ns = 'CfwPdoShimProbe';

// where core keeps everything this driver inherits a PDO reference from
$core_paths = ['core/lib/Drupal/Core/Database', 'core/modules/sqlite/src'];

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
 * Every PHP file under a directory, sorted so the output is reproducible.
 *
 * @param string $dir
 *   The directory to walk.
 *
 * @return string[]
 *   Absolute paths.
 */
function php_files(string $dir): array
{
	$out = [];
	$walk = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
	);
	foreach ($walk as $file) {
		if ($file->isFile() && $file->getExtension() === 'php') {
			$out[] = $file->getPathname();
		}
	}
	sort($out);
	return $out;
}

/**
 * The PDO symbols one file resolves at runtime, comments excluded.
 *
 * Matches PDO::NAME in code tokens only, plus any mention of PDOException or
 * PDOStatement as a type. A ::class fetch and a lowercase-initial name are
 * separated out, because those are a class reference and a method call rather
 * than a constant.
 *
 * @param string $path
 *   The file to read.
 *
 * @return array{constants: string[], methods: string[], classes: string[]}
 *   The three kinds of reference, unsorted and possibly repeated.
 */
function pdo_references(string $path): array
{
	$found = ['constants' => [], 'methods' => [], 'classes' => []];
	$tokens = array_values(
		array_filter(
			token_get_all((string) file_get_contents($path)),
			// comments and whitespace are the whole reason this is not a regex
			static fn($t) => !is_array($t) ||
				!in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
		),
	);

	foreach ($tokens as $i => $token) {
		if (!is_array($token)) {
			continue;
		}
		$name = ltrim($token[1], '\\');
		if (in_array($name, ['PDOException', 'PDOStatement'], true)) {
			$found['classes'][] = $name;
			continue;
		}
		if ($name !== 'PDO') {
			continue;
		}
		$found['classes'][] = 'PDO';
		$next = $tokens[$i + 1] ?? null;
		$after = $tokens[$i + 2] ?? null;
		// T_DOUBLE_COLON arrives as an array, not as the literal '::'
		if (!is_array($next) || $next[0] !== T_DOUBLE_COLON || !is_array($after)) {
			continue;
		}
		$member = $after[1];
		if ($member === 'class') {
			continue;
		}
		$found[ctype_upper($member[0]) ? 'constants' : 'methods'][] = $member;
	}

	return $found;
}

/**
 * Re-declares the shim inside a namespace so it can run beside the real extension.
 *
 * @param string $path
 *   The shim's path.
 * @param string $namespace
 *   The throwaway namespace to declare it into.
 *
 * @throws RuntimeException
 *   If the guard is not where this expects it, which means the shim was
 *   restructured and this transformation no longer describes it.
 */
function declare_probe_copy(string $path, string $namespace): void
{
	// the guard the shim opens with; stripped so the copy declares rather than returns
	$guard = "if (class_exists('PDO', false)) {\n\treturn;\n}";
	// the global classes the shim references, which a namespaced copy has to import
	$imports = [
		'EmptyIterator',
		'Iterator',
		'IteratorAggregate',
		'RuntimeException',
		'Traversable',
	];

	$source = (string) file_get_contents($path);
	if (!str_contains($source, $guard)) {
		throw new RuntimeException(
			'src/pdo-shim.php no longer opens with the class_exists guard this rewrites.',
		);
	}
	$prologue = "<?php\n\nnamespace $namespace;\n";
	foreach ($imports as $class) {
		$prologue .= "use \\$class;\n";
	}
	$body = (string) preg_replace('/^<\?php/', '', str_replace($guard, '', $source), 1);

	// a temp file rather than eval(), so the copy is real PHP a linter could also read
	$copy = sys_get_temp_dir() . '/cfw-pdo-shim-probe-' . getmypid() . '.php';
	file_put_contents($copy, $prologue . $body);
	try {
		require $copy;
	} finally {
		unlink($copy);
	}
}

echo "\nThe shim declares what core resolves\n";

$constants = [];
$classes = [];
foreach ($core_paths as $relative) {
	foreach (php_files($root . '/' . $relative) as $file) {
		$refs = pdo_references($file);
		$constants = array_merge($constants, $refs['constants']);
		$classes = array_merge($classes, $refs['classes']);
	}
}
$constants = array_values(array_unique($constants));
$classes = array_values(array_unique($classes));
sort($constants);
sort($classes);

ok(
	'core resolves at least one PDO constant, so the scan found something',
	$constants !== [],
	(string) count($constants),
);

declare_probe_copy($shim_path, $probe_ns);
$probe = $probe_ns . '\\PDO';

foreach ($constants as $constant) {
	ok("PDO::$constant is declared", defined("$probe::$constant"));
	if (!defined("$probe::$constant")) {
		continue;
	}
	$mine = constant("$probe::$constant");
	$real = constant("PDO::$constant");
	ok(
		"PDO::$constant is $real, which is what the real extension says",
		$mine === $real,
		'shim ' . var_export($mine, true) . ' vs ext ' . var_export($real, true),
	);
}

foreach ($classes as $class) {
	ok("class $class is declared", class_exists($probe_ns . '\\' . $class, false));
}

echo "\nNothing extra, so the set does not drift upwards either\n";

$declared = array_keys(new ReflectionClass($probe)->getConstants());
sort($declared);
ok(
	'every constant the shim carries is one core resolves',
	$declared === $constants,
	'shim only ' .
		implode(',', array_diff($declared, $constants)) .
		' / core only ' .
		implode(',', array_diff($constants, $declared)),
);

echo "\nIt behaves like ext-pdo with no driver registered\n";

ok(
	'getAvailableDrivers() is empty, as it is with no driver compiled in',
	$probe::getAvailableDrivers() === [],
);

$thrown = null;
try {
	new $probe('sqlite::memory:');
} catch (Throwable $e) {
	$thrown = $e;
}
$probe_exception = $probe_ns . '\\PDOException';
ok('constructing throws', $thrown !== null);
ok('and it throws the shim PDOException', $thrown instanceof $probe_exception);
ok(
	"and the message is 'could not find driver', byte for byte what ext-pdo says",
	$thrown?->getMessage() === 'could not find driver',
	(string) $thrown?->getMessage(),
);
ok(
	'and the code is 0, as ext-pdo leaves it',
	$thrown?->getCode() === 0,
	var_export($thrown?->getCode(), true),
);

// the real extension validates the DSN before it looks for a driver, so a name with no
// scheme separator fails differently; the control below reads both off this process
$invalid = null;
try {
	new $probe('garbage');
} catch (Throwable $e) {
	$invalid = $e;
}
$real_invalid = null;
try {
	new PDO('garbage');
} catch (Throwable $e) {
	$real_invalid = $e;
}
ok(
	'a DSN with no colon fails argument validation instead',
	$invalid?->getMessage() ===
		'PDO::__construct(): Argument #1 ($dsn) must be a valid data source name',
	(string) $invalid?->getMessage(),
);
ok(
	'and that message is quoted from the extension in this process, not from documentation',
	$real_invalid?->getMessage() === $invalid?->getMessage(),
	(string) $real_invalid?->getMessage(),
);

$probe_statement = $probe_ns . '\\PDOStatement';
$statement = new $probe_statement();
ok(
	'PDOStatement is Traversable, so a foreach over one still type checks',
	$statement instanceof Traversable,
);
ok('and iterating it yields nothing', iterator_to_array($statement) === []);
ok(
	'PDOException extends RuntimeException, as the real one does',
	is_a($probe_exception, RuntimeException::class, true),
);

echo "\nAnd it is inert where the extension exists\n";

// a subprocess, because this process has already re-declared the probe copy
$inert = shell_exec(
	escapeshellarg(PHP_BINARY) .
		' -r ' .
		escapeshellarg(
			'require ' .
				var_export($shim_path, true) .
				'; echo (new ReflectionClass("PDO"))->isInternal() ? "internal" : "userland";',
		) .
		' 2>&1',
);
ok(
	'requiring the shim on a host with ext-pdo leaves PDO internal',
	trim((string) $inert) === 'internal',
	trim((string) $inert),
);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

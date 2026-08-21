<?php

/**
 * @file
 * Declaration-only stub for the vrzno extension, for static analysis only.
 *
 * **This file must never be loaded at runtime.** `vrzno` registers `vrzno_env()` when the extension
 * initialises, so including this inside the wasm build is a hard `Cannot redeclare vrzno_env()`
 * fatal during boot, before a byte is served. Three mechanisms keep it out and all three are
 * load-bearing:
 *
 * 1. It is NOT in `composer.json` autoload -- no `files`, no `classmap`, no PSR-4 root reaches
 *    `stubs/`.
 * 2. `.gitattributes` marks `stubs/` `export-ignore`, so it is absent from the Packagist archive.
 * 3. `worker/scripts/gen-driver-assets.ts` skips `stubs/` when packing this module into
 *    `assets/driver.json`, which is the copy that executes on the edge.
 *
 * Every call site already guards with `function_exists('vrzno_env')` -- see
 * `CfwSqlClient::env()` and `Install\Tasks`. PHPStan understands that narrowing and reports
 * nothing; intelephense does not and has no inline suppression for an undefined function. The
 * alternative was disabling `undefinedFunctions` wholesale, which would hide every genuinely
 * misspelled function in the repo. A stub costs one unreachable file.
 *
 * The signature is deliberately `mixed`: the real return is a vrzno-wrapped JS value, an object
 * that `is_callable()` may not recognise even when it is invocable. Declaring `callable` would
 * assert something the runtime does not guarantee.
 */

if (!\function_exists('vrzno_env')) {
	/**
	 * Resolves a name on the emscripten Module object, as surfaced by vrzno.
	 *
	 * `vrzno_env($name)` is `Module[$name]` -- the host functions the Durable Object installs on
	 * the module, including the SQL exec and transaction bridges.
	 *
	 * @param string $name
	 *   The Module property to read.
	 *
	 * @return mixed
	 *   The wrapped JS value, or NULL when the name is not present.
	 */
	function vrzno_env(string $name): mixed
	{
		// never executed: the extension owns this symbol at runtime
		throw new \LogicException('vrzno stub called; the extension is not loaded');
	}
}

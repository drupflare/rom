<?php

/**
 * @file
 * Userland PDO, PDOException and PDOStatement, for a build with no ext-pdo.
 */

// an internal class is always present without autoloading, so `false` is correct here
if (class_exists('PDO', false)) {
	return;
}

/**
 * The database-error exception ext-pdo would declare.
 *
 * Core reaches this by name three ways: `catch (PDOException $e)` in the sqlite
 * driver, `$e instanceof PDOException` in Drupal\Core\Database\ExceptionHandler
 * and Drupal\Core\Utility\Error, and `@throws` docblocks. Neither the catch nor
 * the instanceof needs the class to exist, so what this is really declared for is
 * the throw the PDO constructor performs.
 */
class PDOException extends RuntimeException
{
	/**
	 * The driver-specific error information, in ext-pdo's three-element shape.
	 *
	 * @var array|null
	 */
	public ?array $errorInfo = null;
}

/**
 * The connection class ext-pdo would declare.
 *
 * Not final, because core's PDOConnection extends it.
 */
class PDO
{
	/**
	 * The fetch modes, as ext/pdo/php_pdo_driver.h numbers them.
	 */
	public const FETCH_DEFAULT = 0;
	public const FETCH_LAZY = 1;
	public const FETCH_ASSOC = 2;
	public const FETCH_NUM = 3;
	public const FETCH_BOTH = 4;
	public const FETCH_OBJ = 5;
	public const FETCH_BOUND = 6;
	public const FETCH_COLUMN = 7;
	public const FETCH_CLASS = 8;
	public const FETCH_INTO = 9;
	public const FETCH_FUNC = 10;
	public const FETCH_NAMED = 11;
	public const FETCH_KEY_PAIR = 12;

	/**
	 * The two fetch FLAGS, which core ORs into FETCH_CLASS.
	 *
	 * PHP 8.5 REPACKED THESE and nothing else in this file moved with them.
	 * php_pdo_driver.h widened PDO_FETCH_FLAGS from 0xFFFF0000 to 0xFFFFFFF0 and
	 * moved the flags down from the high half-word to bits 5-9, so CLASSTYPE went
	 * 0x00040000 -> 1<<7 and PROPS_LATE went 0x00100000 -> 1<<8. Read out of the
	 * headers: 8.3.11 and 8.4.1 carry the old layout, 8.5.2 and 8.5.7 the new one.
	 * tests/pdo-shim.php checks both against whatever extension is running, which
	 * is what makes the 80500 boundary a claim CI can refute rather than a guess.
	 */
	public const FETCH_CLASSTYPE = PHP_VERSION_ID >= 80500 ? 1 << 7 : 0x00040000;
	public const FETCH_PROPS_LATE = PHP_VERSION_ID >= 80500 ? 1 << 8 : 0x00100000;

	/**
	 * The bound-parameter type Drupal\Core\Database\Connection::quote() defaults to.
	 */
	public const PARAM_STR = 2;

	/**
	 * The connection attributes core's sqlite driver sets or reads.
	 *
	 * ATTR_ERRMODE and ATTR_STRINGIFY_FETCHES are set in that driver's open();
	 * ATTR_SERVER_VERSION and ATTR_CLIENT_VERSION are read by the base
	 * Connection's version() and clientVersion(), both of which this driver
	 * overrides.
	 */
	public const ATTR_ERRMODE = 3;
	public const ATTR_SERVER_VERSION = 4;
	public const ATTR_CLIENT_VERSION = 5;
	public const ATTR_STRINGIFY_FETCHES = 17;

	/**
	 * The one error mode core names.
	 */
	public const ERRMODE_EXCEPTION = 2;

	/**
	 * Refuses to connect, with the two messages ext-pdo raises, in its own order.
	 *
	 * Measured on the real extension: a DSN with no colon fails argument
	 * validation before any driver lookup happens, and one with a colon reaches
	 * the lookup and finds nothing. Both are PDOException with code 0.
	 *
	 * The username, password and options ext-pdo declares are not declared here.
	 * PHP hands a userland function any extra positional arguments without
	 * complaint, so `new PDO($dsn, '', '', $options)` -- the shape core's sqlite
	 * driver uses -- still constructs and still throws. The cost is exact:
	 * `new PDO(dsn: ...)` works, `new PDO(username: ...)` does not, and nothing in
	 * Drupal passes those three by name.
	 *
	 * @param string $dsn
	 *   The data source name.
	 *
	 * @throws PDOException
	 *   Always.
	 */
	public function __construct(string $dsn)
	{
		if (!str_contains($dsn, ':')) {
			throw new PDOException(
				'PDO::__construct(): Argument #1 ($dsn) must be a valid data source name',
			);
		}
		throw new PDOException('could not find driver');
	}

	/**
	 * The registered driver names, of which there are none.
	 *
	 * Drupal\Core\Database\Install\Tasks::installable() calls this to decide
	 * whether a driver can be offered at install time. This driver's own Tasks
	 * replaces that check with the host-bridge test, so an empty list is the
	 * honest answer rather than a value anything depends on.
	 *
	 * The 8.4+ static connect() is NOT carried. It cannot succeed here for the
	 * same reason the constructor cannot, and no reachable call site names it.
	 *
	 * @return string[]
	 *   Always empty.
	 */
	public static function getAvailableDrivers(): array
	{
		return [];
	}
}

/**
 * The prepared-statement class ext-pdo would declare.
 *
 * Core names it as a return type on StatementWrapperIterator and
 * StatementPrefetchIterator, and as a promoted property type on
 * Drupal\Core\Database\Statement\PdoResult. This driver instantiates none of the
 * three, so the class exists to make those declarations resolvable.
 *
 * The interface list matches ext-pdo's own, which is why Traversable is named
 * explicitly alongside IteratorAggregate rather than left implied: `foreach` over
 * a statement is part of the published shape, and a `Traversable` type check on
 * one has to keep answering true. It yields nothing here, which is the only answer
 * available to a statement that can hold no rows.
 */
class PDOStatement implements Traversable, IteratorAggregate
{
	/**
	 * The SQL of the prepared statement, read by PdoTrait::clientQueryString().
	 *
	 * Left uninitialised rather than defaulted to '', matching the real class:
	 * reading it before it is set is an Error there and stays one here.
	 *
	 * @var string
	 */
	public string $queryString;

	/**
	 * {@inheritdoc}
	 */
	public function getIterator(): Iterator
	{
		return new EmptyIterator();
	}
}

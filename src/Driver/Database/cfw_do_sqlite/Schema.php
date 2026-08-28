<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\InvalidQueryException;
use Drupal\sqlite\Driver\Database\sqlite\Schema as SqliteDriverSchema;

/**
 * Schema handling for ctx.storage.sql.
 *
 * The core sqlite schema is correct here almost in full: the table rebuild dance
 * that stands in for ALTER TABLE, the type map, the sqlite_master introspection
 * and the index handling all work unchanged, so none of it is forked.
 *
 * One thing cannot work. The core driver registers a user-space collation named
 * NOCASE_UTF8 through PDO::sqliteCreateCollation() and puts it on every
 * non-binary VARCHAR and TEXT column. ctx.storage.sql has no user-defined
 * collations at all - measured, "no such collation sequence: NOCASE_UTF8:
 * SQLITE_ERROR_MISSING_COLLSEQ" - so every CREATE TABLE the core schema emits
 * would fail. The builtin NOCASE is substituted instead.
 *
 * Builtin NOCASE folds ASCII only, also measured:
 * 'Hello World' matches 'hello world', 'Unicode' with a diaeresis does not match
 * its lower-case form. So case-insensitive comparison of non-ASCII text is
 * case-sensitive on this driver. That affects username and email uniqueness,
 * taxonomy term matching and LIKE, for non-ASCII text only. It is a documented
 * product limitation rather than something the driver can fix, because the fix
 * lives in the runtime's SQLite build.
 *
 * One more thing cannot be inherited, and it is the table prefix. Every other
 * prefixed method here works unchanged, because getPrefixInfo() puts the prefix
 * on the front of the table name and this driver's prefix is exactly that. Only
 * findTables() reads the prefix the other way round - the core sqlite version
 * assumes a prefixed table lives in its own ATTACHed schema and therefore carries
 * no prefix in sqlite_master, which is false here and would return nothing at all.
 */
class Schema extends SqliteDriverSchema
{
	/**
	 * The connection, typed to this driver so its synthetic schema list is reachable.
	 */
	private readonly Connection $driverConnection;

	/**
	 * Constructs a Schema.
	 *
	 * @param object $connection
	 *   The connection this schema belongs to.
	 *
	 * @throws HostBridgeException
	 *   If the connection is not this driver's.
	 */
	public function __construct($connection)
	{
		if (!($connection instanceof Connection)) {
			throw new HostBridgeException(
				sprintf(
					'The cfw_do_sqlite schema needs this driver\'s Connection, got %s.',
					get_debug_type($connection),
				),
			);
		}
		parent::__construct($connection);
		$this->driverConnection = $connection;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function createFieldSql($name, $spec)
	{
		$sql = parent::createFieldSql($name, $spec);

		// rewriting the emitted SQL rather than reimplementing 45 lines of column
		// building; the first occurrence is always the collation clause, because the
		// parent appends it before any DEFAULT literal
		return (string) preg_replace('/ COLLATE NOCASE_UTF8/', ' COLLATE NOCASE', $sql, 1);
	}

	/**
	 * {@inheritdoc}
	 *
	 * A cache bin is stored as its primary-key B-tree rather than as a rowid table.
	 *
	 * Rows written is the meter that binds regeneration here, and a rowid table gives a TEXT
	 * PRIMARY KEY its own unique index - so one stored row is charged twice. Every bin
	 * DatabaseBackend creates keys on a TEXT cid and carries no secondary index on this
	 * runtime, so that autoindex is the entire index cost. Measured on a steady-state render:
	 * 8 charged rows to 6.
	 *
	 * The 14 bins in the shipped pack are emitted this way by scripts/pack-sql.ts. This covers
	 * the ones a module adds at runtime, which that packer never sees.
	 */
	public function createTableSql($name, $table)
	{
		$sql = parent::createTableSql($name, $table);

		if (isset($sql[0]) && $this->storesAsPrimaryKey($name, $table)) {
			$sql[0] = rtrim($sql[0]) . " WITHOUT ROWID\n";
		}

		return $sql;
	}

	/**
	 * Whether a table should be created WITHOUT ROWID.
	 *
	 * Narrow on purpose. Only cache bins are converted: they are the tables measured, they are
	 * on the fill path, and their rows are keyed by a hash rather than ordered by insertion.
	 * A serial column is refused outright because sqlite forbids AUTOINCREMENT on a WITHOUT
	 * ROWID table, and a table with no declared primary key has nothing to be stored by.
	 *
	 * @param string $name
	 *   The unprefixed table name.
	 * @param array $table
	 *   The Drupal schema definition.
	 *
	 * @return bool
	 *   TRUE when the table is a cache bin that can be stored by its key.
	 */
	private function storesAsPrimaryKey(string $name, array $table): bool
	{
		if (!str_starts_with($name, 'cache_')) {
			return false;
		}
		if (empty($table['primary key']) || !is_array($table['primary key'])) {
			return false;
		}
		foreach ($table['fields'] ?? [] as $spec) {
			if (($spec['type'] ?? '') === 'serial') {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Callers pass an unprefixed expression - core's own are 'test%', 'migrate_map_d7_node%'
	 * and '%' - and expect unprefixed names back, so the prefix is applied on the way in and
	 * stripped on the way out. The core sqlite version does neither, because there a prefixed
	 * table is in another schema under its bare name.
	 */
	public function findTables($table_expression)
	{
		$prefix = $this->driverConnection->getPrefix();
		$pattern = $prefix . $table_expression;

		if (strlen($pattern) > Connection::MAX_LIKE_PATTERN_BYTES) {
			throw new InvalidQueryException(
				sprintf(
					'findTables() would send a %d-byte LIKE pattern and ctx.storage.sql refuses any pattern over %d bytes ("pattern too complex"). The prefix is part of that length. Shorten the prefix or the expression.',
					strlen($pattern),
					Connection::MAX_LIKE_PATTERN_BYTES,
				),
			);
		}

		$tables = [];
		foreach ($this->driverConnection->getAttachedDatabases() as $schema) {
			// the schema cannot be a placeholder: the statement would have to read
			// :prefixsqlite_master, which is not a thing
			$result = $this->driverConnection->query(
				'SELECT name FROM [' .
					$schema .
					'].sqlite_master WHERE type = :type AND name LIKE :table_name AND name NOT LIKE :pattern',
				[
					':type' => 'table',
					':table_name' => $pattern,
					':pattern' => 'sqlite_%',
				],
			);
			foreach ($result->fetchAllKeyed(0, 0) as $name) {
				// LIKE narrows, PHP proves: an underscore in the prefix is a LIKE wildcard,
				// so the pattern can over-select. It can never under-select, which is what
				// makes checking the survivors here sufficient rather than merely tidy
				if (!str_starts_with($name, $prefix)) {
					continue;
				}
				$unprefixed = substr($name, strlen($prefix));
				// a table whose whole name is the prefix is not a prefixed table, and an
				// empty key would surprise the caller
				if ($unprefixed === '') {
					continue;
				}
				$tables[$unprefixed] = $unprefixed;
			}
		}

		return $tables;
	}
}

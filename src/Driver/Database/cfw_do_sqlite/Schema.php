<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

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
 * THE LIMITATION THAT BUYS. Builtin NOCASE folds ASCII only, also measured:
 * 'Hello World' matches 'hello world', 'Unicode' with a diaeresis does not match
 * its lower-case form. So case-insensitive comparison of non-ASCII text is
 * case-SENSITIVE on this driver. That affects username and email uniqueness,
 * taxonomy term matching and LIKE, for non-ASCII text only. It is a documented
 * product limitation rather than something the driver can fix, because the fix
 * lives in the runtime's SQLite build.
 */
class Schema extends SqliteDriverSchema
{
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
}

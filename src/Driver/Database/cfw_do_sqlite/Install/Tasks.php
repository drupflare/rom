<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\Install;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Install\Tasks as InstallTasks;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite\CfwSqlClient;

/**
 * Install-time checks for the Durable Object SQLite driver.
 *
 * The base class decides a driver is installable by looking for its PDO driver
 * in PDO::getAvailableDrivers(). There is no PDO driver to find, so the check
 * that matters instead is whether the host installed the bridge - which is the
 * same as asking whether PHP is running inside the Durable Object that owns the
 * database.
 *
 * The inherited task list (CREATE, INSERT, UPDATE, DELETE, DROP, then a JSON
 * check) is kept, because those are exactly the statements the runtime needs to
 * accept for Drupal to function.
 */
class Tasks extends InstallTasks
{
	use StringTranslationTrait;

	/**
	 * Minimum SQLite version, matching the core sqlite driver's requirement.
	 *
	 * Drupal needs the json1 extension for its JSON column support.
	 */
	const SQLITE_MINIMUM_VERSION = '3.45';

	/**
	 * {@inheritdoc}
	 */
	public function name()
	{
		// rendered here rather than returned lazily because the interface documents a
		// string; both callers (a form option label and a t() placeholder) stringify
		// it in the same request anyway
		return $this->t('Durable Object SQLite')->render();
	}

	/**
	 * {@inheritdoc}
	 */
	public function minimumVersion()
	{
		return static::SQLITE_MINIMUM_VERSION;
	}

	/**
	 * {@inheritdoc}
	 */
	public function installable()
	{
		return $this->hasHostBridge();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function connect()
	{
		if (!$this->hasHostBridge()) {
			$this->fail(
				$this->t(
					"The host has not installed the '@bridge' function. This driver requires PHP to run inside the Durable Object that owns ctx.storage.sql.",
					[
						'@bridge' => CfwSqlClient::EXEC_BRIDGE,
					],
				),
			);
			return false;
		}

		try {
			Database::setActiveConnection();
			Database::getConnection();
			$this->pass('Drupal can CONNECT to the database ok.');
		} catch (\Exception $e) {
			// there is no database file to create and no credentials to get wrong, so
			// unlike the core sqlite driver there is no recovery path to attempt here
			$this->fail(
				$this->t(
					'Failed to reach the Durable Object database. The bridge reports: %error.',
					[
						'%error' => $e->getMessage(),
					],
				),
			);
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getFormOptions(array $database)
	{
		$form = parent::getFormOptions($database);

		// none of these apply: the Durable Object's identity is the address, there
		// is no port, no credentials, and prefixes need an ATTACH that does not
		// exist here
		unset(
			$form['username'],
			$form['password'],
			$form['advanced_options']['host'],
			$form['advanced_options']['port'],
			$form['advanced_options']['prefix'],
		);

		$form['database']['#title'] = $this->t('Database Label');
		$form['database']['#description'] = $this->t(
			'Recorded in settings.php for reference only. The Durable Object instance selects the database, so this value does not route anything.',
		);
		$form['database']['#default_value'] = empty($database['database'])
			? 'durable-object'
			: $database['database'];

		return $form;
	}

	/**
	 * Returns whether the host installed the SQL bridge.
	 *
	 * @return bool
	 *   TRUE when the single-statement entry point is reachable.
	 */
	protected function hasHostBridge(): bool
	{
		if (!function_exists('vrzno_env')) {
			return false;
		}

		$bridge = vrzno_env(CfwSqlClient::EXEC_BRIDGE);
		return is_object($bridge) || is_callable($bridge);
	}
}

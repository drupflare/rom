<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\DatabaseException;
use RuntimeException;

/**
 * Thrown when the JS host side of the bridge is missing or misbehaving.
 *
 * This is never a SQL error. It means PHP is not running where the driver
 * requires it to run (inside the Durable Object that owns ctx.storage.sql), or
 * that the host returned something the codec cannot represent.
 */
final class HostBridgeException extends RuntimeException implements DatabaseException {}

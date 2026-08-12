<?php

declare(strict_types=1);

namespace Drupal\cfw_do_sqlite\Driver\Database\cfw_do_sqlite;

use Drupal\Core\Database\DatabaseException;
use RuntimeException;

/**
 * Thrown when a query would need to observe buffered, uncommitted state.
 *
 * The driver buffers writes issued inside a Drupal transaction because
 * ctx.storage.sql refuses SQL transaction control. A read issued against a
 * table that the buffer has written therefore cannot be answered from the
 * committed database, and answering it from the committed database anyway would
 * return data that is quietly wrong. When the host exposes no way to evaluate
 * the read against the buffer, the driver raises this instead of guessing.
 *
 * @see Connection::runStatement()
 */
final class UncommittedStateException extends RuntimeException implements DatabaseException {}

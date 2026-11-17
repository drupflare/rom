<?php

declare(strict_types=1);

namespace Drupflare\Rom\Sql;

/**
 * One token of a MySQL statement.
 *
 * Offsets and lengths count bytes, so substr($sql, $offset, $length) equals
 * $text for every token, and the texts of all tokens in order concatenate to
 * the input.
 */
final class Token
{
	/**
	 * Constructs a Token.
	 *
	 * @param TokenType $type
	 *   The lexical class.
	 * @param int $offset
	 *   Byte offset of the first byte in the input.
	 * @param int $length
	 *   Length in bytes.
	 * @param string $text
	 *   The source bytes, quotes and prefixes included.
	 */
	public function __construct(
		public readonly TokenType $type,
		public readonly int $offset,
		public readonly int $length,
		public readonly string $text,
	) {}
}

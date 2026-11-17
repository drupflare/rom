<?php

declare(strict_types=1);

namespace Drupflare\Rom\Sql;

/**
 * Converts string literal bodies between MySQL's escapes and SQLite's.
 */
final class Literal
{
	/**
	 * Applies MySQL's backslash escapes, so the value in hand is the value in the column.
	 *
	 * Backslash-percent and backslash-underscore keep their backslash, as MySQL
	 * documents: they only mean a literal wildcard inside a LIKE pattern, and
	 * outside one they stay two characters. Dropping the backslash would turn
	 * every esc_like() pattern into a match-anything pattern.
	 *
	 * @param string $raw
	 *   The text between the quotes.
	 * @param string $quote
	 *   (optional) The quote character the literal used, whose doubled form is
	 *   one literal quote. Defaults to a single quote.
	 *
	 * @return string
	 *   The decoded bytes.
	 */
	public static function decodeMysqlString(string $raw, string $quote = "'"): string
	{
		$length = strlen($raw);
		$stops = '\\' . $quote;
		$out = '';
		$i = 0;
		while ($i < $length) {
			$run = strcspn($raw, $stops, $i);
			if ($run > 0) {
				$out .= substr($raw, $i, $run);
				$i += $run;
				continue;
			}
			if ($raw[$i] === $quote) {
				// a doubled quote is the quote character; a lone one is kept as written
				$out .= $quote;
				$i += $i + 1 < $length && $raw[$i + 1] === $quote ? 2 : 1;
				continue;
			}
			if ($i + 1 >= $length) {
				$out .= '\\';
				break;
			}
			$escaped = $raw[$i + 1];
			$i += 2;
			$out .= match ($escaped) {
				'0' => "\0",
				'n' => "\n",
				'r' => "\r",
				't' => "\t",
				'b' => "\x08",
				// ASCII 26, which MySQL escapes because Windows reads it as end-of-file
				'Z' => "\x1a",
				'%' => '\\%',
				'_' => '\\_',
				default => $escaped,
			};
		}
		return $out;
	}

	/**
	 * A SQLite literal for one decoded value.
	 *
	 * A value carrying a NUL becomes CAST(x'..' AS TEXT), because SQLite's
	 * parser ends a string literal at the NUL and the rest of the row becomes
	 * syntax.
	 *
	 * @param string $value
	 *   The decoded bytes.
	 *
	 * @return string
	 *   SQL text that reads back as exactly those bytes.
	 */
	public static function encodeSqliteString(string $value): string
	{
		if (str_contains($value, "\0")) {
			return "CAST(x'" . bin2hex($value) . "' AS TEXT)";
		}
		return "'" . str_replace("'", "''", $value) . "'";
	}
}

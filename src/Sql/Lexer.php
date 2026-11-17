<?php

declare(strict_types=1);

namespace Drupflare\Rom\Sql;

/**
 * A byte-oriented MySQL lexer.
 *
 * Every byte of the input lands in exactly one token, whitespace and comments
 * included, so the token texts concatenate back to the input. Malformed input
 * never throws: it becomes an Error token and lexing carries on, so a
 * statement the translator does not understand is refused by name later
 * instead of vanishing here. Nothing here uses mbstring; bytes 0x80 to 0xFF
 * are identifier bytes, which is how MySQL treats multibyte names.
 *
 * Placeholders are not special. wpdb finishes the SQL before it reaches this
 * class, so a question mark is an operator byte and a colon name is an
 * operator followed by an identifier.
 */
final class Lexer
{
	private const WHITESPACE = " \t\n\r\f\v";

	private const DIGITS = '0123456789';

	private const HEX_DIGITS = '0123456789abcdefABCDEF';

	private const BIT_DIGITS = '01';

	private const THREE_BYTE_OPERATORS = ['<=>' => true, '->>' => true];

	private const TWO_BYTE_OPERATORS = [
		'<>' => true,
		'!=' => true,
		'<=' => true,
		'>=' => true,
		'||' => true,
		'&&' => true,
		'<<' => true,
		'>>' => true,
		':=' => true,
		'->' => true,
	];

	/**
	 * Bytes that continue an identifier, built on first use.
	 */
	private static ?string $identifierBytes = null;

	/**
	 * Splits SQL into tokens.
	 *
	 * @param string $sql
	 *   One statement or a whole script.
	 *
	 * @return list<Token>
	 *   Tokens in source order, covering every byte.
	 */
	public static function tokenize(string $sql): array
	{
		$identifierBytes = self::$identifierBytes ??= self::buildIdentifierBytes();
		$variableBytes = $identifierBytes . '.';
		$length = strlen($sql);
		$tokens = [];
		$previous = TokenType::Whitespace;
		$i = 0;
		while ($i < $length) {
			$c = $sql[$i];
			$type = TokenType::Error;
			$end = $i + 1;
			switch ($c) {
				case ' ':
				case "\n":
				case "\t":
				case "\r":
				case "\f":
				case "\v":
					$type = TokenType::Whitespace;
					$end = $i + strspn($sql, self::WHITESPACE, $i);
					break;

				case "'":
				case '"':
					$close = self::scanQuoted($sql, $i, $length, $c, true);
					$type = $close < 0 ? TokenType::Error : TokenType::String;
					$end = $close < 0 ? $length : $close;
					break;

				case '`':
					$close = self::scanQuoted($sql, $i, $length, '`', false);
					$type = $close < 0 ? TokenType::Error : TokenType::QuotedIdentifier;
					$end = $close < 0 ? $length : $close;
					break;

				case '0':
				case '1':
				case '2':
				case '3':
				case '4':
				case '5':
				case '6':
				case '7':
				case '8':
				case '9':
					$type = TokenType::Number;
					$end = $i;
					if ($c === '0' && $i + 1 < $length) {
						$marker = $sql[$i + 1];
						if ($marker === 'x' || $marker === 'X') {
							$run = strspn($sql, self::HEX_DIGITS, $i + 2);
							$type = TokenType::Hex;
							$end = $run > 0 ? $i + 2 + $run : $i;
						} elseif ($marker === 'b' || $marker === 'B') {
							$run = strspn($sql, self::BIT_DIGITS, $i + 2);
							$type = TokenType::Bit;
							$end = $run > 0 ? $i + 2 + $run : $i;
						}
					}
					$hasPoint = false;
					if ($end === $i) {
						$type = TokenType::Number;
						$end = $i + strspn($sql, self::DIGITS, $i);
						if ($end < $length && $sql[$end] === '.') {
							$hasPoint = true;
							$end++;
							$end += strspn($sql, self::DIGITS, $end);
						}
						$end = self::scanExponent($sql, $length, $end);
					}
					// digits that run into letters are an identifier, as in MySQL
					if (
						!$hasPoint &&
						$end < $length &&
						strspn($sql, $identifierBytes, $end, 1) === 1
					) {
						$type = TokenType::Identifier;
						$end += strspn($sql, $identifierBytes, $end);
					}
					break;

				case '.':
					if (
						$i + 1 < $length &&
						strspn($sql, self::DIGITS, $i + 1, 1) === 1 &&
						$previous !== TokenType::Identifier &&
						$previous !== TokenType::QuotedIdentifier
					) {
						$type = TokenType::Number;
						$end = self::scanExponent(
							$sql,
							$length,
							$i + 1 + strspn($sql, self::DIGITS, $i + 1),
						);
					} else {
						$type = TokenType::Punct;
					}
					break;

				case '(':
				case ')':
				case ',':
				case ';':
				case '{':
				case '}':
					$type = TokenType::Punct;
					break;

				case '#':
					$type = TokenType::Comment;
					$end = $i + strcspn($sql, "\n", $i);
					break;

				case '/':
					if ($i + 1 < $length && $sql[$i + 1] === '*') {
						$close = strpos($sql, '*/', $i + 2);
						if ($close === false) {
							$end = $length;
						} else {
							$end = $close + 2;
							$executable =
								($i + 2 < $length && $sql[$i + 2] === '!') ||
								substr($sql, $i + 2, 2) === 'M!';
							$type = $executable ? TokenType::ExecutableComment : TokenType::Comment;
						}
					} else {
						$type = TokenType::Operator;
					}
					break;

				case '-':
					$after = $i + 2 < $length ? ord($sql[$i + 2]) : 0;
					if (
						$i + 1 < $length &&
						$sql[$i + 1] === '-' &&
						($after <= 0x20 || $after === 0x7f)
					) {
						// a dash comment needs whitespace or a control byte after the two dashes
						$type = TokenType::Comment;
						$end = $i + strcspn($sql, "\n", $i);
					} else {
						$type = TokenType::Operator;
						$end = $i + self::operatorLength($sql, $i);
					}
					break;

				case '+':
				case '*':
				case '%':
				case '=':
				case '<':
				case '>':
				case '!':
				case '&':
				case '|':
				case '^':
				case '~':
				case ':':
				case '?':
					$type = TokenType::Operator;
					$end = $i + self::operatorLength($sql, $i);
					break;

				case '@':
					$name = $i + 1;
					if ($name < $length && $sql[$name] === '@') {
						$name++;
					}
					$quote = $name < $length ? $sql[$name] : '';
					if ($quote === "'" || $quote === '"' || $quote === '`') {
						$close = self::scanQuoted($sql, $name, $length, $quote, $quote !== '`');
						$type = $close < 0 ? TokenType::Error : TokenType::Variable;
						$end = $close < 0 ? $length : $close;
					} else {
						$run = strspn($sql, $variableBytes, $name);
						$type = $run > 0 ? TokenType::Variable : TokenType::Error;
						$end = $name + $run;
					}
					break;

				default:
					$byte = ord($c);
					$lower = $byte | 0x20;
					if (
						$byte < 0x80 &&
						$c !== '_' &&
						$c !== '$' &&
						($lower < 0x61 || $lower > 0x7a)
					) {
						// a stray byte MySQL has no token for
						break;
					}
					$type = TokenType::Identifier;
					$end = $i + strspn($sql, $identifierBytes, $i);
					if ($i + 1 < $length && $sql[$i + 1] === "'") {
						$prefixed = match ($c) {
							'x', 'X' => TokenType::Hex,
							'b', 'B' => TokenType::Bit,
							'n', 'N' => TokenType::NationalString,
							default => null,
						};
						if ($prefixed !== null) {
							[$type, $end] = self::scanPrefixed($sql, $i, $length, $prefixed);
						}
					}
					break;
			}
			$tokens[] = new Token($type, $i, $end - $i, substr($sql, $i, $end - $i));
			if (
				$type !== TokenType::Whitespace &&
				$type !== TokenType::Comment &&
				$type !== TokenType::ExecutableComment
			) {
				$previous = $type;
			}
			$i = $end;
		}
		return $tokens;
	}

	/**
	 * Splits a script into statements at top-level semicolons.
	 *
	 * A scanner rather than a split on a semicolon, because a semicolon inside
	 * a string, a quoted name or a comment is not a boundary. Comments are
	 * dropped and leave one space behind, so the words on either side do not
	 * fuse; executable comments stay, because MySQL runs their contents.
	 *
	 * @param string $sql
	 *   The script.
	 *
	 * @return list<string>
	 *   Trimmed, non-empty statements without their terminating semicolons.
	 */
	public static function splitStatements(string $sql): array
	{
		$statements = [];
		$current = '';
		foreach (self::tokenize($sql) as $token) {
			if ($token->type === TokenType::Comment) {
				$current .= ' ';
			} elseif ($token->type === TokenType::Punct && $token->text === ';') {
				$trimmed = trim($current, self::WHITESPACE);
				if ($trimmed !== '') {
					$statements[] = $trimmed;
				}
				$current = '';
			} else {
				$current .= $token->text;
			}
		}
		$trimmed = trim($current, self::WHITESPACE);
		if ($trimmed !== '') {
			$statements[] = $trimmed;
		}
		return $statements;
	}

	/**
	 * Finds the parenthesis that closes the one at a token index.
	 *
	 * Parentheses inside strings, quoted names and comments are single tokens
	 * of another class, so they never count.
	 *
	 * @param list<Token> $tokens
	 *   The result of tokenize().
	 * @param int $open
	 *   Index of the opening parenthesis token.
	 *
	 * @return int
	 *   Index of the closing token, or -1 when $open is not an opening
	 *   parenthesis or is never closed.
	 */
	public static function matchingParen(array $tokens, int $open): int
	{
		if (
			!isset($tokens[$open]) ||
			$tokens[$open]->text !== '(' ||
			$tokens[$open]->type !== TokenType::Punct
		) {
			return -1;
		}
		$depth = 0;
		for ($i = $open, $count = count($tokens); $i < $count; $i++) {
			if ($tokens[$i]->type !== TokenType::Punct) {
				continue;
			}
			if ($tokens[$i]->text === '(') {
				$depth++;
			} elseif ($tokens[$i]->text === ')' && --$depth === 0) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Builds the byte set that continues an identifier.
	 *
	 * @return string
	 *   Letters, digits, underscore, dollar and every byte from 0x80 up.
	 */
	private static function buildIdentifierBytes(): string
	{
		$bytes = '0123456789_$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		for ($byte = 0x80; $byte <= 0xff; $byte++) {
			$bytes .= chr($byte);
		}
		return $bytes;
	}

	/**
	 * Finds the end of a quoted run.
	 *
	 * @param string $sql
	 *   The input.
	 * @param int $start
	 *   Offset of the opening quote.
	 * @param int $length
	 *   Length of the input.
	 * @param string $quote
	 *   The quote byte.
	 * @param bool $backslash
	 *   Whether a backslash escapes the next byte.
	 *
	 * @return int
	 *   The offset just past the closing quote, or -1 when it never closes.
	 */
	private static function scanQuoted(
		string $sql,
		int $start,
		int $length,
		string $quote,
		bool $backslash,
	): int {
		$stops = $backslash ? $quote . '\\' : $quote;
		$i = $start + 1;
		while (true) {
			$i += strcspn($sql, $stops, $i);
			if ($i >= $length) {
				return -1;
			}
			if ($sql[$i] === '\\') {
				$i += 2;
				if ($i > $length) {
					return -1;
				}
				continue;
			}
			// a doubled quote is the quote character, not the end
			if ($i + 1 < $length && $sql[$i + 1] === $quote) {
				$i += 2;
				continue;
			}
			return $i + 1;
		}
	}

	/**
	 * Lexes X'..', b'..' and N'..' from the prefix letter.
	 *
	 * @param string $sql
	 *   The input.
	 * @param int $start
	 *   Offset of the prefix letter.
	 * @param int $length
	 *   Length of the input.
	 * @param TokenType $type
	 *   Hex, Bit or NationalString.
	 *
	 * @return array{0: TokenType, 1: int}
	 *   The token class and the offset just past it. A hex or bit body with a
	 *   digit it cannot hold is an Error that runs to the closing quote.
	 */
	private static function scanPrefixed(
		string $sql,
		int $start,
		int $length,
		TokenType $type,
	): array {
		if ($type === TokenType::NationalString) {
			$close = self::scanQuoted($sql, $start + 1, $length, "'", true);
			return $close < 0 ? [TokenType::Error, $length] : [$type, $close];
		}
		$close = strpos($sql, "'", $start + 2);
		if ($close === false) {
			return [TokenType::Error, $length];
		}
		$digits = $type === TokenType::Hex ? self::HEX_DIGITS : self::BIT_DIGITS;
		$body = $close - ($start + 2);
		$valid = $body === 0 || strspn($sql, $digits, $start + 2, $body) === $body;
		return [$valid ? $type : TokenType::Error, $close + 1];
	}

	/**
	 * Extends a number past an exponent such as e5, E+5 or e-5.
	 *
	 * @param string $sql
	 *   The input.
	 * @param int $length
	 *   Length of the input.
	 * @param int $end
	 *   Offset just past the mantissa.
	 *
	 * @return int
	 *   The offset past the exponent, or $end when there is none.
	 */
	private static function scanExponent(string $sql, int $length, int $end): int
	{
		if ($end >= $length || ($sql[$end] !== 'e' && $sql[$end] !== 'E')) {
			return $end;
		}
		$digits = $end + 1;
		if ($digits < $length && ($sql[$digits] === '+' || $sql[$digits] === '-')) {
			$digits++;
		}
		$run = $digits < $length ? strspn($sql, self::DIGITS, $digits) : 0;
		return $run > 0 ? $digits + $run : $end;
	}

	/**
	 * Measures the operator that starts at an offset.
	 *
	 * @param string $sql
	 *   The input.
	 * @param int $i
	 *   Offset of the operator's first byte.
	 *
	 * @return int
	 *   3, 2 or 1.
	 */
	private static function operatorLength(string $sql, int $i): int
	{
		$three = substr($sql, $i, 3);
		if (isset(self::THREE_BYTE_OPERATORS[$three])) {
			return 3;
		}
		return isset(self::TWO_BYTE_OPERATORS[substr($three, 0, 2)]) ? 2 : 1;
	}
}

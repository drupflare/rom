<?php

/**
 * @file
 * Checks the MySQL lexer in src/Sql/ with no Drupal root and no database.
 *
 * Run it as "php tests/sql-lexer.php". It needs nothing but PHP.
 */

declare(strict_types=1);

use Drupflare\Rom\Sql\Lexer;
use Drupflare\Rom\Sql\Literal;
use Drupflare\Rom\Sql\Token;
use Drupflare\Rom\Sql\TokenType;

require_once dirname(__DIR__) . '/src/Sql/TokenType.php';
require_once dirname(__DIR__) . '/src/Sql/Token.php';
require_once dirname(__DIR__) . '/src/Sql/Lexer.php';
require_once dirname(__DIR__) . '/src/Sql/Literal.php';

$pass = 0;
$fail = 0;

/**
 * Records one assertion and prints its result.
 *
 * @param string $label
 *   What the assertion claims.
 * @param bool $condition
 *   The result of evaluating that claim.
 * @param string $detail
 *   Extra context printed only on failure.
 */
function ok(string $label, bool $condition, string $detail = ''): void
{
	global $pass, $fail;
	if ($condition) {
		$pass++;
		echo "  ok   $label\n";
	} else {
		$fail++;
		echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
	}
}

/**
 * Lexes SQL and renders each token as "type:text".
 *
 * @param string $sql
 *   The input.
 * @param bool $trivia
 *   (optional) Whether whitespace tokens are kept. Defaults to FALSE.
 *
 * @return list<string>
 *   One entry per token.
 */
function lex(string $sql, bool $trivia = false): array
{
	$out = [];
	foreach (Lexer::tokenize($sql) as $token) {
		if (!$trivia && $token->type === TokenType::Whitespace) {
			continue;
		}
		$out[] = $token->type->value . ':' . $token->text;
	}
	return $out;
}

/**
 * Asserts that SQL lexes to exactly the given tokens.
 *
 * @param string $label
 *   What the assertion claims.
 * @param string $sql
 *   The input.
 * @param list<string> $expected
 *   The "type:text" entries, whitespace excluded.
 */
function lexes(string $label, string $sql, array $expected): void
{
	$actual = lex($sql);
	ok(
		$label,
		$actual === $expected,
		json_encode($actual, JSON_INVALID_UTF8_SUBSTITUTE) .
			' vs ' .
			json_encode($expected, JSON_INVALID_UTF8_SUBSTITUTE),
	);
}

/**
 * Checks the offset, length and round-trip contract for one input.
 *
 * @param string $sql
 *   The input.
 *
 * @return bool
 *   Whether every slice matches and the texts concatenate to the input.
 */
function contract_holds(string $sql): bool
{
	$joined = '';
	$expected_offset = 0;
	foreach (Lexer::tokenize($sql) as $token) {
		if (
			$token->offset !== $expected_offset ||
			$token->length !== strlen($token->text) ||
			$token->length < 1 ||
			substr($sql, $token->offset, $token->length) !== $token->text
		) {
			return false;
		}
		$expected_offset += $token->length;
		$joined .= $token->text;
	}
	return $joined === $sql && $expected_offset === strlen($sql);
}

echo "Whitespace, identifiers and punctuation\n";
lexes('a select list lexes into words and commas', 'SELECT a, b FROM t', [
	'identifier:SELECT',
	'identifier:a',
	'punct:,',
	'identifier:b',
	'identifier:FROM',
	'identifier:t',
]);
ok(
	'every whitespace byte class is one whitespace token',
	lex(" \t\n\r\f\v", true) === ["whitespace: \t\n\r\f\v"],
);
lexes('dollar, underscore and high bytes continue an identifier', "wp_a\$b caf\xc3\xa9", [
	'identifier:wp_a$b',
	"identifier:caf\xc3\xa9",
]);
lexes('a dot between names is punctuation', 'wp.posts.ID', [
	'identifier:wp',
	'punct:.',
	'identifier:posts',
	'punct:.',
	'identifier:ID',
]);
lexes('parentheses, semicolons and braces are punctuation', '(a);{b}', [
	'punct:(',
	'identifier:a',
	'punct:)',
	'punct:;',
	'punct:{',
	'identifier:b',
	'punct:}',
]);

echo "\nQuoted identifiers\n";
lexes('a backtick identifier is one token', '`post_title`', ['quoted_identifier:`post_title`']);
lexes('a doubled backtick stays inside the identifier', '`a``b` x', [
	'quoted_identifier:`a``b`',
	'identifier:x',
]);
lexes('a semicolon inside backticks is not punctuation', '`a;b`', ['quoted_identifier:`a;b`']);
lexes('a backslash is literal inside backticks', '`a\\` b', [
	'quoted_identifier:`a\\`',
	'identifier:b',
]);
lexes('an unterminated backtick is one error to the end', 'a `bc d', [
	'identifier:a',
	'error:`bc d',
]);

echo "\nStrings\n";
lexes('a single quoted string is one token', "'hello world'", ["string:'hello world'"]);
lexes('a double quoted string is a string, as in default sql_mode', '"abc"', ['string:"abc"']);
lexes('a doubled single quote stays inside the string', "'it''s' x", [
	"string:'it''s'",
	'identifier:x',
]);
lexes('a doubled double quote stays inside the string', '"a""b" x', [
	'string:"a""b"',
	'identifier:x',
]);
lexes('a backslash quote does not end the string', "'a\\'b' x", ["string:'a\\'b'", 'identifier:x']);
lexes('an escaped backslash lets the next quote end the string', "'a\\\\' x", [
	"string:'a\\\\'",
	'identifier:x',
]);
lexes('a backslash double quote does not end a double quoted string', '"a\\"b" x', [
	'string:"a\\"b"',
	'identifier:x',
]);
lexes('semicolons and comment markers inside a string are text', "'a; -- b # c /* d */'", [
	"string:'a; -- b # c /* d */'",
]);
lexes('an empty string is a string', "''", ["string:''"]);
lexes('an unterminated string is one error to the end', "a 'bc d", ['identifier:a', "error:'bc d"]);
lexes('a string that ends in a lone backslash is an error', "'abc\\", ["error:'abc\\"]);
lexes('a national string is its own class', "N'abc' n'd'", [
	"national_string:N'abc'",
	"national_string:n'd'",
]);
lexes('an N without a quote is an identifier', 'N x', ['identifier:N', 'identifier:x']);
lexes('a charset introducer is an identifier then a string', "_utf8mb4'x'", [
	'identifier:_utf8mb4',
	"string:'x'",
]);
$long_literal = "'" . str_repeat('q', 5000) . "'";
$long_tokens = Lexer::tokenize("SELECT $long_literal FROM t");
ok(
	'a 5000 byte string is one token of 5002 bytes',
	count($long_tokens) === 7 && $long_tokens[2]->length === 5002 && $long_tokens[2]->offset === 7,
);
$long_escaped = "'" . str_repeat("a''b\\'c;", 700) . "'";
$escaped_tokens = Lexer::tokenize($long_escaped . ';');
ok(
	'a long string full of doubled quotes and escapes is still one token',
	count($escaped_tokens) === 2 && $escaped_tokens[0]->length === strlen($long_escaped),
);
ok('the long inputs satisfy the offset contract', contract_holds($long_literal . $long_escaped));

echo "\nComments\n";
lexes('a dash comment needs whitespace after the dashes', "SELECT 1 -- note\nFROM t", [
	'identifier:SELECT',
	'number:1',
	'comment:-- note',
	'identifier:FROM',
	'identifier:t',
]);
lexes('two dashes followed by a digit are two minus operators', '5--1', [
	'number:5',
	'operator:-',
	'operator:-',
	'number:1',
]);
lexes('two dashes at the end of the input are a comment', 'a --', ['identifier:a', 'comment:--']);
lexes('two dashes then a tab are a comment', "a --\tx\nb", [
	'identifier:a',
	"comment:--\tx",
	'identifier:b',
]);
lexes('a hash comment runs to the end of the line', "a # x ' y\nb", [
	'identifier:a',
	"comment:# x ' y",
	'identifier:b',
]);
lexes('a block comment is one token', 'a /* x; y */ b', [
	'identifier:a',
	'comment:/* x; y */',
	'identifier:b',
]);
lexes('a block comment spans lines', "/* a\nb */x", ["comment:/* a\nb */", 'identifier:x']);
lexes('the star slash search starts after the opener', '/*/ x */ y', [
	'comment:/*/ x */',
	'identifier:y',
]);
lexes('a bang comment is executable', '/*!40101 SET x=1 */', [
	'executable_comment:/*!40101 SET x=1 */',
]);
lexes('a MariaDB comment is executable', '/*M!100100 x */', ['executable_comment:/*M!100100 x */']);
lexes('an optimizer hint comment is a plain comment', '/*+ NO_RANGE_OPTIMIZATION(t) */', [
	'comment:/*+ NO_RANGE_OPTIMIZATION(t) */',
]);
lexes('an unterminated block comment is one error to the end', 'a /* b', [
	'identifier:a',
	'error:/* b',
]);
lexes('a lone slash is an operator', 'a / b', ['identifier:a', 'operator:/', 'identifier:b']);

echo "\nNumbers, hex and bit values\n";
lexes('integers and decimals', '1 22 3.5 4. .5', [
	'number:1',
	'number:22',
	'number:3.5',
	'number:4.',
	'number:.5',
]);
lexes('exponents with and without a sign', '1e5 2E+3 4.5e-6 .5E2', [
	'number:1e5',
	'number:2E+3',
	'number:4.5e-6',
	'number:.5E2',
]);
lexes('an e without digits is not an exponent', '1e', ['identifier:1e']);
lexes('digits that run into letters are an identifier', '12abc 1ea', [
	'identifier:12abc',
	'identifier:1ea',
]);
lexes('a leading dot after a name is punctuation', 't.5', ['identifier:t', 'punct:.', 'number:5']);
lexes('0x hex literal', '0xDEADbeef', ['hex:0xDEADbeef']);
lexes('X quote hex literal', "X'ff00' x'AB' X''", ["hex:X'ff00'", "hex:x'AB'", "hex:X''"]);
lexes('a bad X quote body is an error through the closing quote', "X'zz' a", [
	"error:X'zz'",
	'identifier:a',
]);
lexes('an unterminated X quote is an error to the end', "X'ab", ["error:X'ab"]);
lexes('0b bit literal', '0b1011', ['bit:0b1011']);
lexes('b quote bit literal', "b'101' B'0'", ["bit:b'101'", "bit:B'0'"]);
lexes('a bad b quote body is an error', "b'12'", ["error:b'12'"]);
lexes('0x with no digits is an identifier', '0xzz', ['identifier:0xzz']);
lexes('hex digits that run into letters are an identifier', '0x1Fg', ['identifier:0x1Fg']);
lexes('a bare x is an identifier', 'x', ['identifier:x']);

echo "\nVariables and placeholders\n";
lexes('a user variable', '@a', ['variable:@a']);
lexes('a system variable with a scope', '@@global.sql_mode', ['variable:@@global.sql_mode']);
lexes('a quoted user variable', "@'a b' @`c` @\"d\"", [
	"variable:@'a b'",
	'variable:@`c`',
	'variable:@"d"',
]);
lexes('an assignment keeps the variable and the operator apart', '@a:=1', [
	'variable:@a',
	'operator::=',
	'number:1',
]);
lexes('an at sign with no name is an error', '@ @@', ['error:@', 'error:@@']);
lexes('a question mark is an operator byte, not a placeholder', 'a = ?', [
	'identifier:a',
	'operator:=',
	'operator:?',
]);
lexes('a colon name is an operator then an identifier', ':name', ['operator::', 'identifier:name']);

echo "\nOperators\n";
lexes('the multi-byte operators', '<=> <> != <= >= || && << >> := -> ->>', [
	'operator:<=>',
	'operator:<>',
	'operator:!=',
	'operator:<=',
	'operator:>=',
	'operator:||',
	'operator:&&',
	'operator:<<',
	'operator:>>',
	'operator::=',
	'operator:->',
	'operator:->>',
]);
lexes('the one-byte operators', '+ - * / % = < > ! & | ^ ~ :', [
	'operator:+',
	'operator:-',
	'operator:*',
	'operator:/',
	'operator:%',
	'operator:=',
	'operator:<',
	'operator:>',
	'operator:!',
	'operator:&',
	'operator:|',
	'operator:^',
	'operator:~',
	'operator::',
]);
lexes('a longer operator wins over a shorter prefix', 'a<=>b a<=b', [
	'identifier:a',
	'operator:<=>',
	'identifier:b',
	'identifier:a',
	'operator:<=',
	'identifier:b',
]);
lexes('a json path operator takes a string', "c->'$.a'", [
	'identifier:c',
	'operator:->',
	"string:'$.a'",
]);

echo "\nMalformed and binary input\n";
lexes('a backslash outside a string is an error', 'a \\ b', [
	'identifier:a',
	'error:\\',
	'identifier:b',
]);
lexes('square brackets are errors', '[a]', ['error:[', 'identifier:a', 'error:]']);
lexes('a NUL byte outside a string is an error', "a\0b", [
	'identifier:a',
	"error:\0",
	'identifier:b',
]);
lexes('a NUL byte inside a string stays in the string', "'a\0b'", ["string:'a\0b'"]);
$binary = '';
for ($byte = 0; $byte < 256; $byte++) {
	$binary .= chr($byte);
}
ok('every byte value lexes and round-trips', contract_holds($binary));
$quoted_binary = "'" . str_replace(["'", '\\'], ['', ''], $binary) . "'";
ok(
	'binary bytes inside quotes make one string token',
	count(Lexer::tokenize($quoted_binary)) === 1,
);
ok('an empty input has no tokens', Lexer::tokenize('') === []);
$tokens = Lexer::tokenize("SELECT 'a'");
ok(
	'a token has a type, offset, length and text',
	$tokens[2] instanceof Token &&
		$tokens[2]->type === TokenType::String &&
		$tokens[2]->offset === 7 &&
		$tokens[2]->length === 3 &&
		$tokens[2]->text === "'a'",
);

mt_srand(20261116);
$fuzz_alphabet = "abc019 \t\n'\"`\\/*-#!@.;(),:?<>=|&+0xXbBnN_\$\0\x80\xff";
$fuzz_ok = true;
$fuzz_error = '';
for ($round = 0; $round < 3000; $round++) {
	$input = '';
	$size = mt_rand(0, 40);
	for ($k = 0; $k < $size; $k++) {
		$input .= $fuzz_alphabet[mt_rand(0, strlen($fuzz_alphabet) - 1)];
	}
	try {
		if (!contract_holds($input)) {
			$fuzz_ok = false;
			$fuzz_error = bin2hex($input);
			break;
		}
	} catch (Throwable $e) {
		$fuzz_ok = false;
		$fuzz_error = $e->getMessage() . ' on ' . bin2hex($input);
		break;
	}
}
ok('3000 random inputs never throw and always round-trip', $fuzz_ok, $fuzz_error);

echo "\nRound trip over realistic statements\n";
$statements = [
	"SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE 1=1 AND wp_posts.post_type = 'post' AND ((wp_posts.post_status = 'publish')) GROUP BY wp_posts.ID ORDER BY wp_posts.post_date DESC LIMIT 0, 10",
	"INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('a', 'b\\'c;d', 'yes') ON DUPLICATE KEY UPDATE `option_name` = VALUES(`option_name`)",
	"SELECT * FROM wp_postmeta WHERE meta_value LIKE '%\\_x\\%%' -- trailing\n# another\n/* block */",
	"SET @@session.sql_mode = 'NO_ZERO_DATE', @x := 0x1F, @y = b'101', @z = 1.5e3",
	"/*!40101 SET NAMES utf8mb4 */; SELECT N'\xc3\xa9' <=> X'ff'",
];
foreach ($statements as $index => $statement) {
	ok('statement ' . ($index + 1) . ' round-trips with exact offsets', contract_holds($statement));
}
ok(
	'a real post query has no error tokens',
	array_filter(
		Lexer::tokenize($statements[0]),
		static fn(Token $t): bool => $t->type === TokenType::Error,
	) === [],
);

echo "\nSplitting statements\n";
ok(
	'statements split at top-level semicolons',
	Lexer::splitStatements('SELECT 1; SELECT 2;') === ['SELECT 1', 'SELECT 2'],
);
ok(
	'a semicolon inside a string, a backtick name or a comment is not a boundary',
	Lexer::splitStatements("INSERT INTO t VALUES ('a;b', `c;d`) /* e;f */; SELECT 2") === [
		"INSERT INTO t VALUES ('a;b', `c;d`)",
		'SELECT 2',
	],
);
ok(
	'comments are dropped and leave a space so neighbours do not fuse',
	Lexer::splitStatements('SELECT/* x */1;') === ['SELECT 1'],
);
ok(
	'an executable comment survives splitting',
	Lexer::splitStatements('/*!40101 SET x=1 */; SELECT 1') === ['/*!40101 SET x=1 */', 'SELECT 1'],
);
ok('empty statements are dropped', Lexer::splitStatements(" ;; \n ; ") === []);
ok(
	'an unterminated string keeps the rest as one statement instead of dropping it',
	Lexer::splitStatements("SELECT 'a; SELECT 2") === ["SELECT 'a; SELECT 2"],
);
ok(
	'an error token is kept in the statement text',
	Lexer::splitStatements('SELECT \\ 1; SELECT 2') === ['SELECT \\ 1', 'SELECT 2'],
);
ok(
	'a script without a final semicolon keeps its last statement',
	Lexer::splitStatements('a; b') === ['a', 'b'],
);

echo "\nMatching parentheses\n";
$paren_tokens = Lexer::tokenize('f(a, (b), g(c)) x');
$open = 1;
ok(
	'the closing parenthesis of a nested call is found',
	Lexer::matchingParen($paren_tokens, $open) === 14,
);
ok('an inner pair matches its own close', Lexer::matchingParen($paren_tokens, 5) === 7);
$router = Lexer::tokenize(
	"CREATE TABLE r (a int) ENGINE=InnoDB COMMENT='Maps (access, page and title)'",
);
ok(
	'a parenthesis inside a trailing string comment is not the close',
	Lexer::matchingParen($router, 6) === 10,
);
ok(
	'parentheses inside strings, backticks and comments do not count',
	Lexer::matchingParen(Lexer::tokenize("(')' `)` /* ) */ # )\n)"), 0) === 9,
);
ok('an unclosed parenthesis answers -1', Lexer::matchingParen(Lexer::tokenize('(a (b)'), 0) === -1);
ok('a non-parenthesis start answers -1', Lexer::matchingParen(Lexer::tokenize('a (b)'), 0) === -1);
ok('an out-of-range start answers -1', Lexer::matchingParen(Lexer::tokenize('(a)'), 9) === -1);
ok(
	'a string token that reads as an open parenthesis is not one',
	Lexer::matchingParen(Lexer::tokenize("'(' )"), 0) === -1,
);

echo "\nString literal decoding\n";
$decode_cases = [
	'plain text passes through' => ['abc', 'abc'],
	'backslash n, r, t and b' => ['a\\nb\\rc\\td\\be', "a\nb\rc\td\x08e"],
	'backslash zero is a NUL' => ['a\\0b', "a\0b"],
	'backslash Z is ASCII 26' => ['a\\Zb', "a\x1ab"],
	'an escaped backslash is one backslash' => ['a\\\\b', 'a\\b'],
	'an escaped quote is the quote' => ["a\\'b", "a'b"],
	'an escaped double quote is the quote' => ['a\\"b', 'a"b'],
	'a doubled single quote is one quote' => ["a''b", "a'b"],
	'backslash percent keeps its backslash' => ['a\\%b', 'a\\%b'],
	'backslash underscore keeps its backslash' => ['a\\_b', 'a\\_b'],
	'an unknown escape is the character itself' => ['a\\qb', 'aqb'],
	'a trailing backslash is kept' => ['abc\\', 'abc\\'],
	'multibyte bytes pass through' => ["caf\xc3\xa9", "caf\xc3\xa9"],
	'an empty body is empty' => ['', ''],
];
foreach ($decode_cases as $label => [$raw, $expected]) {
	ok(
		"decode: $label",
		Literal::decodeMysqlString($raw) === $expected,
		bin2hex(Literal::decodeMysqlString($raw)),
	);
}
ok(
	'decode: a doubled double quote is one quote in a double quoted body',
	Literal::decodeMysqlString('a""b', '"') === 'a"b',
);
ok(
	'decode: a doubled single quote stays two in a double quoted body',
	Literal::decodeMysqlString("a''b", '"') === "a''b",
);
$body = str_repeat("x\\n''y", 2000);
ok(
	'decode: a long body decodes in one pass',
	Literal::decodeMysqlString($body) === str_repeat("x\n'y", 2000),
);

echo "\nSQLite literal encoding\n";
ok('encode: plain text is quoted', Literal::encodeSqliteString('abc') === "'abc'");
ok('encode: a quote is doubled', Literal::encodeSqliteString("a'b") === "'a''b'");
ok('encode: a backslash is left alone', Literal::encodeSqliteString('a\\b') === "'a\\b'");
ok(
	'encode: a NUL becomes a cast of hex bytes',
	Literal::encodeSqliteString("a\0b'") === "CAST(x'61006227' AS TEXT)",
);
ok('encode: an empty value is two quotes', Literal::encodeSqliteString('') === "''");
ok(
	'decode then encode keeps the stored value, apostrophes and backslashes included',
	Literal::encodeSqliteString(Literal::decodeMysqlString("it\\'s a \\\\ path")) ===
		"'it''s a \\ path'",
);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

<?php

/**
 * @file
 * Lexes every query of a CSV corpus and writes one digest per query.
 *
 * The corpus is a CSV of "query[,sql_mode[,expected-error]]" records written with
 * fputcsv, so it is read with fgetcsv and the backslash escape on. The queries go
 * to queries.bin (4-byte little-endian length, then the bytes) and the digests to
 * digest.bin, so another lexer can read the same bytes and compare.
 *
 * A digest is 25 bytes: the token count (4-byte little-endian), a flag byte (bit 0
 * set when the query is valid UTF-8, bit 1 set when it holds an error token) and
 * the raw SHA-1 of the token stream. The stream is, per token, "type:offset:length:"
 * followed by the token's bytes. Offsets and lengths count bytes.
 *
 * Run it as "php -n tests/lexer-digest.php corpus.csv out-dir". With "--dump N" as
 * the third and fourth arguments it prints the tokens of query N as JSON lines
 * instead, which is how a difference is explained.
 */

declare(strict_types=1);

use Drupflare\Rom\Sql\Lexer;
use Drupflare\Rom\Sql\TokenType;

require_once dirname(__DIR__) . '/src/Sql/TokenType.php';
require_once dirname(__DIR__) . '/src/Sql/Token.php';
require_once dirname(__DIR__) . '/src/Sql/Lexer.php';

$csv = $argv[1] ?? null;
$out_dir = $argv[2] ?? null;
if ($csv === null || $out_dir === null || !is_file($csv)) {
	fwrite(STDERR, "Usage: php -n tests/lexer-digest.php <corpus.csv> <out-dir> [--dump N]\n");
	exit(2);
}
$dump = ($argv[3] ?? '') === '--dump' ? (int) ($argv[4] ?? -1) : null;
if (!is_dir($out_dir) && !mkdir($out_dir, 0777, true)) {
	fwrite(STDERR, "cannot create $out_dir\n");
	exit(2);
}

$handle = fopen($csv, 'rb');
if ($handle === false) {
	fwrite(STDERR, "cannot open $csv\n");
	exit(2);
}
$queries_file = $dump === null ? fopen($out_dir . '/queries.bin', 'wb') : false;
$digest_file = $dump === null ? fopen($out_dir . '/digest.bin', 'wb') : false;
if ($dump === null && ($queries_file === false || $digest_file === false)) {
	fwrite(STDERR, "cannot write into $out_dir\n");
	exit(2);
}

$index = 0;
$tokens_total = 0;
$errors_total = 0;
$bytes_total = 0;
while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
	if ($row === [null]) {
		continue;
	}
	$query = (string) $row[0];
	$tokens = Lexer::tokenize($query);
	if ($dump !== null) {
		if ($index === $dump) {
			foreach ($tokens as $token) {
				echo json_encode(
					[
						'type' => $token->type->value,
						'offset' => $token->offset,
						'length' => $token->length,
						'text' => $token->text,
					],
					JSON_INVALID_UTF8_SUBSTITUTE,
				) . "\n";
			}
			exit(0);
		}
		$index++;
		continue;
	}
	$stream = '';
	$has_error = false;
	foreach ($tokens as $token) {
		$stream .=
			$token->type->value . ':' . $token->offset . ':' . $token->length . ':' . $token->text;
		if ($token->type === TokenType::Error) {
			$has_error = true;
			$errors_total++;
		}
	}
	$valid_utf8 = preg_match('//u', $query) === 1;
	$flags = ($valid_utf8 ? 1 : 0) | ($has_error ? 2 : 0);
	fwrite($queries_file, pack('V', strlen($query)) . $query);
	fwrite($digest_file, pack('VC', count($tokens), $flags) . sha1($stream, true));
	$tokens_total += count($tokens);
	$bytes_total += strlen($query);
	$index++;
}
fclose($handle);
if ($dump !== null) {
	fwrite(STDERR, "no query $dump\n");
	exit(1);
}
fclose($queries_file);
fclose($digest_file);
echo json_encode([
	'php' => PHP_VERSION,
	'queries' => $index,
	'bytes' => $bytes_total,
	'tokens' => $tokens_total,
	'error_tokens' => $errors_total,
]) . "\n";

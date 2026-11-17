<?php

/**
 * @file
 * Times Lexer::tokenize() over a file of queries and prints one JSON report.
 *
 * The input is a binary file of records, each a 4-byte little-endian length
 * followed by that many bytes of SQL. Each query is lexed once per round and
 * its time is the median across rounds, so one slow round cannot set a
 * percentile. Run it as "php -n tests/lexer-timing.php queries.bin [rounds]";
 * -n keeps a debugger extension out of the clock. The report says what the
 * timer floor was, because a query that lexes in a microsecond is close to it.
 */

declare(strict_types=1);

use Drupflare\Rom\Sql\Lexer;
use Drupflare\Rom\Sql\TokenType;

require_once dirname(__DIR__) . '/src/Sql/TokenType.php';
require_once dirname(__DIR__) . '/src/Sql/Token.php';
require_once dirname(__DIR__) . '/src/Sql/Lexer.php';

$path = $argv[1] ?? null;
$rounds = (int) ($argv[2] ?? 7);
if ($path === null || !is_file($path) || $rounds < 1) {
	fwrite(STDERR, "Usage: php -n tests/lexer-timing.php <queries.bin> [rounds]\n");
	exit(2);
}

$data = (string) file_get_contents($path);
$queries = [];
$pos = 0;
$size = strlen($data);
while ($pos < $size) {
	$length = unpack('V', $data, $pos)[1];
	$queries[] = substr($data, $pos + 4, $length);
	$pos += 4 + $length;
}
unset($data);
$count = count($queries);

// one untimed pass warms the class and checks the lexer's own contract on every query
$tokens = 0;
$errors = 0;
$broken = 0;
$bytes = 0;
foreach ($queries as $query) {
	$bytes += strlen($query);
	$joined = '';
	foreach (Lexer::tokenize($query) as $token) {
		$joined .= $token->text;
		$tokens++;
		if ($token->type === TokenType::Error) {
			$errors++;
		}
	}
	if ($joined !== $query) {
		$broken++;
	}
}

$floor = [];
for ($i = 0; $i < 20000; $i++) {
	$start = hrtime(true);
	$floor[] = hrtime(true) - $start;
}
sort($floor);

/**
 * The nearest-rank percentile of a sorted list.
 *
 * @param list<float|int> $sorted
 *   Values in ascending order.
 * @param float $fraction
 *   Between 0 and 1.
 *
 * @return float|int
 *   One of the values.
 */
function percentile(array $sorted, float $fraction): float|int
{
	$rank = (int) ceil($fraction * count($sorted)) - 1;
	return $sorted[max(0, min(count($sorted) - 1, $rank))];
}

$samples = array_fill(0, $count, []);
$round_totals = [];
for ($round = 0; $round < $rounds; $round++) {
	$total = 0;
	foreach ($queries as $index => $query) {
		$start = hrtime(true);
		Lexer::tokenize($query);
		$elapsed = hrtime(true) - $start;
		$samples[$index][] = $elapsed;
		$total += $elapsed;
	}
	$round_totals[] = round($total / 1000, 1);
}

$per_query = [];
foreach ($samples as $times) {
	sort($times);
	$per_query[] = $times[intdiv(count($times), 2)] / 1000;
}
sort($per_query);

echo json_encode(
	[
		'php' => PHP_VERSION,
		'sapi' => PHP_SAPI,
		'n' => $count,
		'rounds' => $rounds,
		'bytes' => $bytes,
		'tokens' => $tokens,
		'error_tokens' => $errors,
		'round_trip_failures' => $broken,
		'timer_floor_us' => round(array_sum($floor) / count($floor) / 1000, 3),
		'us_per_query' => [
			'p50' => round(percentile($per_query, 0.5), 2),
			'p95' => round(percentile($per_query, 0.95), 2),
			'p99' => round(percentile($per_query, 0.99), 2),
			'max' => round(end($per_query), 2),
			'total' => round(array_sum($per_query), 1),
		],
		'round_total_us' => $round_totals,
	],
	JSON_PRETTY_PRINT,
) . "\n";

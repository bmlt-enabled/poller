<?php
/**
 * Pure checks for poll codes and ballot selection.
 *
 * Run: php tests/run.php
 */

if (PHP_SAPI === 'cli' && !defined('ABSPATH')) {
    define('ABSPATH', __DIR__); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
}

if (!defined('ABSPATH')) {
    exit;
}

require dirname(__DIR__) . '/includes/Codes.php';
require dirname(__DIR__) . '/includes/BallotRules.php';

use BmltEnabled\Poller\BallotRules;
use BmltEnabled\Poller\Codes;

/**
 * @param bool $condition
 */
function poller_check(bool $condition, string $message): void
{
    global $poller_failed;
    if ($condition) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    $poller_failed++;
}

$poller_failed = 0;

$poller_alphabet = str_split(Codes::ALPHABET);
poller_check(count($poller_alphabet) === 31, 'alphabet leaves out ambiguous characters');
poller_check(!preg_match('/[01ILO]/', Codes::ALPHABET), 'alphabet has no ambiguous characters');

for ($poller_i = 0; $poller_i < 40; $poller_i++) {
    $poller_code = Codes::generate();
    poller_check(Codes::is_valid($poller_code), "generated code {$poller_code} is valid");
    poller_check(!Codes::has_ambiguous($poller_code), "generated code {$poller_code} is unambiguous");
}

poller_check(Codes::normalize(' k7-mq 2p ') === 'K7MQ2P', 'normalize strips separators and uppercases');
poller_check(Codes::is_valid(Codes::normalize('k7mq2p')), 'a normalized code can be valid');
poller_check(Codes::has_ambiguous(Codes::normalize('K7MQ2O')), 'O is rejected');
poller_check(Codes::has_ambiguous(Codes::normalize('k7mq20')), '0 is rejected');
poller_check(Codes::has_ambiguous(Codes::normalize('k7mq21')), '1 is rejected');
poller_check(Codes::has_ambiguous(Codes::normalize('k7mqi2')), 'I is rejected');
poller_check(!Codes::is_valid('K7MQ2'), 'short codes are invalid');
poller_check(!Codes::is_valid('K7MQ2P2'), 'long codes are invalid');

$poller_single = BallotRules::select(['4'], [4, 9], 'single');
poller_check($poller_single['error'] === null && $poller_single['ids'] === [4], 'single choice accepts one id');

$poller_missing = BallotRules::select([], [4, 9], 'single');
poller_check($poller_missing['error'] === 'one', 'single choice requires one id');

$poller_two = BallotRules::select(['4', '9'], [4, 9], 'single');
poller_check($poller_two['error'] === 'one', 'single choice rejects two ids');

$poller_multi = BallotRules::select(['9', '4', '9'], [4, 9], 'multi');
poller_check($poller_multi['error'] === null && $poller_multi['ids'] === [9, 4], 'multi choice dedupes and keeps order');

$poller_empty = BallotRules::select([], [4, 9], 'multi');
poller_check($poller_empty['error'] === 'some', 'multi choice requires one id');

$poller_unknown = BallotRules::select(['3'], [4, 9], 'single');
poller_check($poller_unknown['error'] === 'invalid', 'unknown choice is rejected');

$poller_junk = BallotRules::select(['4abc'], [4, 9], 'single');
poller_check($poller_junk['error'] === 'invalid', 'non-numeric choice is rejected');

if ($poller_failed > 0) {
    fwrite(STDERR, "{$poller_failed} failed\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    exit(1);
}

fwrite(STDOUT, "ok\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

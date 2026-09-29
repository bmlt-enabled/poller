<?php
/**
 * Pure checks for poll codes and ballot selection.
 *
 * Run: php tests/run.php
 */

define('ABSPATH', __DIR__);

require dirname(__DIR__) . '/includes/Codes.php';
require dirname(__DIR__) . '/includes/BallotRules.php';

use BmltEnabled\Poller\BallotRules;
use BmltEnabled\Poller\Codes;

$failed = 0;

function check(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    $failed++;
}

$alphabet = str_split(Codes::ALPHABET);
check(count($alphabet) === 31, 'alphabet leaves out ambiguous characters');
check(!preg_match('/[01ILO]/', Codes::ALPHABET), 'alphabet has no ambiguous characters');

for ($i = 0; $i < 40; $i++) {
    $code = Codes::generate();
    check(Codes::is_valid($code), "generated code {$code} is valid");
    check(!Codes::has_ambiguous($code), "generated code {$code} is unambiguous");
}

check(Codes::normalize(' k7-mq 2p ') === 'K7MQ2P', 'normalize strips separators and uppercases');
check(Codes::is_valid(Codes::normalize('k7mq2p')), 'a normalized code can be valid');
check(Codes::has_ambiguous(Codes::normalize('K7MQ2O')), 'O is rejected');
check(Codes::has_ambiguous(Codes::normalize('k7mq20')), '0 is rejected');
check(Codes::has_ambiguous(Codes::normalize('k7mq21')), '1 is rejected');
check(Codes::has_ambiguous(Codes::normalize('k7mqi2')), 'I is rejected');
check(!Codes::is_valid('K7MQ2'), 'short codes are invalid');
check(!Codes::is_valid('K7MQ2P2'), 'long codes are invalid');

$single = BallotRules::select(['4'], [4, 9], 'single');
check($single['error'] === null && $single['ids'] === [4], 'single choice accepts one id');

$missing = BallotRules::select([], [4, 9], 'single');
check($missing['error'] === 'one', 'single choice requires one id');

$two = BallotRules::select(['4', '9'], [4, 9], 'single');
check($two['error'] === 'one', 'single choice rejects two ids');

$multi = BallotRules::select(['9', '4', '9'], [4, 9], 'multi');
check($multi['error'] === null && $multi['ids'] === [9, 4], 'multi choice dedupes and keeps order');

$empty = BallotRules::select([], [4, 9], 'multi');
check($empty['error'] === 'some', 'multi choice requires one id');

$unknown = BallotRules::select(['3'], [4, 9], 'single');
check($unknown['error'] === 'invalid', 'unknown choice is rejected');

$junk = BallotRules::select(['4abc'], [4, 9], 'single');
check($junk['error'] === 'invalid', 'non-numeric choice is rejected');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}

fwrite(STDOUT, "ok\n");

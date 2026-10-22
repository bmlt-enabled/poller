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
require dirname(__DIR__) . '/includes/Repository.php';
require dirname(__DIR__) . '/includes/Tally.php';

use BmltEnabled\Poller\BallotRules;
use BmltEnabled\Poller\Codes;
use BmltEnabled\Poller\Repository;
use BmltEnabled\Poller\Tally;

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

$poller_thirteen = BallotRules::select(range(1, 13), range(1, 13), 'multi');
poller_check($poller_thirteen['error'] === 'some', 'multi choice still stops at 12');

// Dot voting. Allowed items are 4, 9, and 11.
$poller_items = [4, 9, 11];

$poller_ok = BallotRules::dots(['4' => '2', '9' => 1, '11' => 0], $poller_items, 5, 3, false);
poller_check($poller_ok['error'] === null && $poller_ok['weights'] === [4 => 2, 9 => 1], 'dots keep each item\'s dots and drop zeros');

$poller_over = BallotRules::dots([4 => 3, 9 => 3], $poller_items, 5, 3, false);
poller_check($poller_over['error'] === 'dots_budget', 'dots over the budget are rejected');

$poller_stack = BallotRules::dots([4 => 4], $poller_items, 5, 3, false);
poller_check($poller_stack['error'] === 'dots_per_choice', 'dots over the per-item maximum are rejected');

$poller_flat = BallotRules::dots([4 => 2], $poller_items, 5, 1, false);
poller_check($poller_flat['error'] === 'dots_per_choice', 'stacking off rejects 2 dots on one item');

$poller_flat_ok = BallotRules::dots([4 => 1, 9 => 1, 11 => 1], $poller_items, 3, 1, true);
poller_check($poller_flat_ok['error'] === null && array_sum($poller_flat_ok['weights']) === 3, 'stacking off takes one dot on each item');

$poller_exact = BallotRules::dots([4 => 3, 9 => 2], $poller_items, 5, 3, true);
poller_check($poller_exact['error'] === null, 'spend all accepts the exact total');

$poller_short = BallotRules::dots([4 => 3, 9 => 1], $poller_items, 5, 3, true);
poller_check($poller_short['error'] === 'dots_spend_all', 'spend all rejects a short total');

$poller_short_ok = BallotRules::dots([4 => 3, 9 => 1], $poller_items, 5, 3, false);
poller_check($poller_short_ok['error'] === null, 'a short total is fine when spend all is off');

$poller_none = BallotRules::dots([4 => 0], $poller_items, 5, 3, false);
poller_check($poller_none['error'] === 'dots_none', 'a dot ballot needs at least one dot');

$poller_stranger = BallotRules::dots([5 => 1], $poller_items, 5, 3, false);
poller_check($poller_stranger['error'] === 'invalid', 'dots on an item not on the poll are rejected');

$poller_negative = BallotRules::dots([4 => -1, 9 => 3], $poller_items, 5, 3, false);
poller_check($poller_negative['error'] === 'invalid', 'negative dots are rejected');

$poller_fraction = BallotRules::dots([4 => '1.5'], $poller_items, 5, 3, false);
poller_check($poller_fraction['error'] === 'invalid', 'fractional dots are rejected');

$poller_form = BallotRules::dots(['4' => '-2', '9' => '1'], $poller_items, 5, 3, false);
poller_check($poller_form['error'] === 'invalid', 'a negative count posted from the form is rejected');

$poller_form_fraction = BallotRules::dots(['4' => '1.5'], $poller_items, 5, 3, false);
poller_check($poller_form_fraction['error'] === 'invalid', 'a fractional count posted from the form is rejected');

$poller_list = BallotRules::dots([4, 9], $poller_items, 5, 3, false);
poller_check($poller_list['error'] === 'invalid', 'a plain list of ids is not a dot ballot');

$poller_many = range(1, 60);
$poller_wide = BallotRules::dots([60 => 1] + array_fill_keys($poller_many, 0), $poller_many, 5, 1, false);
poller_check($poller_wide['error'] === null, 'dot polls are not held to 12 items');
poller_check(BallotRules::max_choices('dots') === 100 && BallotRules::max_choices('multi') === 12, 'item caps by selection');

// weigh() routes by the poll's selection.
$poller_dot_poll = ['selection' => 'dots', 'budget' => 5, 'per_choice' => 2, 'spend_all' => false];
$poller_weighed = BallotRules::weigh($poller_dot_poll, [4 => 2, 9 => 2], $poller_items);
poller_check($poller_weighed['error'] === null && $poller_weighed['weights'] === [4 => 2, 9 => 2], 'weigh checks a dot ballot by the poll\'s rules');

// Upgrade path: a poll row saved before the dot columns existed.
$poller_v1 = Repository::hydrate_poll([
    'id' => '3',
    'code' => 'K7MQ2P',
    'title' => 'Snacks',
    'question' => 'Snacks?',
    'image_id' => '0',
    'kind' => 'text',
    'selection' => 'multi',
    'status' => 'open',
    'revision' => '2',
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-01 00:00:00',
]);
poller_check($poller_v1['budget'] === 0 && $poller_v1['per_choice'] === 0 && $poller_v1['spend_all'] === false, 'an old poll row reads with no dot rules');
$poller_v1_vote = BallotRules::weigh($poller_v1, ['9', '4'], $poller_items);
poller_check($poller_v1_vote['error'] === null && $poller_v1_vote['weights'] === [9 => 1, 4 => 1], 'an old multi poll weighs each choice as 1');
$poller_v1_single = BallotRules::weigh(['selection' => 'single'] + $poller_v1, ['4', '9'], $poller_items);
poller_check($poller_v1_single['error'] === 'one', 'an old single poll still takes one choice');

// Weighted tallies.
$poller_tally = [
    ['id' => 4, 'votes' => 3],
    ['id' => 9, 'votes' => 7],
    ['id' => 11, 'votes' => 3],
    ['id' => 12, 'votes' => 0],
];
$poller_ranked = array_column(Tally::rank($poller_tally), 'id');
poller_check($poller_ranked === [9, 4, 11, 12], 'dot tallies rank by dots and keep poll order on ties');
poller_check(Tally::share(7, ['selection' => 'dots', 'dots' => 13, 'ballots' => 4]) === 53.8, 'a dot share is out of all dots cast');
poller_check(Tally::share(3, ['selection' => 'multi', 'dots' => 0, 'ballots' => 4]) === 75.0, 'other shares are out of ballots');
poller_check(Tally::share(3, ['selection' => 'dots', 'dots' => 0, 'ballots' => 0]) === 0.0, 'an empty tally has no share');

if ($poller_failed > 0) {
    fwrite(STDERR, "{$poller_failed} failed\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
    exit(1);
}

fwrite(STDOUT, "ok\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

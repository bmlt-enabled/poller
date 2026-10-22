<?php
/**
 * Pure tally math shared by the results template and the tests.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Tally
{
    /** Rows shown on a dot voting tally before "Show all". */
    public const TOP = 10;

    /** Most words a word cloud payload carries. */
    public const CLOUD = 100;

    /**
     * Percent for one choice's bar. A dot poll shows its share of all dots
     * cast; the others show the share of ballots that picked it.
     *
     * @param array<string, mixed> $state
     */
    public static function share(int $votes, array $state): float
    {
        $total = ($state['selection'] ?? '') === 'dots'
            ? (int) ($state['dots'] ?? 0)
            : (int) ($state['ballots'] ?? 0);
        if ($total <= 0) {
            return 0.0;
        }
        return round(($votes / $total) * 100, 1);
    }

    /**
     * Most votes first. Ties keep the poll's own order.
     *
     * @param array<int, array<string, mixed>> $choices
     * @return array<int, array<string, mixed>>
     */
    public static function rank(array $choices): array
    {
        $order = [];
        foreach (array_values($choices) as $index => $choice) {
            $order[] = [$index, $choice];
        }
        usort($order, static function ($a, $b) {
            $diff = (int) $b[1]['votes'] <=> (int) $a[1]['votes'];
            return $diff !== 0 ? $diff : $a[0] <=> $b[0];
        });
        return array_map(static function ($pair) {
            return $pair[1];
        }, $order);
    }

    /**
     * Word cloud tally from stored entries. Each row is one spelling of a key
     * and how many ballots used that spelling; a ballot holds a key at most
     * once, so the sum per key is the number of ballots that entered it.
     *
     * Shows the most common spelling. Hidden keys are left out. Most ballots
     * first, then alphabetical, so the order is stable between refreshes.
     *
     * @param array<int, array{key: string, text: string, count: int|string}> $rows
     * @param array<int, string> $hidden keys
     * @return array<int, array{text: string, key: string, count: int}>
     */
    public static function words(array $rows, array $hidden = [], int $limit = self::CLOUD): array
    {
        $skip = array_fill_keys($hidden, true);
        $totals = [];
        $spelling = [];
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            if ($key === '' || isset($skip[$key])) {
                continue;
            }
            $count = (int) $row['count'];
            $text = (string) $row['text'];
            $totals[$key] = ($totals[$key] ?? 0) + $count;
            $best = $spelling[$key] ?? null;
            if ($best === null || $count > $best[1] || ($count === $best[1] && strcmp($text, $best[0]) < 0)) {
                $spelling[$key] = [$text, $count];
            }
        }

        $words = [];
        foreach ($totals as $key => $count) {
            $words[] = ['text' => $spelling[$key][0], 'key' => (string) $key, 'count' => $count];
        }
        usort($words, static function ($a, $b) {
            return ($b['count'] <=> $a['count']) ?: strcmp($a['key'], $b['key']);
        });
        return $limit > 0 ? array_slice($words, 0, $limit) : $words;
    }
}

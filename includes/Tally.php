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
}

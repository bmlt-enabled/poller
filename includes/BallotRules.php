<?php
/**
 * Pure checks for a submitted ballot.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class BallotRules
{
    /** Most choices on a one-choice or more-than-one poll. */
    public const MAX_CHOICES = 12;

    /** Most items on a dot voting poll. */
    public const MAX_DOT_CHOICES = 100;

    /** Most dots one voter can have. */
    public const MAX_BUDGET = 50;

    public const DEFAULT_BUDGET = 5;

    /**
     * @param array<int, mixed> $submitted
     * @param array<int, int> $allowed
     * @return array{ids: array<int, int>, error: ?string}
     */
    public static function select(array $submitted, array $allowed, string $selection): array
    {
        $allowed_map = [];
        foreach ($allowed as $id) {
            $allowed_map[(int) $id] = true;
        }

        $ids = [];
        foreach ($submitted as $value) {
            $id = self::whole($value);
            if ($id === null || $id <= 0 || !isset($allowed_map[$id])) {
                return ['ids' => [], 'error' => 'invalid'];
            }
            $ids[$id] = $id;
        }

        $ids = array_values($ids);
        if ($selection === 'single') {
            if (count($ids) !== 1) {
                return ['ids' => [], 'error' => 'one'];
            }
        } elseif (count($ids) < 1) {
            return ['ids' => [], 'error' => 'some'];
        }

        if (count($ids) > self::MAX_CHOICES) {
            return ['ids' => [], 'error' => 'some'];
        }

        return ['ids' => $ids, 'error' => null];
    }

    /**
     * A dot ballot is a map of choice id => dots. Items with 0 dots are dropped.
     *
     * @param array<mixed, mixed> $submitted
     * @param array<int, int> $allowed
     * @return array{weights: array<int, int>, error: ?string}
     */
    public static function dots(array $submitted, array $allowed, int $budget, int $per_choice, bool $spend_all): array
    {
        $allowed_map = [];
        foreach ($allowed as $id) {
            $allowed_map[(int) $id] = true;
        }

        $weights = [];
        $total = 0;
        foreach ($submitted as $key => $value) {
            $id = self::whole($key);
            $dots = self::whole($value);
            if ($id === null || $id <= 0 || !isset($allowed_map[$id]) || $dots === null || $dots < 0) {
                return ['weights' => [], 'error' => 'invalid'];
            }
            if ($dots === 0) {
                continue;
            }
            if ($dots > $per_choice) {
                return ['weights' => [], 'error' => 'dots_per_choice'];
            }
            $weights[$id] = $dots;
            $total += $dots;
        }

        if ($total < 1) {
            return ['weights' => [], 'error' => 'dots_none'];
        }
        if ($total > $budget) {
            return ['weights' => [], 'error' => 'dots_budget'];
        }
        if ($spend_all && $total !== $budget) {
            return ['weights' => [], 'error' => 'dots_spend_all'];
        }

        return ['weights' => $weights, 'error' => null];
    }

    /**
     * Check a ballot against a poll's rules and return choice id => weight.
     * Single and multi polls weigh every choice as 1.
     *
     * @param array<string, mixed> $poll
     * @param array<mixed, mixed> $submitted
     * @param array<int, int> $allowed
     * @return array{weights: array<int, int>, error: ?string}
     */
    public static function weigh(array $poll, array $submitted, array $allowed): array
    {
        $selection = (string) ($poll['selection'] ?? 'single');
        if ($selection === 'dots') {
            return self::dots(
                $submitted,
                $allowed,
                (int) ($poll['budget'] ?? 0),
                (int) ($poll['per_choice'] ?? 0),
                !empty($poll['spend_all'])
            );
        }

        $picked = self::select($submitted, $allowed, $selection);
        if ($picked['error'] !== null) {
            return ['weights' => [], 'error' => $picked['error']];
        }
        return ['weights' => array_fill_keys($picked['ids'], 1), 'error' => null];
    }

    /**
     * Most items a poll with this selection can have.
     */
    public static function max_choices(string $selection): int
    {
        return $selection === 'dots' ? self::MAX_DOT_CHOICES : self::MAX_CHOICES;
    }

    /**
     * @param mixed $value
     */
    private static function whole($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }
        return null;
    }
}

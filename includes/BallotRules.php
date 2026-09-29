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
            if (is_string($value)) {
                if (!preg_match('/^\d+$/', $value)) {
                    return ['ids' => [], 'error' => 'invalid'];
                }
                $id = (int) $value;
            } elseif (is_int($value)) {
                $id = $value;
            } else {
                return ['ids' => [], 'error' => 'invalid'];
            }

            if ($id <= 0 || !isset($allowed_map[$id])) {
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

        if (count($ids) > 12) {
            return ['ids' => [], 'error' => 'some'];
        }

        return ['ids' => $ids, 'error' => null];
    }
}

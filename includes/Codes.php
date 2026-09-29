<?php
/**
 * Short poll codes that are readable across a room.
 *
 * The alphabet leaves out 0, 1, I, L, and O so a code on a projector
 * is hard to misread.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Codes
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH = 6;

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        $stripped = preg_replace('/[^A-Z0-9]/', '', $code);
        return $stripped ?? '';
    }

    public static function is_valid(string $code): bool
    {
        return (bool) preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code);
    }

    public static function has_ambiguous(string $code): bool
    {
        return (bool) preg_match('/[01ILO]/', $code);
    }
}

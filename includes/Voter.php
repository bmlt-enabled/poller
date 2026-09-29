<?php
/**
 * Anonymous browser ballot.
 *
 * The cookie holds a random token. The database stores only a salted
 * hash of that token, so a ballot can be updated without storing an
 * account, address, or anything that identifies a person.
 *
 * @package Poller
 */

namespace BmltEnabled\Poller;

if (!defined('ABSPATH')) {
    exit;
}

class Voter
{
    public const COOKIE = 'poller_ballot';

    public static function has_token(): bool
    {
        if (!isset($_COOKIE[self::COOKIE])) {
            return false;
        }
        return (bool) preg_match('/^[a-f0-9]{64}$/', (string) $_COOKIE[self::COOKIE]);
    }

    public static function token(): string
    {
        return self::has_token() ? (string) $_COOKIE[self::COOKIE] : '';
    }

    public static function ensure(): string
    {
        if (self::has_token()) {
            return self::token();
        }

        $raw = bin2hex(random_bytes(32));
        setcookie(self::COOKIE, $raw, [
            'expires' => time() + 30 * DAY_IN_SECONDS,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $raw;
        return $raw;
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw . '|' . wp_salt('auth'));
    }
}

<?php

namespace TopJewelleryDiamondBuilder\Rest;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Simple per-IP transient counter protecting the Nivoda proxy endpoints
 * from abuse (each search call costs a real Nivoda API request).
 */
class RateLimiter
{
    private const LIMIT = 30;
    private const WINDOW = MINUTE_IN_SECONDS;

    public static function allow(string $bucket): bool
    {
        $ip = self::get_client_ip();
        $key = 'tjdb_rl_' . $bucket . '_' . md5($ip);

        $count = (int) get_transient($key);

        if ($count >= self::LIMIT) {
            return false;
        }

        if ($count === 0) {
            set_transient($key, 1, self::WINDOW);
        } else {
            set_transient($key, $count + 1, self::WINDOW);
        }

        return true;
    }

    private static function get_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}

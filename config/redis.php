<?php
/**
 * Redis Cache Integration with Graceful Fallback
 * PT Angkasa Pura Sarana Digital
 */

class AppRedis {
    private static $client = null;
    private static $connected = false;
    private static $tested = false;

    private static function init() {
        if (self::$tested) {
            return;
        }
        self::$tested = true;

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = intval(getenv('REDIS_PORT') ?: 6379);
        $timeout = 0.5; // Fast connection timeout for non-blocking fallback

        if (class_exists('Redis')) {
            try {
                $redis = new Redis();
                if (@$redis->connect($host, $port, $timeout)) {
                    self::$client = $redis;
                    self::$connected = true;
                }
            } catch (Exception $e) {
                self::$connected = false;
            }
        }
    }

    public static function isAvailable(): bool {
        self::init();
        return self::$connected;
    }

    public static function get(string $key) {
        if (!self::isAvailable()) {
            return null;
        }
        try {
            $data = self::$client->get("apsd:{$key}");
            return $data !== false ? json_decode($data, true) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    public static function set(string $key, $value, int $ttl = 300): bool {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            return self::$client->setex("apsd:{$key}", $ttl, json_encode($value, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            return false;
        }
    }

    public static function del(string $key): bool {
        if (!self::isAvailable()) {
            return false;
        }
        try {
            return (bool) self::$client->del("apsd:{$key}");
        } catch (Exception $e) {
            return false;
        }
    }

    public static function flushPrefix(string $prefix): void {
        if (!self::isAvailable()) {
            return;
        }
        try {
            $keys = self::$client->keys("apsd:{$prefix}*");
            if (!empty($keys)) {
                self::$client->del($keys);
            }
        } catch (Exception $e) {
            // Ignore on fallback
        }
    }
}

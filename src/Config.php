<?php

declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

final class Config
{
    private static bool $loaded = false;

    public static function load(string $rootDir): void
    {
        if (self::$loaded) {
            return;
        }

        if (is_file($rootDir . DIRECTORY_SEPARATOR . '.env')) {
            $dotenv = Dotenv::createImmutable($rootDir);
            $dotenv->safeLoad();
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }
}

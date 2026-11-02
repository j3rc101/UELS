<?php
declare(strict_types=1);

namespace App\Core;

/** Tiny .env reader (no Composer needed). */
class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$vars[trim($key)] = trim($value, " \t\"'");
        }
    }

    public static function get(string $key, $default = null)
    {
        return self::$vars[$key] ?? ($_ENV[$key] ?? $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}

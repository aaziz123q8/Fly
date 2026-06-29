<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * ConfigLoader
 *
 * Resolves the secret config files (config/apis.php, config/database.php) from
 * one of two locations, in order:
 *
 *   1. <web-root>/config/<name>.php        — the classic location (public_html/config)
 *   2. <one level above web-root>/config/<name>.php
 *
 * Location (2) sits OUTSIDE the deploy target (`public_html`), so a git
 * auto-deploy — which only syncs the web root — never overwrites or deletes it.
 * It is also not reachable over HTTP. Placing the real secret files there means
 * they survive every deploy/update without ever being committed to git.
 *
 * These files are intentionally git-ignored, so the repo only ships the
 * `*.example.php` templates; the real values live on the server only.
 */
class ConfigLoader
{
    private static function webRoot(): string
    {
        // BASE_PATH is defined in public/index.php as the web root. Fall back to
        // resolving it relative to this file (app/Helpers/ConfigLoader.php).
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    }

    /**
     * Return the first existing path for a config file (without extension dir),
     * or null when neither location has it.
     */
    public static function path(string $name): ?string
    {
        $webRoot = self::webRoot();
        $candidates = [
            $webRoot . '/config/' . $name . '.php',
            dirname($webRoot) . '/config/' . $name . '.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Load a config file as an array, or [] when it cannot be found.
     */
    public static function load(string $name): array
    {
        $path = self::path($name);
        if ($path === null) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }
}

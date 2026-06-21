<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Returns the shared PDO singleton.
     *
     * Config is loaded from config/database.php (not committed to VCS).
     * Falls back to environment variables if the config file is absent
     * (useful for Hostinger hPanel env vars).
     *
     * @throws \RuntimeException on connection failure.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $configFile = dirname(__DIR__, 2) . '/config/database.php';

        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            $config = [
                'host'     => getenv('DB_HOST')     ?: 'localhost',
                'port'     => (int)(getenv('DB_PORT') ?: 3306),
                'dbname'   => getenv('DB_NAME')     ?: '',
                'username' => getenv('DB_USER')     ?: '',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset'  => 'utf8mb4',
                'options'  => [],
            ];
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['dbname'],
            $config['charset'] ?? 'utf8mb4'
        );

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $options = array_replace($defaultOptions, $config['options'] ?? []);

        try {
            self::$instance = new PDO($dsn, $config['username'], $config['password'], $options);
        } catch (PDOException $e) {
            // Do not expose connection details (host, credentials) in the message.
            // Log the real error server-side; surface only a generic message to callers.
            error_log('Database connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed.', (int)$e->getCode());
        }

        return self::$instance;
    }

    /**
     * Allow replacing the singleton (useful for testing).
     */
    public static function setInstance(PDO $pdo): void
    {
        self::$instance = $pdo;
    }

    /**
     * Reset the singleton (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // Prevent instantiation.
    private function __construct() {}
    private function __clone() {}
}

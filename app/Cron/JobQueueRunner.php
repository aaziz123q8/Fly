#!/usr/bin/env php
<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';

// Load .env if available
if (class_exists(\Dotenv\Dotenv::class) && file_exists(BASE_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(BASE_PATH);
    $dotenv->safeLoad();
}

try {
    (new \App\Workers\JobWorker())->run();
} catch (\Throwable $e) {
    fwrite(STDERR, '[JobQueueRunner] Fatal: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

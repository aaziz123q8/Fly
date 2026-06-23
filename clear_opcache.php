<?php
$token = $_SERVER['HTTP_X_CACHE_TOKEN'] ?? $_GET['token'] ?? '';
$expected = $_ENV['APP_MASTER_KEY'] ?? getenv('APP_MASTER_KEY') ?? '';
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (function_exists('opcache_reset')) {
    opcache_reset();
    $status = function_exists('opcache_get_status') ? opcache_get_status(false) : [];
    echo json_encode([
        'success'        => true,
        'opcache_cleared' => true,
        'cached_scripts'  => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
        'time'            => date('c'),
    ]);
} else {
    echo json_encode([
        'success'        => true,
        'opcache_cleared' => false,
        'note'            => 'OPcache not enabled or not available',
        'time'            => date('c'),
    ]);
}

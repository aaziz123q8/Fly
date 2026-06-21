<?php
// OPcache reset — served directly by Apache (bypasses router + OPcache on index.php)
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

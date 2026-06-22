<?php
// Proxy airline logos server-side to avoid CDN blocking on client
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'] ?? ''));
if (!$code || strlen($code) > 3) { http_response_code(400); exit; }

$cacheDir = __DIR__ . '/storage/airline-logos/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

$cachePath = $cacheDir . $code . '.png';

// Serve cached version if exists and less than 30 days old
if (file_exists($cachePath) && (time() - filemtime($cachePath)) < 2592000) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000');
    readfile($cachePath);
    exit;
}

// Try CDN sources in order
$sources = [
    'https://images.kiwi.com/airlines/64/' . $code . '.png',
    'https://pics.avs.io/200/200/' . $code . '.png',
    'https://assets.duffel.com/img/airlines/for-light-background/' . $code . '.svg',
];

$ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'Mozilla/5.0']]);

foreach ($sources as $url) {
    $data = @file_get_contents($url, false, $ctx);
    if ($data && strlen($data) > 500) {
        // For SVG convert header, serve as SVG
        if (str_ends_with($url, '.svg')) {
            // Try to cache as svg
            file_put_contents($cacheDir . $code . '.svg', $data);
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=2592000');
            echo $data;
            exit;
        }
        // PNG: cache and serve
        file_put_contents($cachePath, $data);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=2592000');
        echo $data;
        exit;
    }
}

http_response_code(404);

<?php
// ONE-TIME USE — DELETE THIS FILE AFTER USE
$cfg = require __DIR__ . '/config/database.php';
try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['username'], $cfg['password']
    );
    $pdo->exec("DELETE FROM rate_limits WHERE endpoint='admin_login'");
    echo "Done. Rate limit cleared. <a href='admin/login.html'>Go to Admin Login</a>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

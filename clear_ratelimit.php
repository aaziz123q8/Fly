<?php
// ONE-TIME SETUP SCRIPT — DELETE AFTER USE
$cfg = require __DIR__ . '/config/database.php';
try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4",
        $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1. Clear rate limits
    $pdo->exec("DELETE FROM rate_limits WHERE endpoint='admin_login'");

    // 2. Set correct password hash (Admin@2026)
    $hash = password_hash('Admin@2026', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password=?, role='super_admin', is_active=1 WHERE email='admin@flymasar.com'");
    $stmt->execute([$hash]);

    $rows = $stmt->rowCount();
    echo "<h2 style='color:green'>Done!</h2>";
    echo "<p>Rate limits cleared.</p>";
    echo "<p>Password updated. Rows affected: $rows</p>";
    echo "<p><strong>Email:</strong> admin@flymasar.com</p>";
    echo "<p><strong>Password:</strong> Admin@2026</p>";
    echo "<p><a href='admin/login.html'>Go to Admin Login &rarr;</a></p>";
    echo "<hr><p style='color:red'><strong>DELETE THIS FILE NOW:</strong> run <code>rm clear_ratelimit.php</code></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

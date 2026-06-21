<?php
// ONE-TIME SCRIPT — DELETE AFTER USE
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "<p style='color:green;font-family:monospace'>✅ OPcache cleared successfully.</p>";
} else {
    echo "<p style='color:orange;font-family:monospace'>⚠️ OPcache not available or not enabled.</p>";
}
echo "<p style='color:red;font-size:.85rem'>Delete this file: <code>rm clear_opcache.php</code></p>";
echo "<p><a href='index.html'>← الرئيسية</a></p>";

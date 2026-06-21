<?php
// Copy this file to config/app.php and fill in real values
// config/app.php is NOT committed to version control

return [
    'name'        => 'FlyMasar',
    'env'         => 'production',   // local | staging | production

    // Master key for AES-256-CBC encryption (32 bytes hex-encoded = 64 chars)
    // Generate with: bin2hex(random_bytes(32))
    // Store in Hostinger hPanel → Environment Variables as APP_MASTER_KEY
    'master_key'  => 'YOUR_64_CHAR_HEX_KEY_HERE',

    // App URL
    'url'         => 'https://flymasar.com',

    // Session salt (32+ random chars)
    'salt'        => 'CHANGE_ME_RANDOM_SALT_32_CHARS_MIN',

    // AES setup notes:
    // Algorithm : AES-256-CBC
    // Key length : 32 bytes (256 bits)
    // IV length  : 16 bytes (openssl_random_pseudo_bytes(16))
    // Encoding   : base64 of iv + ':' + ciphertext
    // Helper     : App\Helpers\SecurityHelper::encrypt() / decrypt()
];

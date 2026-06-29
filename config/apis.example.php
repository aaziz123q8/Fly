<?php
// Copy this file to config/apis.php and fill in real values
// config/apis.php is NOT committed to version control

return [

    'duffel' => [
        'api_key'    => 'YOUR_DUFFEL_API_KEY',
        'base_url'   => 'https://api.duffel.com',
        'version'    => 'v2',
        'webhook_secret' => 'YOUR_DUFFEL_WEBHOOK_SECRET',
    ],

    'ratehawk' => [
        'key_id'     => 'YOUR_RATEHAWK_KEY_ID',
        'api_key'    => 'YOUR_RATEHAWK_API_KEY',
        'base_url'   => 'https://api.worldota.net/api/b2b/v3',
        'webhook_secret' => 'YOUR_RATEHAWK_WEBHOOK_SECRET',
    ],

    'stripe' => [
        'publishable_key' => 'pk_live_YOUR_PUBLISHABLE_KEY',
        'secret_key'      => 'sk_live_YOUR_SECRET_KEY',
        'webhook_secret'  => 'whsec_YOUR_WEBHOOK_SECRET',
        'currency'        => 'GBP',
    ],

    'whatsapp' => [
        'provider'   => 'meta',   // meta | twilio | wati
        'token'      => 'YOUR_WHATSAPP_TOKEN',
        'phone_id'   => 'YOUR_PHONE_NUMBER_ID',
        'verify_token' => 'YOUR_WEBHOOK_VERIFY_TOKEN',
    ],

];

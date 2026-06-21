<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminApiSettingsController
{
    public function index(Request $request): void
    {
        $db   = Database::getInstance();
        $stmt = $db->query('SELECT id, provider, setting_key, setting_value, is_secret, updated_at FROM api_settings ORDER BY provider, setting_key');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Mask secret values
        foreach ($rows as &$row) {
            if ($row['is_secret']) {
                $row['setting_value'] = $row['setting_value'] ? '••••••••' . substr((string)$row['setting_value'], -4) : '';
            }
        }

        Response::json(['data' => $rows]);
    }

    public function update(Request $request): void
    {
        $id    = (int) $request->param('id');
        $db    = Database::getInstance();
        $value = (string) $request->input('setting_value', '');

        $db->prepare('UPDATE api_settings SET setting_value = ?, updated_at = NOW() WHERE id = ?')->execute([$value, $id]);
        Response::json(['success' => true]);
    }

    public function upsert(Request $request): void
    {
        $db       = Database::getInstance();
        $provider = (string) $request->input('provider', '');
        $key      = (string) $request->input('setting_key', '');
        $value    = (string) $request->input('setting_value', '');
        $isSecret = (int) $request->input('is_secret', 0);

        if ($provider === '' || $key === '') { Response::error('Provider and key required.', 422); }

        $db->prepare(
            'INSERT INTO api_settings (provider, setting_key, setting_value, is_secret, updated_at)
             VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute([$provider, $key, $value, $isSecret]);

        Response::json(['success' => true]);
    }

    public function testDuffel(Request $request): void
    {
        $apisConfig = file_exists(BASE_PATH . '/config/apis.php') ? require BASE_PATH . '/config/apis.php' : [];
        $key = $apisConfig['duffel']['api_key'] ?? getenv('DUFFEL_API_KEY') ?? '';

        if (!$key) {
            Response::json(['success' => false, 'message' => 'Duffel API key not configured.']);
            return;
        }

        $ch = curl_init('https://api.duffel.com/air/airlines?limit=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Duffel-Version: v2',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Response::json(['success' => $code === 200, 'status_code' => $code]);
    }

    public function testRatehawk(Request $request): void
    {
        $apisConfig = file_exists(BASE_PATH . '/config/apis.php') ? require BASE_PATH . '/config/apis.php' : [];
        $apiKey    = $apisConfig['ratehawk']['api_key'] ?? getenv('RATEHAWK_API_KEY') ?? '';
        $apiSecret = $apisConfig['ratehawk']['api_secret'] ?? getenv('RATEHAWK_API_SECRET') ?? '';

        if (!$apiKey || !$apiSecret) {
            Response::json(['success' => false, 'message' => 'RateHawk credentials not configured.']);
            return;
        }

        $ch = curl_init('https://api.worldota.net/api/b2b/v3/hotel/info/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $apiKey . ':' . $apiSecret,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['id' => 'test']),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 400 is expected for invalid test id — means credentials are valid
        Response::json(['success' => in_array($code, [200, 400]), 'status_code' => $code]);
    }

    public function testStripe(Request $request): void
    {
        $apisConfig = file_exists(BASE_PATH . '/config/apis.php') ? require BASE_PATH . '/config/apis.php' : [];
        $key = $apisConfig['stripe']['secret_key'] ?? getenv('STRIPE_SECRET_KEY') ?? '';

        if (!$key) {
            Response::json(['success' => false, 'message' => 'Stripe secret key not configured.']);
            return;
        }

        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $key . ':',
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Response::json(['success' => $code === 200, 'status_code' => $code]);
    }
}

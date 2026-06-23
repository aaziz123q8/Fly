<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

class AdminCurrenciesController
{
    public function index(Request $request): void
    {
        $db = Database::getInstance();
        try {
            $stmt = $db->query('SELECT id, code, name, symbol, rate_to_gbp, is_active, updated_at FROM currencies ORDER BY code ASC');
            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $data = [];
        }
        Response::json(['data' => $data]);
    }

    public function store(Request $request): void
    {
        $db     = Database::getInstance();
        $code   = strtoupper(trim((string) $request->input('code', '')));
        $name   = trim((string) $request->input('name', ''));
        $symbol = trim((string) $request->input('symbol', ''));
        $rate   = (float) $request->input('rate_to_gbp', 1);

        if ($code === '' || $name === '') { Response::error('Code and name are required.', 422); }

        $db->prepare(
            'INSERT INTO currencies (code, name, symbol, rate_to_gbp, is_active, updated_at) VALUES (?,?,?,?,1,NOW())'
        )->execute([$code, $name, $symbol, $rate]);

        Response::json(['success' => true], 201);
    }

    public function update(Request $request): void
    {
        $id     = (int) $request->param('id');
        $db     = Database::getInstance();
        $allowed = ['name','symbol','rate_to_gbp','is_active'];
        $set    = [];
        $params = [];
        foreach ($allowed as $field) {
            $val = $request->input($field);
            if ($val !== null) {
                $set[]    = "$field = ?";
                $params[] = in_array($field, ['is_active']) ? (int) $val : (string) $val;
            }
        }
        $set[]    = 'updated_at = NOW()';
        $params[] = $id;
        $db->prepare('UPDATE currencies SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
        $stmt = $db->prepare('SELECT * FROM currencies WHERE id = ?');
        $stmt->execute([$id]);
        Response::json(['currency' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    public function destroy(Request $request): void
    {
        $id = (int) $request->param('id');
        Database::getInstance()->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        Response::noContent();
    }

    public function refreshRates(Request $request): void
    {
        $ch = curl_init('https://open.er-api.com/v6/latest/GBP');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FlyMasar/1.0');
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode !== 200) {
            Response::json(['error' => 'Failed to fetch exchange rates from open.er-api.com', 'http_code' => $httpCode], 502);
            return;
        }

        $data = json_decode($raw, true);
        if (empty($data['rates']) || !is_array($data['rates'])) {
            Response::json(['error' => 'Invalid response from exchange rate API'], 502);
            return;
        }

        $rates = $data['rates'];
        $db = Database::getInstance();

        $stmt = $db->query('SELECT code FROM currencies WHERE is_active = 1');
        $activeCurrencies = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $updated = 0;
        $upsert = $db->prepare(
            'INSERT INTO exchange_rates (from_currency, to_currency, rate, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), updated_at = NOW()'
        );

        foreach ($activeCurrencies as $code) {
            if (isset($rates[$code])) {
                $upsert->execute(['GBP', $code, $rates[$code]]);
                $updated++;
            }
        }

        $db->prepare(
            'UPDATE currencies c
             JOIN exchange_rates er ON er.from_currency = "GBP" AND er.to_currency = c.code
             SET c.rate_to_gbp = er.rate, c.updated_at = NOW()
             WHERE c.is_active = 1'
        )->execute();

        Response::json(['success' => true, 'updated' => $updated]);
    }
}

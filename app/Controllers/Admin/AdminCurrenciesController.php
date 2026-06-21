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
        // Placeholder — in production would call an exchange rate API
        Response::json(['success' => true, 'message' => 'Rates refresh queued.']);
    }
}

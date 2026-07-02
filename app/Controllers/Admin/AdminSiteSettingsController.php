<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;

/**
 * Public site settings — the brand contact details and logo shown across the
 * site. Stored in the shared `settings` key/value table.
 */
class AdminSiteSettingsController
{
    private const KEYS = [
        'site_email', 'site_phone', 'site_whatsapp', 'site_logo',
        // Site control / maintenance
        'maintenance_mode', 'bookings_enabled', 'announcement',
    ];

    private static function readAll(): array
    {
        $out = [
            'site_email' => '', 'site_phone' => '', 'site_whatsapp' => '', 'site_logo' => '',
            'maintenance_mode' => '0', 'bookings_enabled' => '1', 'announcement' => '',
        ];
        try {
            $in  = implode(',', array_fill(0, count(self::KEYS), '?'));
            $st  = Database::getInstance()->prepare("SELECT key_name, value FROM settings WHERE key_name IN ($in)");
            $st->execute(self::KEYS);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[$row['key_name']] = (string) ($row['value'] ?? '');
            }
        } catch (\Throwable) {}
        return $out;
    }

    // GET /api/admin/site-settings  (admin) and GET /api/site-settings (public)
    public function index(Request $request): void
    {
        Response::json(['settings' => self::readAll()]);
    }

    // PUT /api/admin/site-settings — upsert the brand contact details + logo.
    public function update(Request $request): void
    {
        $db  = Database::getInstance();
        $ins = $db->prepare(
            'INSERT INTO settings (key_name, value, type, description, updated_at)
             VALUES (?, ?, "string", ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
        );
        $labels = [
            'site_email'       => 'بريد الموقع',
            'site_phone'       => 'هاتف الموقع',
            'site_whatsapp'    => 'واتساب الموقع',
            'site_logo'        => 'شعار الموقع',
            'maintenance_mode' => 'وضع الصيانة',
            'bookings_enabled' => 'تفعيل الحجز',
            'announcement'     => 'شريط إعلاني',
        ];
        $bools = ['maintenance_mode', 'bookings_enabled'];
        foreach (self::KEYS as $k) {
            $v = $request->input($k);
            if ($v === null) continue;
            $v = in_array($k, $bools, true)
                ? (filter_var($v, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : trim((string) $v);
            $ins->execute([$k, $v, $labels[$k] ?? $k]);
        }

        \App\Services\AdminActivityLog::record('update', 'site_settings', null, null, 'تحديث إعدادات الموقع');

        Response::json(['message' => 'تم حفظ إعدادات الموقع بنجاح.', 'settings' => self::readAll()]);
    }
}

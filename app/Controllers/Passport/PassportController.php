<?php

declare(strict_types=1);

namespace App\Controllers\Passport;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;

class PassportController
{
    public function scan(Request $request): void
    {
        $user = AuthMiddleware::currentUser();
        if ($user === null) {
            Response::unauthorized();
        }

        $imageData = (string)($request->input('image') ?? '');
        if (empty($imageData)) {
            Response::error('الصورة مطلوبة.', 400);
        }

        // Strip data URI prefix if present: data:image/jpeg;base64,...
        $base64 = $imageData;
        $mediaType = 'image/jpeg';
        if (str_starts_with($imageData, 'data:')) {
            [$prefix, $base64] = explode(',', $imageData, 2);
            if (preg_match('/data:([^;]+);base64/', $prefix, $m)) {
                $mediaType = $m[1];
            }
        }

        // Validate size (max ~5MB decoded ≈ ~6.7MB base64)
        if (strlen($base64) > 7_000_000) {
            Response::error('الصورة كبيرة جداً. الحد الأقصى 5 ميجابايت.', 400);
        }

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if (empty($apiKey)) {
            Response::error('خدمة المسح غير مفعّلة. أدخل البيانات يدوياً.', 503);
        }

        $prompt = <<<PROMPT
Extract passport or identity document data from this image.
Return ONLY a JSON object with these exact keys (use empty string "" if not found):
{
  "surname": "LAST NAME as on document",
  "first_name": "FIRST given name",
  "middle_name": "middle names (space separated) or empty",
  "nationality": "ISO 3166-1 alpha-3 country code e.g. KWT",
  "doc_country": "issuing country ISO 3 code e.g. KWT",
  "doc_number": "document number (alphanumeric, no spaces)",
  "date_of_birth": "YYYY-MM-DD",
  "doc_expiry": "YYYY-MM-DD",
  "gender": "male or female",
  "doc_type": "passport or id_card or national_id"
}
Return only the JSON object. No explanation. No markdown.
PROMPT;

        $payload = json_encode([
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 400,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    [
                        'type'  => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $mediaType,
                            'data'       => $base64,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || !$raw) {
            error_log('[PassportScan] curl error: ' . $err);
            Response::error('تعذر الاتصال بخدمة المسح. أدخل البيانات يدوياً.', 502);
        }

        $anthropic = json_decode($raw, true);
        if ($code !== 200 || empty($anthropic['content'][0]['text'])) {
            error_log('[PassportScan] Anthropic error ' . $code . ': ' . $raw);
            Response::error('لم يتمكن النظام من قراءة الوثيقة. حاول بصورة أوضح أو أدخل البيانات يدوياً.', 422);
        }

        $text   = trim($anthropic['content'][0]['text']);
        // Extract JSON object from the response (in case model wraps it)
        if (preg_match('/\{[\s\S]+\}/', $text, $jm)) {
            $text = $jm[0];
        }
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            Response::error('تعذر تحليل بيانات الوثيقة. حاول بصورة أوضح.', 422);
        }

        Response::json(['data' => $parsed]);
    }
}

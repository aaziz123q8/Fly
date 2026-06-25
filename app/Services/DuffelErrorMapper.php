<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Maps Duffel API error codes to Arabic customer messages and appropriate HTTP status codes.
 *
 * Message format in the RuntimeException is "arabic_message|internal_message"
 * so callers can extract both without a separate DTO.
 *
 * Usage:
 *   try { $this->duffel->createOrder(...); }
 *   catch (\Throwable $e) {
 *       $mapped = DuffelErrorMapper::fromDuffelException($e);
 *       [$arabic, $internal] = DuffelErrorMapper::split($mapped->getMessage());
 *       throw new RuntimeException($arabic, $mapped->getCode());
 *   }
 */
class DuffelErrorMapper
{
    /**
     * Duffel error code → [Arabic customer message, HTTP status, recommended action]
     */
    private const ERROR_MAP = [
        'order_type_not_eligible_for_payment' => [
            'ar'     => 'نوع هذا الحجز لا يقبل الدفع المباشر. يرجى التواصل مع الدعم.',
            'status' => 422,
            'action' => 'contact_support',
        ],
        'payment_amount_does_not_match_order_amount' => [
            'ar'     => 'تغير سعر الرحلة قبل إتمام الحجز. يرجى تحديث السعر والمحاولة مرة أخرى.',
            'status' => 409,
            'action' => 'reprice',
        ],
        'payment_currency_does_not_match_order_currency' => [
            'ar'     => 'عملة الدفع لا تتطابق مع عملة الحجز. يرجى التواصل مع الدعم.',
            'status' => 409,
            'action' => 'contact_support',
        ],
        'already_paid' => [
            'ar'     => 'تم دفع هذا الحجز مسبقاً.',
            'status' => 409,
            'action' => 'check_existing',
        ],
        'already_cancelled' => [
            'ar'     => 'تم إلغاء هذا الحجز مسبقاً.',
            'status' => 409,
            'action' => 'check_existing',
        ],
        'past_payment_required_by_date' => [
            'ar'     => 'انتهت مهلة الدفع لهذا الحجز. يرجى البحث من جديد.',
            'status' => 410,
            'action' => 'new_search',
        ],
        'schedule_changed' => [
            'ar'     => 'طرأ تغيير على الرحلة من شركة الطيران. يرجى البحث من جديد.',
            'status' => 409,
            'action' => 'new_search',
        ],
        'offer_no_longer_available' => [
            'ar'     => 'انتهت صلاحية هذا العرض. يرجى البحث من جديد.',
            'status' => 410,
            'action' => 'new_search',
        ],
        'offer_expired' => [
            'ar'     => 'انتهت صلاحية هذا العرض. يرجى البحث من جديد.',
            'status' => 410,
            'action' => 'new_search',
        ],
        'airline_error' => [
            'ar'     => 'رفضت شركة الطيران الحجز. يرجى المحاولة مجدداً أو التواصل مع الدعم.',
            'status' => 502,
            'action' => 'retry_or_support',
        ],
        'insufficient_balance' => [
            'ar'     => 'رصيد حساب Duffel غير كافٍ. يرجى إضافة رصيد تجريبي من لوحة تحكم Duffel (Settings → Balance).',
            'status' => 402,
            'action' => 'top_up_balance',
        ],
        'not_supported' => [
            'ar'     => 'هذا الحجز غير مدعوم حالياً. يرجى التواصل مع الدعم.',
            'status' => 422,
            'action' => 'contact_support',
        ],
        'invalid_passenger_identity_document' => [
            'ar'     => 'بيانات جواز سفر أحد المسافرين غير صحيحة. يرجى التحقق من البيانات.',
            'status' => 422,
            'action' => 'fix_passengers',
        ],
        'offer_id_already_used' => [
            'ar'     => 'تم استخدام هذا العرض من قبل. يرجى البحث عن رحلة جديدة.',
            'status' => 409,
            'action' => 'new_search',
        ],
        'service_not_available_for_offer' => [
            'ar'     => 'إحدى الخدمات الإضافية المختارة لم تعد متاحة. يرجى تحديث اختياراتك.',
            'status' => 409,
            'action' => 'reselect_services',
        ],
        'price_guarantee_expired' => [
            'ar'     => 'انتهت ضمانة السعر. يرجى البحث من جديد.',
            'status' => 410,
            'action' => 'new_search',
        ],
        'invalid_email' => [
            'ar'     => 'البريد الإلكتروني المدخل غير صحيح. يرجى التحقق من البيانات.',
            'status' => 422,
            'action' => 'fix_passengers',
        ],
        'invalid_phone_number' => [
            'ar'     => 'رقم الهاتف غير صحيح. يرجى إدخاله بصيغة دولية (+XXXXXXXX).',
            'status' => 422,
            'action' => 'fix_passengers',
        ],
        'invalid_identity_document' => [
            'ar'     => 'بيانات وثيقة سفر أحد المسافرين غير صحيحة. يرجى المراجعة.',
            'status' => 422,
            'action' => 'fix_passengers',
        ],
        'passenger_already_flying' => [
            'ar'     => 'أحد المسافرين محجوز بالفعل على هذه الرحلة.',
            'status' => 409,
            'action' => 'check_existing',
        ],
        // 3DS session errors (createOrder with card payment)
        'three_d_secure_session_not_found' => [
            'ar'     => 'لم يتم العثور على جلسة التحقق الأمني. يرجى المحاولة مجدداً.',
            'status' => 422,
            'action' => 'retry_payment',
        ],
        'three_d_secure_session_not_ready_for_payment' => [
            'ar'     => 'جلسة التحقق الأمني غير جاهزة للدفع. يرجى إعادة التحقق أو المحاولة مجدداً.',
            'status' => 422,
            'action' => 'retry_payment',
        ],
        'three_d_secure_session_expired' => [
            'ar'     => 'انتهت صلاحية جلسة التحقق الأمني. يرجى المحاولة مجدداً.',
            'status' => 422,
            'action' => 'retry_payment',
        ],
        // Card errors
        'payment_declined' => [
            'ar'     => 'تم رفض الدفع. يرجى التحقق من بيانات البطاقة أو استخدام بطاقة أخرى.',
            'status' => 422,
            'action' => 'retry_payment',
        ],
        'invalid_card_expiration_date' => [
            'ar'     => 'تاريخ انتهاء صلاحية البطاقة غير صحيح.',
            'status' => 422,
            'action' => 'fix_card',
        ],
        // Airline errors
        'price_changed' => [
            'ar'     => 'تغير سعر الرحلة. يرجى البحث من جديد.',
            'status' => 409,
            'action' => 'new_search',
        ],
        'duplicate_booking' => [
            'ar'     => 'يوجد حجز مكرر بنفس البيانات لهذه الرحلة.',
            'status' => 409,
            'action' => 'check_existing',
        ],
        'ancillary_service_not_available' => [
            'ar'     => 'إحدى الخدمات الإضافية المختارة (كالمقاعد) لم تعد متاحة. يرجى تحديث اختياراتك.',
            'status' => 409,
            'action' => 'reselect_services',
        ],
        'order_not_created' => [
            'ar'     => 'لم يتم إنشاء الحجز. يرجى عدم إعادة المحاولة والتواصل مع الدعم.',
            'status' => 422,
            'action' => 'contact_support',
        ],
        'invalid_intended_card' => [
            'ar'     => 'البطاقة المستخدمة غير صالحة. يرجى المحاولة مجدداً.',
            'status' => 422,
            'action' => 'retry_payment',
        ],
    ];

    /**
     * Parse a Duffel exception and return a mapped RuntimeException.
     *
     * Tries to extract structured error codes from the exception message
     * (DuffelAdapter typically embeds the JSON response body in the message).
     */
    public static function fromDuffelException(\Throwable $e): RuntimeException
    {
        $msg  = $e->getMessage();

        // Extract embedded JSON from DuffelAdapter error messages ("...: {json}")
        $jsonStart = strpos($msg, '{');
        $body      = $jsonStart !== false ? substr($msg, $jsonStart) : null;

        if ($body !== null) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $errors = $decoded['errors'] ?? [];
                foreach ((array)$errors as $error) {
                    $code = $error['code'] ?? '';
                    if (isset(self::ERROR_MAP[$code])) {
                        $entry    = self::ERROR_MAP[$code];
                        $internal = sprintf(
                            '[Duffel:%s] field=%s title=%s message=%s',
                            $code,
                            $error['source']['pointer'] ?? 'n/a',
                            $error['title']   ?? '',
                            $error['message'] ?? $msg
                        );
                        return new RuntimeException(
                            $entry['ar'] . '|' . $internal,
                            $entry['status']
                        );
                    }
                }
            }
        }

        // Fallback: extract whatever Duffel gave us even if code is not in our map
        $firstError = null;
        if ($body !== null) {
            $dec = $decoded ?? json_decode($body, true);
            $firstError = (is_array($dec) ? $dec['errors'][0] ?? null : null);
        }
        $rawCode   = $firstError['code']    ?? 'unknown';
        $rawTitle  = $firstError['title']   ?? '';
        $rawDetail = $firstError['message'] ?? '';
        $requestId = ($decoded ?? [])['meta']['request_id'] ?? '';

        $internal = sprintf(
            '[Duffel:%s] title="%s" detail="%s" request_id=%s exception=%s',
            $rawCode, $rawTitle, $rawDetail, $requestId, substr($msg, 0, 600)
        );

        // Show the real Duffel code+message to help diagnose — change to generic once resolved
        $customerMsg = sprintf(
            'خطأ Duffel [%s]: %s',
            $rawCode,
            $rawDetail ?: ($rawTitle ?: 'راجع لوحة تحكم Duffel')
        );

        return new RuntimeException($customerMsg . '|' . $internal, 502);
    }

    /**
     * Split the dual-message format "arabic_message|internal_message" into its parts.
     *
     * @return array{customer: string, internal: string}
     */
    public static function split(string $message): array
    {
        $parts = explode('|', $message, 2);
        return [
            'customer' => $parts[0],
            'internal' => $parts[1] ?? $parts[0],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ConfigLoader;

/**
 * DemoHotelData
 *
 * Canned hotel data so the hotel flow (search → detail → prebook → book → pay →
 * confirm) can be exercised end-to-end WITHOUT a RateHawk account. Demo mode is
 * active when HOTELS_DEMO is truthy, or automatically when RateHawk has no
 * credentials — so real RateHawk resumes by itself once keys are configured,
 * and RateHawk settings are never touched.
 *
 * Fields are shaped to match exactly what the hotel frontend reads (h.id,
 * h.name, h.min_price, h.stars, h.city, rooms[].price/book_hash, etc.).
 */
class DemoHotelData
{
    public static function isEnabled(): bool
    {
        if (filter_var(getenv('HOTELS_DEMO') ?: '0', FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        // No RateHawk credentials → fall back to demo so hotels stay testable.
        $cfg    = ConfigLoader::load('apis')['ratehawk'] ?? [];
        $keyId  = $cfg['key_id']  ?? (getenv('RATEHAWK_KEY_ID')  ?: '');
        $apiKey = $cfg['api_key'] ?? (getenv('RATEHAWK_API_KEY') ?: '');
        return $keyId === '' || $apiKey === '';
    }

    /**
     * Search results — list shape consumed by hotels.html renderHotels().
     * @return list<array<string,mixed>>
     */
    public static function hotels(): array
    {
        return array_map(static function (array $h): array {
            $min = null;
            foreach ($h['rooms'] as $r) {
                $min = $min === null ? $r['price'] : min($min, $r['price']);
            }
            return [
                'id'                => $h['id'],
                'provider_hotel_id' => $h['id'],
                'name'              => $h['name'],
                'name_en'           => $h['name_en'],
                'city'              => $h['city'],
                'address'           => $h['address'],
                'stars'             => $h['stars'],
                'star_rating'       => $h['stars'],
                'rating'            => $h['rating'],
                'review_score'      => $h['rating'],
                'review_count'      => $h['review_count'],
                'image_url'         => $h['image_url'],
                'main_image_url'    => $h['image_url'],
                'min_price'         => $min,
                'currency'          => 'GBP',
                'is_demo'           => true,
            ];
        }, self::catalog());
    }

    /**
     * Single hotel + rooms — consumed by hotel-detail.html.
     * @return array<string,mixed>|null
     */
    public static function hotel(string $id): ?array
    {
        foreach (self::catalog() as $h) {
            if ($h['id'] === $id) {
                return [
                    'id'                => $h['id'],
                    'provider_hotel_id' => $h['id'],
                    'name'              => $h['name'],
                    'name_en'           => $h['name_en'],
                    'name_ar'           => $h['name'],
                    'city'              => $h['city'],
                    'address'           => $h['address'],
                    'stars'             => $h['stars'],
                    'star_rating'       => $h['stars'],
                    'rating'            => $h['rating'],
                    'review_count'      => $h['review_count'],
                    'image_url'         => $h['image_url'],
                    'main_image_url'    => $h['image_url'],
                    'description'       => $h['description'],
                    'amenities'         => $h['amenities'],
                    'check_in_time'     => '15:00',
                    'check_out_time'    => '12:00',
                    'images'            => [],
                    'rooms'             => $h['rooms'],
                    'currency'          => 'GBP',
                    'is_demo'           => true,
                ];
            }
        }
        return null;
    }

    /**
     * The underlying catalog. Each room carries a book_hash that encodes its
     * price so the demo prebook can confirm it without any external call.
     */
    private static function catalog(): array
    {
        return [
            [
                'id' => 'demo-hotel-dxb-1', 'name' => 'فندق جراند بلازا دبي', 'name_en' => 'Grand Plaza Dubai',
                'city' => 'دبي', 'address' => 'شارع الشيخ زايد، دبي', 'stars' => 5, 'rating' => 9.2, 'review_count' => 1840,
                'image_url' => '', 'description' => 'فندق فاخر 5 نجوم في قلب دبي مع إطلالات بانورامية ومسبح خارجي.',
                'amenities' => ['واي فاي مجاني', 'مسبح', 'سبا', 'صالة رياضية', 'مطعم', 'موقف سيارات'],
                'rooms' => [
                    ['id' => 'r-dxb-1a', 'name' => 'غرفة ديلوكس', 'price' => 95.0, 'meal_plan' => 'إفطار مجاني', 'bed_type' => 'سرير كبير', 'max_guests' => 2, 'size_sqm' => 35, 'free_cancellation' => true,  'book_hash' => 'DEMO|95|deluxe'],
                    ['id' => 'r-dxb-1b', 'name' => 'جناح تنفيذي', 'price' => 165.0, 'meal_plan' => 'إفطار وعشاء', 'bed_type' => 'سرير كبير', 'max_guests' => 3, 'size_sqm' => 60, 'free_cancellation' => true,  'book_hash' => 'DEMO|165|suite'],
                ],
            ],
            [
                'id' => 'demo-hotel-dxb-2', 'name' => 'فندق برج السلام', 'name_en' => 'Burj Al Salam', 'city' => 'دبي',
                'address' => 'ديرة، دبي', 'stars' => 4, 'rating' => 8.4, 'review_count' => 920, 'image_url' => '',
                'description' => 'فندق 4 نجوم عملي قريب من الأسواق والمترو.',
                'amenities' => ['واي فاي مجاني', 'مطعم', 'موقف سيارات', 'خدمة الغرف'],
                'rooms' => [
                    ['id' => 'r-dxb-2a', 'name' => 'غرفة قياسية', 'price' => 62.0, 'meal_plan' => 'بدون وجبات', 'bed_type' => 'سريران مفردان', 'max_guests' => 2, 'size_sqm' => 24, 'free_cancellation' => false, 'book_hash' => 'DEMO|62|standard'],
                    ['id' => 'r-dxb-2b', 'name' => 'غرفة عائلية', 'price' => 88.0, 'meal_plan' => 'إفطار مجاني', 'bed_type' => 'سرير كبير + أريكة', 'max_guests' => 4, 'size_sqm' => 40, 'free_cancellation' => true, 'book_hash' => 'DEMO|88|family'],
                ],
            ],
            [
                'id' => 'demo-hotel-kwi-1', 'name' => 'فندق أبراج الكويت', 'name_en' => 'Kuwait Towers Hotel', 'city' => 'الكويت',
                'address' => 'شارع الخليج العربي، الكويت', 'stars' => 5, 'rating' => 9.0, 'review_count' => 640, 'image_url' => '',
                'description' => 'فندق 5 نجوم على الواجهة البحرية بإطلالة على أبراج الكويت.',
                'amenities' => ['واي فاي مجاني', 'مسبح', 'شاطئ خاص', 'سبا', 'مطعمان'],
                'rooms' => [
                    ['id' => 'r-kwi-1a', 'name' => 'غرفة بإطلالة بحرية', 'price' => 78.0, 'meal_plan' => 'إفطار مجاني', 'bed_type' => 'سرير كبير', 'max_guests' => 2, 'size_sqm' => 38, 'free_cancellation' => true, 'book_hash' => 'DEMO|78|seaview'],
                ],
            ],
            [
                'id' => 'demo-hotel-kwi-2', 'name' => 'نزل روز جاردن', 'name_en' => 'Rose Garden Inn', 'city' => 'الكويت',
                'address' => 'السالمية، الكويت', 'stars' => 3, 'rating' => 7.8, 'review_count' => 410, 'image_url' => '',
                'description' => 'نزل اقتصادي 3 نجوم مريح وقريب من المطاعم.',
                'amenities' => ['واي فاي مجاني', 'موقف سيارات'],
                'rooms' => [
                    ['id' => 'r-kwi-2a', 'name' => 'غرفة مزدوجة', 'price' => 40.0, 'meal_plan' => 'بدون وجبات', 'bed_type' => 'سرير مزدوج', 'max_guests' => 2, 'size_sqm' => 20, 'free_cancellation' => false, 'book_hash' => 'DEMO|40|double'],
                ],
            ],
        ];
    }
}

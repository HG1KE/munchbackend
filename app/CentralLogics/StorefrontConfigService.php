<?php

namespace App\CentralLogics;

use App\Model\Branch;
use App\Model\Currency;
use App\Model\SocialMedia;
use App\Model\TimeSchedule;
use App\Services\Auth\EmergencyOtpModeService;
use App\Traits\HelperTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Builds GET /api/v1/config payload with batched settings reads and branch schedule preloading.
 */
class StorefrontConfigService
{
    use HelperTrait;

    private static bool $lastResponseWasCacheHit = false;

    public static function wasLastResponseCacheHit(): bool
    {
        return self::$lastResponseWasCacheHit;
    }

    public static function cacheKey(): string
    {
        return 'storefront_api_config_v1';
    }

    public static function forgetCachedConfiguration(): void
    {
        Cache::forget(self::cacheKey());
    }

    public static function invalidateAfterScheduleChange(): void
    {
        Helpers::forgetRestaurantSchedulesRuntimeCache();
        self::forgetCachedConfiguration();
    }

    public static function getConfigurationPayload(): array
    {
        $key = self::cacheKey();
        $ttl = max(30, (int) config('storefront.config_cache_ttl', 120));

        if (Cache::has($key)) {
            self::$lastResponseWasCacheHit = true;
            $payload = Cache::get($key);

            return self::applyDynamicFields($payload);
        }

        self::$lastResponseWasCacheHit = false;
        $started = microtime(true);
        $queryCount = 0;

        if (config('storefront.instrument_config')) {
            DB::listen(static function () use (&$queryCount): void {
                $queryCount++;
            });
        }

        $payload = self::buildUncached();
        Cache::put($key, $payload, $ttl);
        $payload = self::applyDynamicFields($payload);

        if (config('storefront.instrument_config')) {
            Log::info('api.config.built', [
                'cache_hit' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'query_count' => $queryCount,
                'payload_bytes' => strlen((string) json_encode($payload)),
                'branch_count' => is_array($payload['branches'] ?? null) ? count($payload['branches']) : 0,
            ]);
        }

        return $payload;
    }

    /**
     * Recompute time-sensitive fields on every request (even when config payload is cached).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function applyDynamicFields(array $payload): array
    {
        $restaurantSchedulesByDay = collect($payload['restaurant_schedule_time'] ?? [])->groupBy('day');
        $now = Carbon::now();
        $dayOfWeek = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        if (isset($payload['branches']) && is_array($payload['branches'])) {
            $payload['branches'] = array_map(
                static fn (array $branch) => Helpers::applyBranchAvailabilityToPayloadArray(
                    $branch,
                    $dayOfWeek,
                    $currentTime,
                    $restaurantSchedulesByDay
                ),
                $payload['branches']
            );
        }

        $payload['advance_maintenance_mode'] = (new self)->checkMaintenanceMode();

        if (isset($payload['customer_verification']) && is_array($payload['customer_verification'])) {
            $payload['customer_verification']['emergency_otp_mode'] = app(EmergencyOtpModeService::class)->enabled();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildUncached(): array
    {
        $settings = Helpers::businessSettingsByKey();
        $get = static function (string $key, mixed $default = null) use ($settings): mixed {
            $row = $settings->get($key);
            if ($row === null) {
                return $default;
            }
            $decoded = json_decode($row->value, true);

            return $decoded ?? $row->value ?? $default;
        };

        $restaurantLogo = $get('logo');
        $restaurantFavicon = $get('fav_icon');
        $restaurantImageBase = asset('storage/app/public/restaurant');

        $resolveRestaurantAssetUrl = static function ($path) use ($restaurantImageBase) {
            if (! is_string($path) || trim($path) === '') {
                return null;
            }
            $trimmed = trim($path);
            if (preg_match('/^https?:\/\//i', $trimmed)) {
                return $trimmed;
            }

            return rtrim($restaurantImageBase, '/').'/'.ltrim($trimmed, '/');
        };

        $digitalPayment = $get('digital_payment', ['status' => 0]);
        if (! is_array($digitalPayment)) {
            $digitalPayment = ['status' => 0];
        }

        $publishedStatus = 0;
        $paymentPublishedStatus = config('get_payment_publish_status');
        if (isset($paymentPublishedStatus[0]['is_published'])) {
            $publishedStatus = $paymentPublishedStatus[0]['is_published'];
        }

        $activeAddonPaymentLists = $publishedStatus == 1
            ? self::getPaymentMethods()
            : self::getDefaultPaymentMethods();

        $digitalPaymentInfos = [
            'digital_payment' => ($digitalPayment['status'] ?? 0) == 1 ? 'true' : 'false',
            'plugin_payment_gateways' => $publishedStatus ? 'true' : 'false',
            'default_payment_gateways' => $publishedStatus ? 'false' : 'true',
        ];

        $currencyCode = Helpers::currency_code();
        $currencySymbol = Currency::query()
            ->where('currency_code', $currencyCode)
            ->value('currency_symbol') ?? '';

        $cod = $get('cash_on_delivery', ['status' => 0]);
        if (! is_array($cod)) {
            $cod = ['status' => 0];
        }

        $deliveryConfig = $get('delivery_management', []);
        if (! is_array($deliveryConfig)) {
            $deliveryConfig = [];
        }
        $deliveryManagement = [
            'status' => (int) ($deliveryConfig['status'] ?? 0),
            'min_shipping_charge' => (float) ($deliveryConfig['min_shipping_charge'] ?? 0),
            'shipping_per_km' => (float) ($deliveryConfig['shipping_per_km'] ?? 0),
        ];

        $playStoreConfig = $get('play_store_config', []);
        $appStoreConfig = $get('app_store_config', []);
        if (! is_array($playStoreConfig)) {
            $playStoreConfig = [];
        }
        if (! is_array($appStoreConfig)) {
            $appStoreConfig = [];
        }

        $schedules = TimeSchedule::query()
            ->select(['day', 'opening_time', 'closing_time'])
            ->get();

        $branchPromotion = Branch::query()
            ->with('branch_promotion')
            ->where(['branch_promotion_status' => 1])
            ->get();

        $google = $get('google_social_login', 0);
        $facebook = $get('facebook_social_login', 0);

        $cookiesConfig = $get('cookies', []);
        if (! is_array($cookiesConfig)) {
            $cookiesConfig = [];
        }
        $cookies_management = [
            'status' => (int) ($cookiesConfig['status'] ?? 0),
            'text' => $cookiesConfig['text'] ?? '',
        ];

        $offlinePayment = $get('offline_payment', ['status' => 0]);
        if (! is_array($offlinePayment)) {
            $offlinePayment = ['status' => 0];
        }

        $apple = $get('apple_login', []);
        if (! is_array($apple)) {
            $apple = [];
        }
        $appleLogin = [
            'login_medium' => $apple['login_medium'] ?? '',
            'status' => 0,
            'client_id' => $apple['client_id'] ?? '',
        ];

        $firebaseOTPVerification = $get('firebase_otp_verification', []);
        if (! is_array($firebaseOTPVerification)) {
            $firebaseOTPVerification = [];
        }

        $emailVerification = (int) (Helpers::get_login_settings('email_verification') ?? 0);
        $phoneVerification = (int) (Helpers::get_login_settings('phone_verification') ?? 0);

        $status = 0;
        if ($emailVerification === 1) {
            $status = 1;
        } elseif ($phoneVerification === 1) {
            $status = 1;
        }

        $customerVerification = [
            'status' => $status,
            'phone' => $phoneVerification,
            'email' => $emailVerification,
            'firebase' => (int) ($firebaseOTPVerification['status'] ?? 0),
            'emergency_otp_mode' => app(EmergencyOtpModeService::class)->enabled(),
        ];

        $loginOptions = Helpers::get_login_settings('login_options');
        $socialMediaLoginOptions = Helpers::get_login_settings('social_media_for_login');

        $customerLogin = [
            'login_option' => $loginOptions,
            'social_media_login_options' => $socialMediaLoginOptions,
        ];

        $restaurantLocationCoverage = Branch::query()
            ->where(['id' => 1])
            ->first(['longitude', 'latitude', 'coverage']);

        $restaurantSchedulesByDay = $schedules->groupBy('day');
        $branches = self::buildBranchesWithAvailability($restaurantSchedulesByDay);

        return [
            'restaurant_name' => $get('restaurant_name'),
            'restaurant_phone' => $get('phone'),
            'restaurant_open_time' => $get('restaurant_open_time'),
            'restaurant_close_time' => $get('restaurant_close_time'),
            'restaurant_schedule_time' => $schedules,
            'restaurant_logo' => $restaurantLogo,
            'restaurant_favicon' => $restaurantFavicon,
            'restaurant_logo_full_url' => $resolveRestaurantAssetUrl($restaurantLogo),
            'restaurant_favicon_full_url' => $resolveRestaurantAssetUrl($restaurantFavicon),
            'branding' => [
                'logo' => $restaurantLogo,
                'logo_full_url' => $resolveRestaurantAssetUrl($restaurantLogo),
                'favicon' => $restaurantFavicon,
                'favicon_full_url' => $resolveRestaurantAssetUrl($restaurantFavicon),
            ],
            'restaurant_address' => $get('address'),
            'restaurant_email' => $get('email_address'),
            'restaurant_location_coverage' => $restaurantLocationCoverage,
            'minimum_order_value' => (float) $get('minimum_order_value', 0),
            'base_urls' => [
                'product_image_url' => asset('storage/app/public/product'),
                'customer_image_url' => asset('storage/app/public/profile'),
                'banner_image_url' => asset('storage/app/public/banner'),
                'category_image_url' => asset('storage/app/public/category'),
                'category_banner_image_url' => asset('storage/app/public/category/banner'),
                'review_image_url' => asset('storage/app/public/review'),
                'notification_image_url' => asset('storage/app/public/notification'),
                'restaurant_image_url' => asset('storage/app/public/restaurant'),
                'delivery_man_image_url' => asset('storage/app/public/delivery-man'),
                'chat_image_url' => asset('storage/app/public/conversation'),
                'promotional_url' => asset('storage/app/public/promotion'),
                'kitchen_image_url' => asset('storage/app/public/kitchen'),
                'branch_image_url' => asset('storage/app/public/branch'),
                'gateway_image_url' => asset('storage/app/public/payment_modules/gateway_image'),
                'payment_image_url' => asset('public/assets/admin/img/payment'),
                'cuisine_image_url' => asset('storage/app/public/cuisine'),
            ],
            'currency_symbol' => $currencySymbol,
            'delivery_charge' => (float) $get('delivery_charge', 0),
            'delivery_management' => $deliveryManagement,
            'branches' => $branches,
            'email_verification' => (bool) ($get('email_verification') ?? 0),
            'phone_verification' => (bool) ($get('phone_verification') ?? 0),
            'currency_symbol_position' => $get('currency_symbol_position', 'right'),
            'country' => $get('country', 'BD'),
            'self_pickup' => (bool) ($get('self_pickup') ?? 1),
            'delivery' => (bool) ($get('delivery') ?? 1),
            'play_store_config' => [
                'status' => isset($playStoreConfig) && (bool) ($playStoreConfig['status'] ?? false),
                'link' => isset($playStoreConfig) ? ($playStoreConfig['link'] ?? null) : null,
                'min_version' => isset($playStoreConfig) && array_key_exists('min_version', $appStoreConfig)
                    ? ($playStoreConfig['min_version'] ?? null)
                    : null,
            ],
            'app_store_config' => [
                'status' => isset($appStoreConfig) && (bool) ($appStoreConfig['status'] ?? false),
                'link' => $appStoreConfig['link'] ?? null,
                'min_version' => array_key_exists('min_version', $appStoreConfig) ? ($appStoreConfig['min_version'] ?? null) : null,
            ],
            'social_media_link' => SocialMedia::query()->orderByDesc('id')->active()->get(),
            'software_version' => (string) (env('SOFTWARE_VERSION') ?? ''),
            'decimal_point_settings' => (int) ($get('decimal_point_settings', 2) ?? 2),
            'schedule_order_slot_duration' => (int) ($get('schedule_order_slot_duration', 30) ?? 30),
            'time_format' => (string) ($get('time_format', '12') ?? '12'),
            'promotion_campaign' => $branchPromotion,
            'social_login' => [
                'google' => (int) $google,
                'facebook' => (int) $facebook,
            ],
            'wallet_status' => (int) $get('wallet_status'),
            'loyalty_point_status' => (int) $get('loyalty_point_status'),
            'ref_earning_status' => (int) $get('ref_earning_status'),
            'loyalty_point_item_purchase_point' => (float) $get('loyalty_point_item_purchase_point'),
            'loyalty_point_exchange_rate' => (float) ($get('loyalty_point_exchange_rate', 0) ?? 0),
            'loyalty_point_minimum_point' => (float) ($get('loyalty_point_minimum_point', 0) ?? 0),
            'customer_referred_discount_status' => (int) ($get('customer_referred_discount_status', 0) ?? 0),
            'customer_referred_discount_type' => $get('customer_referred_discount_type', 'amount'),
            'customer_referred_discount_amount' => (float) ($get('customer_referred_discount_amount', 0) ?? 0),
            'customer_referred_validity_type' => $get('customer_referred_validity_type', 'day'),
            'customer_referred_validity_value' => (int) ($get('customer_referred_validity_value', 0) ?? 0),
            'whatsapp' => $get('whatsapp'),
            'cookies_management' => $cookies_management,
            'toggle_dm_registration' => (int) ($get('dm_self_registration', 0) ?? 0),
            'is_veg_non_veg_active' => (int) ($get('toggle_veg_non_veg', 0) ?? 0),
            'otp_resend_time' => $get('otp_resend_time', 60),
            'digital_payment_info' => $digitalPaymentInfos,
            'digital_payment_status' => (int) ($digitalPayment['status'] ?? 0),
            'active_payment_method_list' => (int) ($digitalPayment['status'] ?? 0) === 1 ? $activeAddonPaymentLists : [],
            'cash_on_delivery' => ($cod['status'] ?? 0) == 1 ? 'true' : 'false',
            'digital_payment' => ($digitalPayment['status'] ?? 0) == 1 ? 'true' : 'false',
            'offline_payment' => ($offlinePayment['status'] ?? 0) == 1 ? 'true' : 'false',
            'guest_checkout' => (int) ($get('guest_checkout', 0) ?? 0),
            'partial_payment' => (int) ($get('partial_payment', 0) ?? 0),
            'partial_payment_combine_with' => (string) $get('partial_payment_combine_with'),
            'add_fund_to_wallet' => (int) ($get('add_fund_to_wallet', 0) ?? 0),
            'apple_login' => $appleLogin,
            'cutlery_status' => (int) ($get('cutlery_status', 0) ?? 0),
            'firebase_otp_verification_status' => (int) ($firebaseOTPVerification ? ($firebaseOTPVerification['status'] ?? 0) : 0),
            'customer_verification' => $customerVerification,
            'footer_copyright_text' => $get('footer_text'),
            'footer_description_text' => $get('footer_description_text'),
            'customer_login' => $customerLogin,
            'google_map_status' => (int) ($get('google_map_status', 0) ?? 0),
            'maintenance_mode' => (bool) ($get('maintenance_mode') ?? 0),
            'advance_maintenance_mode' => [],
            'halal_tag_status' => (int) ($get('halal_tag_status') ?? 0),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, TimeSchedule>>  $restaurantSchedulesByDay
     * @return array<int, array<string, mixed>>
     */
    private static function buildBranchesWithAvailability($restaurantSchedulesByDay): array
    {
        $branches = Branch::query()
            ->with('branch_time_schedules')
            ->where('status', 1)
            ->get(['id', 'name', 'email', 'phone', 'longitude', 'latitude', 'address', 'coverage', 'status', 'image', 'cover_image', 'preparation_time']);

        $now = Carbon::now();
        $dayOfWeek = $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');

        return $branches->map(function (Branch $branch) use ($restaurantSchedulesByDay, $dayOfWeek, $currentTime) {
            return Helpers::branchPayloadWithAvailability($branch, $restaurantSchedulesByDay, $dayOfWeek, $currentTime);
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getPaymentMethods(): array
    {
        if (! Schema::hasTable('addon_settings')) {
            return [];
        }

        $methods = DB::table('addon_settings')->where('settings_type', 'payment_config')->get();
        $env = env('APP_ENV') == 'live' ? 'live' : 'test';
        $credentials = $env.'_values';

        $data = [];
        foreach ($methods as $method) {
            $credentialsData = json_decode($method->$credentials);
            $additionalData = json_decode($method->additional_data);
            if (isset($credentialsData->status) && $credentialsData->status == 1) {
                $data[] = [
                    'gateway' => $method->key_name,
                    'gateway_title' => $additionalData?->gateway_title,
                    'gateway_image' => $additionalData?->gateway_image,
                ];
            }
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getDefaultPaymentMethods(): array
    {
        if (! Schema::hasTable('addon_settings')) {
            return [];
        }

        $methods = DB::table('addon_settings')
            ->whereIn('settings_type', ['payment_config'])
            ->whereIn('key_name', ['ssl_commerz', 'paypal', 'stripe', 'razor_pay', 'senang_pay', 'pesapal', 'paystack', 'paymob_accept', 'flutterwave', 'bkash', 'mercadopago'])
            ->get();

        $env = env('APP_ENV') == 'live' ? 'live' : 'test';
        $credentials = $env.'_values';

        $data = [];
        foreach ($methods as $method) {
            $credentialsData = json_decode($method->$credentials);
            $additionalData = json_decode($method->additional_data);
            if (isset($credentialsData->status) && $credentialsData->status == 1) {
                $data[] = [
                    'gateway' => $method->key_name,
                    'gateway_title' => $additionalData?->gateway_title,
                    'gateway_image' => $additionalData?->gateway_image,
                ];
            }
        }

        return $data;
    }
}

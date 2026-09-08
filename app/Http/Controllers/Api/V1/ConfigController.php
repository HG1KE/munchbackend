<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\StorefrontConfigService;
use App\Http\Controllers\Controller;
use App\Model\Branch;
use App\Model\BusinessSetting;
use App\Model\Currency;
use App\Models\LoginSetup;
use App\Traits\HelperTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ConfigController extends Controller
{
    use HelperTrait;

    public function __construct(
        private Currency $currency,
        private Branch $branch,
        private BusinessSetting $businessSetting,
        private LoginSetup $loginSetup,
    ) {
    }

    public function configuration(): JsonResponse
    {
        $started = microtime(true);
        $payload = StorefrontConfigService::getConfigurationPayload();

        if (config('storefront.instrument_config')) {
            Log::info('api.config.metrics', [
                'cache_hit' => StorefrontConfigService::wasLastResponseCacheHit(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'payload_bytes' => strlen((string) json_encode($payload)),
                'branch_count' => is_array($payload['branches'] ?? null) ? count($payload['branches']) : 0,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ]);
        }

        return response()->json($payload, 200);
    }

    /**
     * @param Request $request
     * @return array|JsonResponse|mixed
     */
    public function direction_api(Request $request): mixed
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => 'required',
            'origin_long' => 'required',
            'destination_lat' => 'required',
            'destination_long' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $apiKey = Helpers::get_business_settings('map_api_client_key');
        $url = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';

        $origin = [
            'waypoint' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $request['origin_lat'],
                        'longitude' => $request['origin_long'],
                    ],
                ],
            ],
        ];

        $destination = [
            'waypoint' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $request['destination_lat'],
                        'longitude' => $request['destination_long'],
                    ],
                ],
            ],
        ];

        $data = [
            'origins' => $origin,
            'destinations' => $destination,
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $apiKey,
            'X-Goog-FieldMask' => '*',
        ];

        $response = Http::withHeaders($headers)->post($url, $data);

        return $response->json();
    }

    public function deliveryFree(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branch_id' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $branch = $this->branch->with(['delivery_charge_setup', 'delivery_charge_by_area'])
            ->where(['id' => $request['branch_id']])
            ->first(['id', 'name', 'status']);

        if (! $branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        if (isset($branch->delivery_charge_setup) && $branch->delivery_charge_setup->delivery_charge_type == 'distance') {
            unset($branch->delivery_charge_by_area);
            $branch->delivery_charge_by_area = [];
        }

        return response()->json($branch);
    }
}

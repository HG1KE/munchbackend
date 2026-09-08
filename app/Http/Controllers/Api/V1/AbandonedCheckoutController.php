<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\AbandonedCheckoutService;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AbandonedCheckoutController extends Controller
{
    /**
     * Capture (or refresh) a checkout-intent snapshot from the storefront.
     * Accepts both authenticated customers and guests.
     */
    public function track(Request $request): JsonResponse
    {
        $authUser = auth('api')->user();

        $validator = Validator::make($request->all(), [
            // Guests must send phone; authenticated customers use the account phone only (see below).
            'phone' => ($authUser ? 'nullable' : 'required').'|string|min:6|max:32',
            'branch_id' => 'nullable|integer',
            'guest_id' => 'nullable|integer',
            'cart' => 'nullable|array',
            'item_count' => 'nullable|integer|min:0',
            'expected_total' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|max:8',
            'locale' => 'nullable|string|max:8',
            'source' => 'nullable|string|max:32',
            'client_token' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $payload = $validator->validated();

        if ($authUser) {
            $payload['user_id'] = $authUser->id;
            $accountPhone = trim((string) ($authUser->phone ?? ''));
            if ($accountPhone === '') {
                return response()->json([
                    'errors' => [[
                        'code' => 'account_phone_missing',
                        'message' => 'No phone number on this account; abandoned checkout SMS cannot be targeted.',
                    ]],
                ], 403);
            }
            // Never trust client-supplied phone for logged-in customers (dedupe/cooldown stay account-scoped).
            $payload['phone'] = $accountPhone;
        }

        $row = AbandonedCheckoutService::capture($payload);
        if (! $row) {
            return response()->json(['errors' => [['code' => 'invalid', 'message' => 'Unable to record checkout intent.']]], 403);
        }

        return response()->json([
            'message' => 'tracked',
            'id' => (int) $row->id,
        ], 200);
    }

    /**
     * Idempotent helper for the storefront to explicitly mark a tracked checkout as recovered.
     */
    public function markRecovered(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'order_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        AbandonedCheckoutService::markRecovered(
            (int) $request->input('id'),
            $request->filled('order_id') ? (int) $request->input('order_id') : null
        );

        return response()->json(['message' => 'ok'], 200);
    }
}

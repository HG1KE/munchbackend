<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\LivePresenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LivePresenceController extends Controller
{
    /**
     * Lightweight storefront heartbeat — one upsert per client_token.
     */
    public function ping(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_token' => 'required|string|min:8|max:64',
            'state' => 'nullable|string|max:32',
            'branch_id' => 'nullable|integer',
            'current_path' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $payload = $validator->validated();
        $authUser = auth('api')->user();
        if ($authUser) {
            $payload['user_id'] = $authUser->id;
        }

        $row = LivePresenceService::recordPing($payload);
        if (! $row) {
            return response()->json(['errors' => [['code' => 'invalid', 'message' => 'Unable to record presence.']]], 403);
        }

        return response()->json(['message' => 'ok'], 200);
    }
}

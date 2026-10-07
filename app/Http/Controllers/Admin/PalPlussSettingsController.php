<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PalPlussException;
use App\Http\Controllers\Controller;
use App\Services\PalPluss\PalPlussConfigResolver;
use App\Services\PalPluss\PalPlussSettingsService;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PalPlussSettingsController extends Controller
{
    public function __construct(
        private readonly PalPlussSettingsService $settings,
        private readonly PalPlussConfigResolver $resolver,
    ) {
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'mode' => 'nullable|in:live,test',
            'api_key' => 'nullable|string|max:255',
            'channel_id' => 'nullable|string|max:64',
            'channel_shortcode' => 'nullable|string|max:50',
            'channel_type' => 'nullable|string|max:50',
            'channel_name' => 'nullable|string|max:120',
            'gateway_title' => 'nullable|string|max:120',
        ]);

        try {
            $this->settings->update([
                'status' => $request->has('status') ? 1 : 0,
                'mode' => $request->input('mode', 'live'),
                'api_key' => $request->input('api_key'),
                'channel_id' => $request->input('channel_id'),
                'channel_shortcode' => $request->input('channel_shortcode'),
                'channel_type' => $request->input('channel_type'),
                'channel_name' => $request->input('channel_name'),
                'gateway_title' => $request->input('gateway_title'),
            ]);
        } catch (PalPlussException $e) {
            Toastr::error($e->getMessage());

            return back();
        }

        Toastr::success(translate('updated successfully!'));

        return back();
    }

    public function listChannels(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'nullable|string|max:255',
        ]);

        try {
            $override = trim((string) $request->input('api_key', ''));
            // Ignore masked placeholders from the UI
            if ($override !== '' && str_contains($override, '•')) {
                $override = '';
            }

            $channels = $this->settings->listChannels($override !== '' ? $override : null);

            return response()->json([
                'success' => true,
                'channels' => $channels,
            ]);
        } catch (PalPlussException $e) {
            Log::warning('palpluss.admin_list_channels_failed', [
                'error_code' => $e->errorCode,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->httpStatus && $e->httpStatus >= 400 ? min($e->httpStatus, 422) : 422);
        }
    }

    public function verify(): JsonResponse
    {
        try {
            $result = $this->settings->verifyConnection();

            return response()->json([
                'success' => (bool) ($result['ok'] ?? false),
                'result' => $result,
            ]);
        } catch (PalPlussException $e) {
            Log::warning('palpluss.admin_verify_failed', [
                'error_code' => $e->errorCode,
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 422);
        }
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'config' => $this->resolver->adminSafeView(),
        ]);
    }
}

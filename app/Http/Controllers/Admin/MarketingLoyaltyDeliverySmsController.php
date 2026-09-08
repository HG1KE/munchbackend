<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\LoyaltyDeliverySmsService;
use App\CentralLogics\SMS_module;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketingLoyaltyDeliverySmsController extends Controller
{
    public function index(): View
    {
        $row = Setting::query()
            ->where('key_name', SMS_module::LOYALTY_DELIVERY_CAMPAIGN_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        $values = is_array($row?->live_values) ? $row->live_values : [
            'status' => 0,
            'message_template' => '',
            'test_phone' => '',
        ];

        return view('admin-views.marketing.loyalty-delivery-sms', compact('values'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['gateway' => SMS_module::LOYALTY_DELIVERY_CAMPAIGN_KEY]);

        return app(SMSModuleController::class)->smsUpdate($request, SMS_module::LOYALTY_DELIVERY_CAMPAIGN_KEY);
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $redirect = redirect()->route('admin.marketing.loyalty-delivery-sms.index');

        try {
            $validated = $request->validate([
                'test_phone' => ['required', 'string', 'min:9', 'max:32'],
            ]);

            $result = LoyaltyDeliverySmsService::sendTestSms((string) $validated['test_phone']);

            if ($result['ok']) {
                Toastr::success($result['message']);

                return $redirect;
            }

            Toastr::error($result['message']);

            return $redirect->withInput();
        } catch (ValidationException $e) {
            Toastr::error($e->validator->errors()->first() ?: 'Validation failed');

            return $redirect->withErrors($e->validator)->withInput();
        } catch (Throwable $e) {
            Log::error('loyalty_delivery_sms.test_exception', [
                'error' => $e->getMessage(),
            ]);

            Toastr::error('Test SMS could not be sent. Please try again.');

            return $redirect->withInput();
        }
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\SMS_module;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingPromotionalSmsController extends Controller
{
    public function index(): View
    {
        $row = Setting::query()
            ->where('key_name', SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        $values = is_array($row?->live_values) ? $row->live_values : [
            'status' => 0,
            'api_key' => '',
            'partner_id' => '',
            'sender_id' => '',
            'http_timeout_seconds' => '30',
        ];

        return view('admin-views.marketing.promotional-sms-gateway', compact('values'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['gateway' => SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY]);

        return app(SMSModuleController::class)->smsUpdate($request, SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY);
    }
}

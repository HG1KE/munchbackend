<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\SMS_module;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingAbandonedCheckoutController extends Controller
{
    public function index(): View
    {
        $row = Setting::query()
            ->where('key_name', SMS_module::ABANDONED_CHECKOUT_CAMPAIGN_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        $values = is_array($row?->live_values) ? $row->live_values : [
            'status' => 0,
            'message_template' => '',
            'delay_minutes' => '30',
            'max_attempts' => '1',
            'cooldown_hours' => '24',
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '08:00',
            'recovery_url' => '',
        ];

        return view('admin-views.marketing.abandoned-checkout', compact('values'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['gateway' => SMS_module::ABANDONED_CHECKOUT_CAMPAIGN_KEY]);

        return app(SMSModuleController::class)->smsUpdate($request, SMS_module::ABANDONED_CHECKOUT_CAMPAIGN_KEY);
    }
}

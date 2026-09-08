<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\ReorderReminderService;
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

class MarketingReorderReminderController extends Controller
{
    public function index(): View
    {
        $row = Setting::query()
            ->where('key_name', SMS_module::REORDER_REMINDER_CAMPAIGN_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        $values = is_array($row?->live_values) ? $row->live_values : [
            'status' => 0,
            'message_template' => '',
            'delay_days' => '14',
            'minimum_completed_orders' => '2',
            'max_attempts' => '1',
            'cooldown_days' => '30',
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '08:00',
            'recovery_url' => '',
            'branch_ids' => '',
            'test_phone' => '',
        ];

        return view('admin-views.marketing.reorder-reminders', compact('values'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['gateway' => SMS_module::REORDER_REMINDER_CAMPAIGN_KEY]);

        return app(SMSModuleController::class)->smsUpdate($request, SMS_module::REORDER_REMINDER_CAMPAIGN_KEY);
    }

    public function sendTest(Request $request): RedirectResponse
    {
        $redirect = redirect()->route('admin.marketing.reorder-reminders.index');

        try {
            $validated = $request->validate([
                'test_phone' => ['required', 'string', 'min:9', 'max:32'],
            ]);

            $phone = (string) $validated['test_phone'];
            $result = ReorderReminderService::sendTestSms($phone);

            if ($result['ok']) {
                Toastr::success($result['message']);

                return $redirect;
            }

            Toastr::error($result['message']);

            return $redirect->withInput();
        } catch (ValidationException $e) {
            Log::warning('reorder_reminder.test_validation_failed', [
                'errors' => $e->errors(),
            ]);

            Toastr::error($e->validator->errors()->first() ?: 'Validation failed');

            return $redirect->withErrors($e->validator)->withInput();
        } catch (Throwable $e) {
            Log::error('reorder_reminder.test_exception', [
                'error' => $e->getMessage(),
            ]);

            Toastr::error('Test SMS could not be sent. Please try again.');

            return $redirect->withInput();
        }
    }
}

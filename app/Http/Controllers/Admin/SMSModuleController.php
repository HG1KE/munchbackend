<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\CentralLogics\SMS_module;
use App\Http\Controllers\Controller;
use App\Model\BusinessSetting;
use App\Models\Setting;
use App\Support\SmsGatewayKeys;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\DB;


class SMSModuleController extends Controller
{
    /**
     * @return Application|Factory|View
     */
    public function smsIndex(): View|Factory|Application
    {
        $publishedStatus = 0; // Set a default value
        $paymentPublishedStatus = config('get_payment_publish_status');
        if (isset($paymentPublishedStatus[0]['is_published'])) {
            $publishedStatus = $paymentPublishedStatus[0]['is_published'];
        }

        $routes = config('addon_admin_routes');
        $desiredName = 'sms_setup';
        $paymentUrl = '';

        foreach ($routes as $routeArray) {
            foreach ($routeArray as $route) {
                if ($route['name'] === $desiredName) {
                    $paymentUrl = $route['url'];
                    break 2;
                }
            }
        }
        $dataValues = Setting::where('settings_type', 'sms_config')->whereIn('key_name', [
            'twilio', 'nexmo', '2factor', 'msg91', 'signal_wire', 'alphanet_sms',
        ])->get() ?? collect();

        $textSmsGateways = [
            SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY => $this->textSmsGatewayValues(
                SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY,
                SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT
            ),
            SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY => $this->textSmsGatewayValues(
                SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY,
                SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT
            ),
        ];

        return view('admin-views.business-settings.sms-index', compact(
            'publishedStatus',
            'paymentUrl',
            'dataValues',
            'textSmsGateways'
        ));
    }

    /**
     * @param Request $request
     * @param $module
     * @return RedirectResponse
     */
    public function smsUpdate(Request $request, $module): RedirectResponse
    {
        $validation = [
            'gateway' => 'required|in:twilio,nexmo,2factor,msg91,signal_wire,alphanet_sms,textsms_transactional,textsms_ke_promotional,textsms_ke_abandoned_cart,textsms_ke_reorder_reminder,textsms_ke_loyalty_delivery',
        ];

        $validationData = [];
        if ($module == 'twilio') {
            $validationData = [
                'status' => 'required|in:1,0',
                'sid' => 'required_if:status,1',
                'messaging_service_sid' => 'required_if:status,1',
                'token' => 'required_if:status,1',
                'from' => 'required_if:status,1',
                'otp_template' => 'required_if:status,1'
            ];
        } elseif ($module == 'nexmo') {
            $validationData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'api_secret' => 'required_if:status,1',
                'token' => 'required_if:status,1',
                'from' => 'required_if:status,1',
                'otp_template' => 'required_if:status,1'
            ];
        } elseif ($module == '2factor') {
            $validationData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'otp_template' => 'required_if:status,1'
            ];
        } elseif ($module == 'msg91') {
            $validationData = [
                'status' => 'required|in:1,0',
                'template_id' => 'required_if:status,1',
                'auth_key' => 'required_if:status,1',
            ];
        } elseif ($module == 'signal_wire') {
            $validationData = [
                'status' => 'required|in:1,0',
                'project_id' => 'required_if:status,1',
                'token' => 'required_if:status,1',
                'space_url' => 'required_if:status,1',
                'from' => 'required_if:status,1',
                'otp_template' => 'required_if:status,1',
            ];
        } elseif ($module == 'alphanet_sms') {
            $validationData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'otp_template' => 'required_if:status,1',
            ];
        } elseif ($module == 'textsms_transactional' || $module == 'textsms_ke_promotional') {
            $validationData = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'partner_id' => 'required_if:status,1',
                'sender_id' => 'required_if:status,1',
                'endpoint' => 'nullable|url',
                'http_timeout_seconds' => 'nullable|integer|min:5|max:120',
            ];
        } elseif ($module == 'textsms_ke_abandoned_cart') {
            $validationData = [
                'status' => 'required|in:1,0',
                'message_template' => 'required_if:status,1',
            ];
        } elseif ($module == 'textsms_ke_reorder_reminder') {
            $validationData = [
                'status' => 'required|in:1,0',
                'message_template' => 'required_if:status,1',
            ];
        } elseif ($module == 'textsms_ke_loyalty_delivery') {
            $validationData = [
                'status' => 'required|in:1,0',
                'message_template' => 'required_if:status,1',
            ];
        }

        $validation = $request->validate(array_merge($validation, $validationData));

        $additionalData = [];
        if ($module == 'twilio') {
            $additionalData = [
                'status' => $request['status'],
                'sid' => $request['sid'],
                'messaging_service_sid' => $request['messaging_service_sid'],
                'token' => $request['token'],
                'from' => $request['from'],
                'otp_template' => $request['otp_template'],
            ];

        } elseif ($module == 'nexmo') {
            $additionalData = [
                'status' =>$request['status'],
                'api_key' => $request['api_key'],
                'api_secret' => $request['api_secret'],
                'token' => $request['token'],
                'from' => $request['from'],
                'otp_template' => $request['otp_template'],
            ];

        } elseif ($module == '2factor') {
            $additionalData = [
                'status' => $request['status'],
                'api_key' => $request['api_key'],
                'otp_template' => $request['otp_template'],
            ];
        } elseif ($module == 'msg91') {
            $additionalData = [
                'status' => $request['status'],
                'template_id' => $request['template_id'],
                'auth_key' => $request['auth_key'],
            ];
        } elseif ($module == 'signal_wire') {
            $additionalData = [
                'status' => $request['status'],
                'project_id' => $request['project_id'],
                'token' => $request['token'],
                'space_url' => $request['space_url'],
                'from' => $request['from'],
                'otp_template' => $request['otp_template'],
            ];
        }elseif ($module == 'alphanet_sms') {
            $additionalData = [
                'status' => $request['status'],
                'api_key' => $request['api_key'],
                'otp_template' => $request['otp_template'],
            ];
        } elseif ($module == 'textsms_transactional' || $module == 'textsms_ke_promotional') {
            $defaultEndpoint = SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT;
            $endpoint = trim((string) $request->input('endpoint', ''));
            $additionalData = [
                'status' => $request['status'],
                'api_key' => $request['api_key'],
                'partner_id' => $request['partner_id'],
                'sender_id' => $request['sender_id'],
                'endpoint' => $endpoint !== '' ? $endpoint : $defaultEndpoint,
                'http_timeout_seconds' => (string) $request->input('http_timeout_seconds', '30'),
                'is_otp_gateway' => 0,
            ];
        } elseif ($module == 'textsms_ke_abandoned_cart') {
            $additionalData = [
                'status' => $request['status'],
                'message_template' => $request['message_template'],
                'delay_minutes' => $request->input('delay_minutes', '30'),
                'max_attempts' => $request->input('max_attempts', '1'),
                'cooldown_hours' => $request->input('cooldown_hours', '24'),
                'quiet_hours_start' => $request->input('quiet_hours_start', '21:00'),
                'quiet_hours_end' => $request->input('quiet_hours_end', '08:00'),
                'recovery_url' => $request->input('recovery_url', ''),
                'is_otp_gateway' => 0,
            ];
        } elseif ($module == 'textsms_ke_reorder_reminder') {
            $additionalData = [
                'status' => $request['status'],
                'message_template' => $request->message_template,
                'delay_days' => $request->input('delay_days', '14'),
                'minimum_completed_orders' => $request->input('minimum_completed_orders', '2'),
                'max_attempts' => $request->input('max_attempts', '1'),
                'cooldown_days' => $request->input('cooldown_days', '30'),
                'quiet_hours_start' => $request->input('quiet_hours_start', '21:00'),
                'quiet_hours_end' => $request->input('quiet_hours_end', '08:00'),
                'recovery_url' => $request->input('recovery_url', ''),
                'branch_ids' => $request->input('branch_ids', ''),
                'test_phone' => $request->input('test_phone', ''),
                'is_otp_gateway' => 0,
            ];
        } elseif ($module == 'textsms_ke_loyalty_delivery') {
            $additionalData = [
                'status' => $request['status'],
                'message_template' => $request->message_template,
                'test_phone' => $request->input('test_phone', ''),
                'is_otp_gateway' => 0,
            ];
        }

        $data= [
            'gateway' => $module ,
            'mode' =>  isset($request['status']) == 1  ?  'live': 'test'
        ];

        $credentials= json_encode(array_merge($data, $additionalData));

        $row = [
            'key_name' => $module,
            'live_values' => $credentials,
            'test_values' => $credentials,
            'settings_type' => 'sms_config',
            'mode' => isset($request['status']) == 1  ?  'live': 'test',
            'is_active' => isset($request['status']) == 1  ?  1: 0 ,
        ];
        $exists = DB::table('addon_settings')
            ->where('key_name', $module)
            ->where('settings_type', 'sms_config')
            ->exists();
        if (! $exists) {
            $row['id'] = (string) \Illuminate\Support\Str::uuid();
        }

        DB::table('addon_settings')->updateOrInsert(['key_name' => $module, 'settings_type' => 'sms_config'], $row);

        $SMSGatewayArray = [
            'twilio','nexmo','2factor','msg91', 'signal_wire'
        ];

        $nonOtpModules = [
            'textsms_transactional',
            'textsms_ke_promotional',
            'textsms_ke_abandoned_cart',
            'textsms_ke_reorder_reminder',
            'textsms_ke_loyalty_delivery',
        ];

        if ($request['status'] == 1) {
            if (! in_array($module, $nonOtpModules, true)) {
                foreach ($SMSGatewayArray as $gateway) {
                    if ($module != $gateway) {
                        $keep = Setting::where(['key_name' => $gateway, 'settings_type' => 'sms_config'])->first();
                        if (isset($keep)) {
                            $hold = $keep->live_values;
                            $hold['status'] = 0;
                            Setting::where(['key_name' => $gateway, 'settings_type' => 'sms_config'])->update([
                                'live_values' => $hold,
                                'test_values' => $hold,
                                'is_active' => 0,
                            ]);
                        }
                    }
                }

                $firebaseOTP = Helpers::get_business_settings('firebase_otp_verification');

                DB::table('business_settings')->updateOrInsert(['key' => 'firebase_otp_verification'], [
                    'value' => json_encode([
                        'status'  => 0,
                        'web_api_key' => $firebaseOTP['web_api_key'],
                    ]),
                ]);
            }
        }
        return back();
    }

    public function smsTest(Request $request, $module): RedirectResponse
    {
        $request->validate([
            'test_phone' => 'required|string|min:9|max:32',
        ]);

        if (! in_array($module, [
            SMS_module::TRANSACTIONAL_SMS_GATEWAY_KEY,
            SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY,
        ], true)) {
            Toastr::error(translate('Invalid SMS gateway'));

            return back();
        }

        $label = $module === SMS_module::PROMOTIONAL_SMS_GATEWAY_KEY
            ? 'TextSMS Promotional'
            : 'TextSMS Transactional';
        $result = SMS_module::sendGatewayTestSms(
            $module,
            (string) $request->input('test_phone'),
            'Munch test SMS from '.$label.'.'
        );

        if ($result === 'success') {
            Toastr::success(translate('Test SMS sent successfully'));
        } else {
            Toastr::error(translate('Test SMS could not be sent. Check credentials, endpoint, and that the gateway is configured.'));
        }

        return back()->withInput();
    }

    /**
     * @return array<string, mixed>
     */
    private function textSmsGatewayValues(string $key, string $defaultEndpoint): array
    {
        $row = Setting::query()
            ->where('key_name', $key)
            ->where('settings_type', 'sms_config')
            ->first();

        $values = is_array($row?->live_values) ? $row->live_values : [];

        return [
            'status' => (int) ($values['status'] ?? 0),
            'api_key' => (string) ($values['api_key'] ?? ''),
            'partner_id' => (string) ($values['partner_id'] ?? ''),
            'sender_id' => (string) ($values['sender_id'] ?? ''),
            'endpoint' => (string) ($values['endpoint'] ?? $defaultEndpoint),
            'http_timeout_seconds' => (string) ($values['http_timeout_seconds'] ?? '30'),
        ];
    }
}

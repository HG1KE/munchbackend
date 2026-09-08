<?php

namespace App\CentralLogics;

use App\Model\BusinessSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SMS_module
{
    /** Official JSON POST endpoint for standard SMS (branch alerts, customer templates). Not the OTP endpoint. */
    private const TEXTSMS_KE_SENDSMS_URL = 'https://sms.textsms.co.ke/api/services/sendsms/';

    /** Global marketing / promotional TextSMS credentials (shared by abandoned checkout and future campaigns). */
    public const PROMOTIONAL_SMS_GATEWAY_KEY = 'textsms_ke_promotional';

    /** Abandoned-checkout campaign settings only (template, delays, etc.). */
    public const ABANDONED_CHECKOUT_CAMPAIGN_KEY = 'textsms_ke_abandoned_cart';

    /** Reorder-reminder campaign settings (repeat customers). */
    public const REORDER_REMINDER_CAMPAIGN_KEY = 'textsms_ke_reorder_reminder';

    /** Loyalty points credited on delivered order (template + toggle; sends via transactional gateway). */
    public const LOYALTY_DELIVERY_CAMPAIGN_KEY = 'textsms_ke_loyalty_delivery';

    /** Transactional TextSMS for customer order status / loyalty delivery (not promotional). */
    public const TRANSACTIONAL_CUSTOMER_CONFIRM_KEY = 'textsms_ke_customer_confirm';

    public static function send($receiver, $otp)
    {
        $config = self::get_settings('twilio');
        if (isset($config) && $config['status'] == 1) {
            return self::twilio($receiver, $otp);
        }

        $config = self::get_settings('nexmo');
        if (isset($config) && $config['status'] == 1) {
            return self::nexmo($receiver, $otp);
        }

        $config = self::get_settings('2factor');
        if (isset($config) && $config['status'] == 1) {
            return self::two_factor($receiver, $otp);
        }

        $config = self::get_settings('msg91');
        if (isset($config) && $config['status'] == 1) {
            return self::msg_91($receiver, $otp);
        }

        $config = self::get_settings('signal_wire');
        if (isset($config) && $config['status'] == 1) {
            return self::signal_wire($receiver, $otp);
        }

        $config = self::get_settings('alphanet_sms');
        if (isset($config) && $config['status'] == 1) {
            return self::alphanet_sms($receiver, $otp);
        }

        $config = self::get_settings('textsms_ke');
        if (isset($config) && $config['status'] == 1) {
            return self::textsms_ke($receiver, $otp);
        }

        return 'not_found';
    }

    /**
     * OTP send used by auth controllers. Prefers the CodeCanyon Gateways
     * module when it is published and present; otherwise uses this class.
     */
    public static function send_otp($receiver, $otp)
    {
        $publishedStatus = 0;
        $paymentPublishedStatus = config('get_payment_publish_status');
        if (isset($paymentPublishedStatus[0]['is_published'])) {
            $publishedStatus = (int) $paymentPublishedStatus[0]['is_published'];
        }

        if ($publishedStatus === 1 && class_exists(\Modules\Gateways\Traits\SmsGateway::class)) {
            return \Modules\Gateways\Traits\SmsGateway::send($receiver, $otp);
        }

        return self::send($receiver, $otp);
    }

    public static function twilio($receiver, $otp)
    {
        $config = self::get_settings('twilio');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {
            $message = str_replace("#OTP#", $otp, $config['otp_template']);
            $sid = $config['sid'];
            $token = $config['token'];
            try {
                $twilio = new Client($sid, $token);
                $twilio->messages
                    ->create($receiver, // to
                        array(
                            "messagingServiceSid" => $config['messaging_service_sid'],
                            "body" => $message
                        )
                    );
                $response = 'success';
            } catch (\Exception $exception) {
                $response = 'error';
            }
        }
        return $response;
    }

    public static function nexmo($receiver, $otp)
    {
        $config = self::get_settings('nexmo');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {
            $message = str_replace("#OTP#", $otp, $config['otp_template']);
            try {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, 'https://rest.nexmo.com/sms/json');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, "from=".$config['from']."&text=".$message."&to=".$receiver."&api_key=".$config['api_key']."&api_secret=".$config['api_secret']);

                $headers = array();
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $result = curl_exec($ch);
                if (curl_errno($ch)) {
                    echo 'Error:' . curl_error($ch);
                }
                curl_close($ch);
                $response = 'success';
            } catch (\Exception $exception) {
                $response = 'error';
            }
        }
        return $response;
    }

    public static function two_factor($receiver, $otp)
    {

        $config = self::get_settings('2factor');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {
            $apiKey = $config['api_key'];
            $otpTemplate = $config['otp_template'] ?? '';
            $apiUrl = "https://2factor.in/API/V1/$apiKey/SMS/$receiver/$otp/$otpTemplate";

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
            ));
            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if (!$err) {
                $response = 'success';
            } else {
                $response = 'error';
            }
        }
        return $response;
    }

    public static function msg_91($receiver, $otp)
    {
        $config = self::get_settings('msg91');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {
            $receiver = str_replace("+", "", $receiver);
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.msg91.com/api/v5/otp?template_id=" . $config['template_id'] . "&mobile=" . $receiver . "&authkey=" . $config['auth_key'] . "",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_POSTFIELDS => "{\"OTP\":\"$otp\"}",
                CURLOPT_HTTPHEADER => array(
                    "content-type: application/json"
                ),
            ));
            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);
            if (!$err) {
                $response = 'success';
            } else {
                $response = 'error';
            }
        }
        return $response;
    }

    public static function signal_wire($receiver, $otp)
    {
        $config = self::get_settings('signal_wire');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {

            $message = str_replace("#OTP#", $otp, "Your otp is #OTP#.");

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://" . $config['space_url'] . "/api/laml/2010-04-01/Accounts/" . $config['project_id'] . "/Messages");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $config['project_id'] . ':' . $config['token']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "From=" . $config['from'] . "&To=" . $receiver . "&Body=" . $message);

            $response = curl_exec($ch);
            $error = curl_error($ch);

            curl_close($ch);

            if (!$error) {
                $response = 'success';
            } else {
                $response = 'error';
            }

        }
        return $response;
    }

    public static function alphanet_sms($receiver, $otp): string
    {
        $config = self::get_settings('alphanet_sms');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {
            $receiver = str_replace("+", "", $receiver);
            $message = str_replace("#OTP#", $otp, $config['otp_template']);
            $api_key = $config['api_key'];

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://api.sms.net.bd/sendsms',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => array('api_key' => $api_key, 'msg' => $message, 'to' => $receiver),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if (!$err) {
                $response = 'success';
            } else {
                $response = 'error';
            }
        }
        return $response;
    }

    public static function textsms_ke($receiver, $otp)
    {
        $config = self::get_settings('textsms_ke');
        $response = 'error';
        if (isset($config) && $config['status'] == 1) {
            // Format the receiver number if needed (ensure it starts with country code)
            if (substr($receiver, 0, 1) === '+') {
                $receiver = substr($receiver, 1); // Remove the + sign
            }

            // If the number doesn't start with country code, add it (assuming Kenya's code is 254)
            if (substr($receiver, 0, 3) !== '254') {
                // If it starts with 0, replace it with 254
                if (substr($receiver, 0, 1) === '0') {
                    $receiver = '254' . substr($receiver, 1);
                } else {
                    $receiver = '254' . $receiver;
                }
            }

            $message = str_replace("#OTP#", $otp, $config['otp_template']);
            $api_key = $config['api_key'];
            $partner_id = $config['partner_id'];
            $shortcode = $config['sender_id'];

            $postData = [
                'apikey' => $api_key,
                'partnerID' => $partner_id,
                'message' => $message,
                'shortcode' => $shortcode,
                'mobile' => $receiver
            ];

            try {
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => 'https://sms.textsms.co.ke/api/services/sendotp/',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode($postData),
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json'
                    ],
                ]);

                $response_data = curl_exec($curl);
                $err = curl_error($curl);
                curl_close($curl);

                if (!$err) {
                    $json_response = json_decode($response_data, true);

                    // Check if the response contains a success code
                    if (isset($json_response['responses']) &&
                        is_array($json_response['responses']) &&
                        isset($json_response['responses'][0]['respose-code']) &&
                        $json_response['responses'][0]['respose-code'] == 200) {
                        $response = 'success';
                    } else {
                        $response = 'error';
                    }
                } else {
                    $response = 'error';
                }
            } catch (\Exception $exception) {
                $response = 'error';
            }
        }
        return $response;
    }

    /**
     * Normalize MSISDN for Kenya TextSMS payloads (same rules as OTP / branch notifications).
     */
    private static function textsms_ke_format_receiver(string $receiver): string
    {
        if (str_starts_with($receiver, '+')) {
            $receiver = substr($receiver, 1);
        }
        if (substr($receiver, 0, 3) !== '254') {
            if (str_starts_with($receiver, '0')) {
                $receiver = '254' . substr($receiver, 1);
            } else {
                $receiver = '254' . $receiver;
            }
        }

        return $receiver;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function textsms_ke_redact_payload_for_log(array $payload): array
    {
        $out = $payload;
        if (isset($out['apikey'])) {
            $k = (string) $out['apikey'];
            $out['apikey'] = strlen($k) > 10 ? substr($k, 0, 4) . '…' . substr($k, -4) : '(set)';
        }

        return $out;
    }

    /**
     * Provider uses typo key "respose-code" in docs; tolerate "response-code" if corrected.
     *
     * @param array<string,mixed>|null $row
     */
    private static function textsms_ke_row_response_code(?array $row): ?int
    {
        if ($row === null) {
            return null;
        }
        if (isset($row['respose-code'])) {
            return (int) $row['respose-code'];
        }
        if (isset($row['response-code'])) {
            return (int) $row['response-code'];
        }

        return null;
    }

    /**
     * Shared TextSMS transport for non-OTP messages (branch notification + customer confirmed).
     * Uses official sendsms endpoint — sendotp is for OTP-only flows; wrong endpoint yields HTML/non-JSON and null response_code.
     *
     * @param array<string,mixed>|null $config Decoded live_values for the gateway
     *
     * @see https://textsms.co.ke/bulk-sms-api/
     */
    private static function textsms_ke_send_general_message(?array $config, string $receiver, string $message, ?string $logLabel = null): string
    {
        $response = 'error';
        if (!isset($config) || ($config['status'] ?? 0) != 1) {
            if ($logLabel !== null) {
                Log::info('textsms.general.skipped_inactive', [
                    'gateway' => $logLabel,
                    'has_config' => $config !== null,
                    'status' => $config['status'] ?? null,
                ]);
            }

            return $response;
        }

        $receiver = self::textsms_ke_format_receiver($receiver);

        $api_key = $config['api_key'] ?? '';
        $partner_id = $config['partner_id'] ?? '';
        $shortcode = $config['sender_id'] ?? '';
        if ($api_key === '' || $partner_id === '' || $shortcode === '') {
            if ($logLabel !== null) {
                Log::warning('textsms.general.missing_credentials', ['gateway' => $logLabel]);
            }

            return 'error';
        }

        $postData = [
            'apikey' => $api_key,
            'partnerID' => $partner_id,
            'message' => $message,
            'shortcode' => $shortcode,
            'mobile' => $receiver,
            'pass_type' => 'plain',
        ];

        $payloadJson = json_encode($postData);
        $requestUrl = self::TEXTSMS_KE_SENDSMS_URL;

        if ($logLabel !== null) {
            Log::info('textsms.general.http_attempt', [
                'gateway' => $logLabel,
                'endpoint' => $requestUrl,
                'receiver_suffix' => strlen($receiver) >= 4 ? substr($receiver, -4) : '****',
                'message_length' => strlen($message),
                'payload_redacted' => self::textsms_ke_redact_payload_for_log($postData),
                'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
        }

        try {
            $curlTimeout = max(5, (int) ($config['http_timeout_seconds'] ?? 30));
            $curlConnect = min(30, max(3, (int) round($curlTimeout / 2)));

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $requestUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => $curlTimeout,
                CURLOPT_CONNECTTIMEOUT => $curlConnect,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            $response_data = curl_exec($curl);
            $curl_errno = curl_errno($curl);
            $curl_errstr = curl_error($curl);
            $curl_info = curl_getinfo($curl);
            curl_close($curl);

            $httpCode = $curl_info['http_code'] ?? null;
            $finalUrl = $curl_info['url'] ?? $requestUrl;

            $json_response = is_string($response_data) ? json_decode($response_data, true) : null;
            $jsonErr = json_last_error();

            $firstRow = (is_array($json_response) && isset($json_response['responses'][0]) && is_array($json_response['responses'][0]))
                ? $json_response['responses'][0]
                : null;
            $providerCode = self::textsms_ke_row_response_code($firstRow);
            $providerDesc = is_array($firstRow) ? ($firstRow['response-description'] ?? $firstRow['response_description'] ?? null) : null;

            if ($curl_errno !== 0) {
                $response = 'error';
                if ($logLabel !== null) {
                    Log::warning('textsms.general.transport', [
                        'gateway' => $logLabel,
                        'stage' => 'curl_failed',
                        'curl_errno' => $curl_errno,
                        'curl_error' => $curl_errstr,
                        'curl_getinfo' => [
                            'http_code' => $httpCode,
                            'primary_ip' => $curl_info['primary_ip'] ?? null,
                            'total_time' => $curl_info['total_time'] ?? null,
                            'ssl_verify_result' => $curl_info['ssl_verify_result'] ?? null,
                        ],
                        'effective_url' => $finalUrl,
                        'raw_body_preview' => is_string($response_data) ? substr($response_data, 0, 2000) : null,
                    ]);
                }

                return $response;
            }

            if ($jsonErr !== JSON_ERROR_NONE || ! is_array($json_response)) {
                $response = 'error';
                if ($logLabel !== null) {
                    Log::warning('textsms.general.transport', [
                        'gateway' => $logLabel,
                        'stage' => 'invalid_json_or_non_json_body',
                        'json_last_error' => $jsonErr,
                        'json_last_error_msg' => json_last_error_msg(),
                        'http_code' => $httpCode,
                        'effective_url' => $finalUrl,
                        'raw_body_preview' => is_string($response_data) ? substr($response_data, 0, 4000) : null,
                        'curl_getinfo' => [
                            'content_type' => $curl_info['content_type'] ?? null,
                            'ssl_verify_result' => $curl_info['ssl_verify_result'] ?? null,
                        ],
                    ]);
                }

                return $response;
            }

            if ($providerCode === 200) {
                $response = 'success';
            } else {
                $response = 'error';
            }

            if ($logLabel !== null) {
                Log::info('textsms.general.transport', [
                    'gateway' => $logLabel,
                    'stage' => 'parsed',
                    'effective_url' => $finalUrl,
                    'http_code' => $httpCode,
                    'curl_errno' => $curl_errno,
                    'curl_error' => $curl_errstr ?: null,
                    'provider_result' => $response,
                    'response_code' => $providerCode,
                    'response_description' => $providerDesc,
                    'first_response_row' => $firstRow,
                    'curl_getinfo' => [
                        'primary_ip' => $curl_info['primary_ip'] ?? null,
                        'total_time' => $curl_info['total_time'] ?? null,
                        'ssl_verify_result' => $curl_info['ssl_verify_result'] ?? null,
                        'content_type' => $curl_info['content_type'] ?? null,
                    ],
                    'raw_body_preview' => is_string($response_data) ? substr($response_data, 0, 2000) : null,
                ]);
            }
        } catch (\Exception $exception) {
            $response = 'error';
            if ($logLabel !== null) {
                Log::warning('textsms.general.exception', ['gateway' => $logLabel, 'error' => $exception->getMessage()]);
            }
        }

        return $response;
    }

    /**
     * Public helper for admin / previews — same placeholder rules as TextSMS notification templates.
     *
     * @param  array<string, string|int|float>  $vars
     */
    public static function renderMarketingNotificationTemplate(string $template, array $vars): string
    {
        return self::textsms_ke_replace_notification_placeholders($template, array_map('strval', $vars));
    }

    /**
     * Apply placeholders for TextSMS notification templates (#HASH# and {brace} styles).
     *
     * @param array<string,string> $vars
     */
    private static function textsms_ke_replace_notification_placeholders(string $template, array $vars): string
    {
        $message = $template;
        foreach ($vars as $key => $value) {
            $message = str_replace('#' . strtoupper($key) . '#', $value, $message);
            $message = str_replace('{' . $key . '}', $value, $message);
        }

        return $message;
    }

    /**
     * Send notification SMS using textsms_ke_not gateway
     * This function is specifically for branch order notifications, not for OTP
     * 
     * @param string $receiver The phone number to send the SMS to
     * @param array $data The notification data containing order details
     * @return string 'success' or 'error'
     */
    public static function textsms_ke_not($receiver, $data)
    {
        $config = self::get_settings('textsms_ke_not');

        $title = $data['title'] ?? '';
        $description = $data['description'] ?? '';
        $customer_name = $data['customer_name'] ?? '';
        $order_amount = $data['order_amount'] ?? '';

        $vars = [
            'order_id' => (string)($data['order_id'] ?? ''),
            'title' => $title,
            'description' => $description,
            'customer_name' => $customer_name,
            'order_amount' => $order_amount,
        ];

        $message = isset($config['notification_template'])
            ? self::textsms_ke_replace_notification_placeholders($config['notification_template'], $vars)
            : '';

        return self::textsms_ke_send_general_message($config, $receiver, $message, 'textsms_ke_not');
    }

    /**
     * Customer SMS via textsms_ke_customer_confirm — single gateway, multiple template keys in live_values.
     *
     * @param array<string,string|int> $data order_id, title, description, customer_name, order_amount, branch_name, branch_phone, order_status
     * @param string                     $templateField Config keys: order_placed_template, processing_template; falls back to legacy notification_template for order_placed_template only
     */
    public static function textsms_ke_customer_status_sms(string $receiver, array $data, string $templateField): string
    {
        $config = self::get_settings('textsms_ke_customer_confirm');

        $vars = [
            'order_id' => (string) ($data['order_id'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'customer_name' => (string) ($data['customer_name'] ?? ''),
            'order_amount' => (string) ($data['order_amount'] ?? ''),
            'branch_name' => (string) ($data['branch_name'] ?? ''),
            'branch_phone' => (string) ($data['branch_phone'] ?? ''),
            'order_status' => (string) ($data['order_status'] ?? ''),
        ];

        $template = '';
        if (is_array($config) && isset($config[$templateField]) && $config[$templateField] !== '') {
            $template = (string) $config[$templateField];
        } elseif ($templateField === 'order_placed_template' && is_array($config) && ! empty($config['notification_template'])) {
            $template = (string) $config['notification_template'];
        }

        $message = $template !== ''
            ? self::textsms_ke_replace_notification_placeholders($template, $vars)
            : '';

        return self::textsms_ke_send_general_message($config, $receiver, $message, 'textsms_ke_customer_confirm');
    }


    /**
     * Abandoned-checkout campaign settings merged with global promotional SMS credentials.
     * Backward compatible when credentials still exist on the campaign row (pre-split).
     *
     * @return array<string,mixed>|null
     */
    public static function getAbandonedCartRuntimeConfig(): ?array
    {
        $campaign = self::get_settings(self::ABANDONED_CHECKOUT_CAMPAIGN_KEY);
        if (! is_array($campaign)) {
            return null;
        }

        $promo = self::get_settings(self::PROMOTIONAL_SMS_GATEWAY_KEY);

        $merged = $campaign;
        if (is_array($promo)) {
            foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
                if (isset($promo[$k]) && (string) $promo[$k] !== '') {
                    $merged[$k] = $promo[$k];
                }
            }
            if (isset($promo['http_timeout_seconds']) && (string) $promo['http_timeout_seconds'] !== '') {
                $merged['http_timeout_seconds'] = $promo['http_timeout_seconds'];
            }
        }

        $campaignOn = (int) ($campaign['status'] ?? 0) === 1;
        $promoOn = is_array($promo) && (int) ($promo['status'] ?? 0) === 1;
        $credFromPromo = is_array($promo) && self::hasCompleteTextSmsCredentials($promo);
        $credFromCampaign = self::hasCompleteTextSmsCredentials($campaign);

        if ($credFromPromo) {
            $merged['status'] = ($campaignOn && $promoOn) ? 1 : 0;
        } else {
            $merged['status'] = ($campaignOn && $credFromCampaign) ? 1 : 0;
        }

        return $merged;
    }

    /**
     * @param  array<string,mixed>|null  $cfg
     */
    private static function hasCompleteTextSmsCredentials(?array $cfg): bool
    {
        if (! is_array($cfg)) {
            return false;
        }
        foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
            if (! isset($cfg[$k]) || (string) $cfg[$k] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Abandoned-checkout recovery SMS via TextSMS promotional sender ID.
     * Uses merged campaign + {@see self::PROMOTIONAL_SMS_GATEWAY_KEY} credentials.
     *
     * @param  array<string,string|int>  $vars  customer_name, branch_name, item_count, order_amount, currency, recovery_url
     */
    public static function textsms_ke_abandoned_cart(string $receiver, array $vars): string
    {
        $merged = self::getAbandonedCartRuntimeConfig();

        $template = '';
        if (is_array($merged) && isset($merged['message_template']) && $merged['message_template'] !== '') {
            $template = (string) $merged['message_template'];
        }

        $message = $template !== ''
            ? self::renderMarketingNotificationTemplate($template, $vars)
            : '';

        return self::textsms_ke_send_general_message($merged, $receiver, $message, 'textsms_ke_abandoned_cart');
    }

    /**
     * Reorder-reminder campaign settings merged with global promotional SMS credentials.
     *
     * @return array<string,mixed>|null
     */
    public static function getReorderReminderRuntimeConfig(): ?array
    {
        $campaign = self::get_settings(self::REORDER_REMINDER_CAMPAIGN_KEY);
        if (! is_array($campaign)) {
            return null;
        }

        $promo = self::get_settings(self::PROMOTIONAL_SMS_GATEWAY_KEY);

        $merged = $campaign;
        if (is_array($promo)) {
            foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
                if (isset($promo[$k]) && (string) $promo[$k] !== '') {
                    $merged[$k] = $promo[$k];
                }
            }
            if (isset($promo['http_timeout_seconds']) && (string) $promo['http_timeout_seconds'] !== '') {
                $merged['http_timeout_seconds'] = $promo['http_timeout_seconds'];
            }
        }

        $campaignOn = (int) ($campaign['status'] ?? 0) === 1;
        $promoOn = is_array($promo) && (int) ($promo['status'] ?? 0) === 1;
        $credFromPromo = is_array($promo) && self::hasCompleteTextSmsCredentials($promo);
        $credFromCampaign = self::hasCompleteTextSmsCredentials($campaign);

        if ($credFromPromo) {
            $merged['status'] = ($campaignOn && $promoOn) ? 1 : 0;
        } else {
            $merged['status'] = ($campaignOn && $credFromCampaign) ? 1 : 0;
        }

        return $merged;
    }

    /**
     * Reorder reminder SMS via TextSMS promotional sender ID.
     *
     * @param  array<string,string>  $vars
     */
    public static function textsms_ke_reorder_reminder(string $receiver, array $vars): string
    {
        $merged = self::getReorderReminderRuntimeConfig();

        $template = '';
        if (is_array($merged) && isset($merged['message_template']) && $merged['message_template'] !== '') {
            $template = (string) $merged['message_template'];
        }

        $message = $template !== ''
            ? self::renderMarketingNotificationTemplate($template, $vars)
            : '';

        return self::textsms_ke_send_general_message($merged, $receiver, $message, 'textsms_ke_reorder_reminder');
    }

    /**
     * Runtime config for admin test sends: campaign template + promotional credentials.
     * Enables transport when promotional credentials are complete (campaign may stay inactive).
     *
     * @return array<string,mixed>|null
     */
    public static function getReorderReminderTestRuntimeConfig(): ?array
    {
        $campaign = self::get_settings(self::REORDER_REMINDER_CAMPAIGN_KEY);
        $promo = self::get_settings(self::PROMOTIONAL_SMS_GATEWAY_KEY);

        if (! is_array($campaign) && ! is_array($promo)) {
            return null;
        }

        $merged = is_array($campaign) ? $campaign : [];
        if (is_array($promo)) {
            foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
                if (isset($promo[$k]) && (string) $promo[$k] !== '') {
                    $merged[$k] = $promo[$k];
                }
            }
            if (isset($promo['http_timeout_seconds']) && (string) $promo['http_timeout_seconds'] !== '') {
                $merged['http_timeout_seconds'] = $promo['http_timeout_seconds'];
            }
        }

        $merged['status'] = self::hasCompleteTextSmsCredentials($merged) ? 1 : 0;

        return $merged;
    }

    /**
     * Send a pre-rendered reorder reminder test message (admin test button).
     */
    public static function textsms_ke_send_reorder_reminder_test(string $receiver, string $message): string
    {
        $config = self::getReorderReminderTestRuntimeConfig();

        return self::textsms_ke_send_general_message($config, $receiver, $message, 'textsms_ke_reorder_reminder_test');
    }

    /**
     * Loyalty delivery: campaign template/toggle + transactional {@see self::TRANSACTIONAL_CUSTOMER_CONFIRM_KEY} credentials.
     *
     * @return array<string,mixed>|null
     */
    public static function getLoyaltyDeliveryRuntimeConfig(): ?array
    {
        return self::mergeLoyaltyDeliveryWithTransactionalGateway(requireTransactionalActive: true);
    }

    /**
     * @param  array<string,string>  $vars
     */
    public static function textsms_ke_loyalty_delivery(string $receiver, array $vars): string
    {
        $merged = self::getLoyaltyDeliveryRuntimeConfig();
        $message = self::renderLoyaltyDeliveryMessage($merged, $vars);

        return self::textsms_ke_send_general_message($merged, $receiver, $message, 'textsms_ke_loyalty_delivery');
    }

    /**
     * @param  array<string,mixed>|null  $config
     * @param  array<string,string>  $vars
     */
    public static function renderLoyaltyDeliveryMessage(?array $config, array $vars): string
    {
        $template = is_array($config) ? trim((string) ($config['message_template'] ?? '')) : '';
        if ($template === '') {
            return '';
        }

        return self::textsms_ke_replace_notification_placeholders($template, array_map('strval', $vars));
    }

    /**
     * Test sends: loyalty template + transactional credentials (campaign may be inactive).
     *
     * @return array<string,mixed>|null
     */
    public static function getLoyaltyDeliveryTestRuntimeConfig(): ?array
    {
        return self::mergeLoyaltyDeliveryWithTransactionalGateway(requireTransactionalActive: false);
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function mergeLoyaltyDeliveryWithTransactionalGateway(bool $requireTransactionalActive): ?array
    {
        $campaign = self::get_settings(self::LOYALTY_DELIVERY_CAMPAIGN_KEY);
        if (! is_array($campaign)) {
            return null;
        }

        $transactional = self::get_settings(self::TRANSACTIONAL_CUSTOMER_CONFIRM_KEY);

        $merged = $campaign;
        if (is_array($transactional)) {
            foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
                if (isset($transactional[$k]) && (string) $transactional[$k] !== '') {
                    $merged[$k] = $transactional[$k];
                }
            }
            if (isset($transactional['http_timeout_seconds']) && (string) $transactional['http_timeout_seconds'] !== '') {
                $merged['http_timeout_seconds'] = $transactional['http_timeout_seconds'];
            }
        }

        if ($requireTransactionalActive) {
            $campaignOn = (int) ($campaign['status'] ?? 0) === 1;
            $transactionalOn = is_array($transactional) && (int) ($transactional['status'] ?? 0) === 1;
            $credOk = is_array($transactional) && self::hasCompleteTextSmsCredentials($transactional);
            $merged['status'] = ($campaignOn && $transactionalOn && $credOk) ? 1 : 0;
        } else {
            $merged['status'] = self::hasCompleteTextSmsCredentials($merged) ? 1 : 0;
        }

        return $merged;
    }

    /**
     * Diagnostic snapshot for loyalty delivery SMS (why production sends may be blocked).
     *
     * @return array<string,mixed>
     */
    public static function describeLoyaltyDeliveryRuntimeConfig(): array
    {
        $campaign = self::get_settings(self::LOYALTY_DELIVERY_CAMPAIGN_KEY);
        $transactional = self::get_settings(self::TRANSACTIONAL_CUSTOMER_CONFIRM_KEY);
        $runtime = self::getLoyaltyDeliveryRuntimeConfig();

        $campaignOn = is_array($campaign) && (int) ($campaign['status'] ?? 0) === 1;
        $transactionalOn = is_array($transactional) && (int) ($transactional['status'] ?? 0) === 1;
        $credOk = is_array($transactional) && self::hasCompleteTextSmsCredentials($transactional);

        $inactiveReason = 'ok';
        if (! $campaignOn) {
            $inactiveReason = 'loyalty_campaign_inactive';
        } elseif (! $transactionalOn) {
            $inactiveReason = 'transactional_gateway_inactive';
        } elseif (! $credOk) {
            $inactiveReason = 'transactional_credentials_incomplete';
        }

        return [
            'loyalty_campaign_active' => $campaignOn,
            'transactional_gateway_active' => $transactionalOn,
            'transactional_credentials_ok' => $credOk,
            'runtime_send_enabled' => is_array($runtime) && (int) ($runtime['status'] ?? 0) === 1,
            'inactive_reason' => $inactiveReason,
        ];
    }

    public static function textsms_ke_send_loyalty_delivery_test(string $receiver, string $message): string
    {
        $config = self::getLoyaltyDeliveryTestRuntimeConfig();

        return self::textsms_ke_send_general_message($config, $receiver, $message, 'textsms_ke_loyalty_delivery_test');
    }


    public static function get_settings($name)
    {
        $config = DB::table('addon_settings')->where('key_name', $name)
            ->where('settings_type', 'sms_config')->first();

        if (isset($config) && !is_null($config->live_values)) {
            return json_decode($config->live_values, true);
        }
        return null;
    }
}

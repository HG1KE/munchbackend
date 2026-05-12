<?php

namespace App\CentralLogics;

use App\Model\BusinessSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Twilio\Rest\Client;

class SMS_module
{
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

            // Prepare the message using the notification template
            // Replace placeholders with actual data
            $message = $config['notification_template'];
            $message = str_replace("#ORDER_ID#", $data['order_id'], $message);
            $message = str_replace("#TITLE#", $data['title'], $message);
            $message = str_replace("#DESCRIPTION#", $data['description'], $message);
            
            // Add customer name and order amount if available
            if (isset($data['customer_name'])) {
                $message = str_replace("#CUSTOMER_NAME#", $data['customer_name'], $message);
            }
            
            if (isset($data['order_amount'])) {
                $message = str_replace("#ORDER_AMOUNT#", $data['order_amount'], $message);
            }
            
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

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use App\Traits\Processor;
use Illuminate\Support\Facades\Storage;

class PesapalController extends Controller
{
    use Processor;
    private PaymentRequest $payment;
    private $config_values;
 
    private $user;
    private $baseUrl;

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('pesapal', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
            $this->baseUrl = 'https://pay.pesapal.com/v3/api/'; // Live URL
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
            $this->baseUrl = 'https://cybqa.pesapal.com/pesapalv3/api/'; // Sandbox URL
        }

        $this->payment = $payment;
        $this->user = $user;
    }


        /**
     * Get or refresh the access token for Pesapal.
     *
     * @return string|null
     */
    private function getAccessToken()
    {
        // Check if token is cached and still valid
        if (Cache::has('pesapal_access_token_granted')) {
           
            return Cache::get('pesapal_access_token_granted');
        }
        $payload = json_encode([
            'consumer_key' => $this->config_values->consumer_key,
            'consumer_secret' => $this->config_values->consumer_secret,
        ]);
        
        // Request new token
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . 'Auth/RequestToken',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $res = json_decode($response);
        
        if ($res && isset($res->token)) {
            // Cache the token for 2 minutes (to allow time for request completion before expiry)
            Cache::put('pesapal_access_token_granted', $res->token, now()->addMinutes(2));
            return $res->token;
        }

        return null;
    }



    /**
     * Register a Pesapal webhook for transaction notifications.
     */
    public function registerWebhook($callbackUrl)
    {
        
        $data = [
            'url' => $callbackUrl,
            'ipn_notification_type' => 'GET',
        ];

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return response()->json(['error' => 'Could not authenticate with Pesapal'], 500);
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . 'URLSetup/RegisterIPN',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        curl_close($curl);
        
        $res = json_decode($response);
        
        if ($res && isset($res->ipn_id)) {
           
            return $res->ipn_id;
        }

        return null;
    }

    /**
     * Initialize a payment request with Pesapal and obtain notification_id.
     */
    public function initialize(Request $request)
    {
        
        
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $payer = json_decode($data['payer_information']);
        // Get the access token
        $accessToken = $this->getAccessToken();
       
        if (!$accessToken) {
            return response()->json(['error' => 'Payment could not be initialized'], 500);
        }

        $callbackUrl = route('pesapal.callback', ['payment_id' => $data->id]);
        $ipnURL = route('pesapal.ipn', ['payment_id' => $data->id]);   
        // Fetch or register IPN (webhook) for transaction notifications
        $ipnId = $this->registerWebhook($ipnURL);
         
        if(!$ipnId) {
            return response()->json(['error' => 'Payment could not be initialized'], 500);
        }
        $paymentData = [
            'amount' => $data->payment_amount,
            'currency' => $data->currency_code,
            'description' => 'Payment for Order #' . $data->id,
            'id' => uniqid(),
            'callback_url' => $callbackUrl,
            'notification_id' => $ipnId,
            'billing_address' => [
                'email_address' =>  $payer->email,
                'first_name' => $payer->name,
               
            ],
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . 'Transactions/SubmitOrderRequest',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($paymentData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $res = json_decode($response,true);
      
       
        if ($res && $res['status'] == 200) {
            return redirect()->away($res['redirect_url']);
        }

        return response()->json(['error' => 'Payment could not be initialized'], 500);
    }
    
     public function ipn(Request $request)
    {
        
        // Retrieve the OrderTrackingId and status from the request
        $OrderTrackingId = $request->input('OrderTrackingId');
        $paymentId = $request->input('payment_id');
    
        if (!$OrderTrackingId) {
            return response()->json(['error' => 'OrderTrackingId is missing'], 400);
        }
    
        // Authenticate and obtain an access token
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return response()->json(['error' => 'Could not authenticate with Pesapal'], 500);
        }
        
        // Call Pesapal API to get transaction status
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . 'Transactions/GetTransactionStatus?orderTrackingId=' . $OrderTrackingId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
    
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            return response()->json(['error' => 'Request to Pesapal failed: ' . curl_error($curl)], 500);
        }
        curl_close($curl);
    
        $res = json_decode($response);
    
        // Check if the response has a valid status
        if ($res && isset($res->status_code)) {
            // Handle 'COMPLETED' or 'FAILED' statuses based on status_code
            if ($res->status_code == 1) { // 1 = COMPLETED
                
                $payment_info = PaymentRequest::where('id', $paymentId)->first();
                $payment_info->payment_method = 'pesapal';
                $payment_info->is_paid = 1;
                $payment_info->transaction_id = $OrderTrackingId;
                $payment_info->save();
            }
        }
    }
        
    
        
    
    /**
     * Handle Pesapal callback with notification_id and payment status.
     */
    public function callback(Request $request)
    {
        // Retrieve the OrderTrackingId and status from the request
        $OrderTrackingId = $request->input('OrderTrackingId');
        $paymentId = $request->input('payment_id');
    
        if (!$OrderTrackingId) {
            return response()->json(['error' => 'OrderTrackingId is missing'], 400);
        }
    
        // Authenticate and obtain an access token
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return response()->json(['error' => 'Could not authenticate with Pesapal'], 500);
        }
    
        // Call Pesapal API to get transaction status
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . 'Transactions/GetTransactionStatus?orderTrackingId=' . $OrderTrackingId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
    
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            return response()->json(['error' => 'Request to Pesapal failed: ' . curl_error($curl)], 500);
        }
        curl_close($curl);
    
        $res = json_decode($response);
    
        // Check if the response has a valid status
        if ($res && isset($res->status_code)) {
             $payment_info = $this->payment::where('id', $paymentId)->first();   
            
            
            // Handle 'COMPLETED' or 'FAILED' statuses based on status_code
            if ($res->status_code == 1) { // 1 = COMPLETED
               
                $this->payment::where('id', $paymentId)->update([
                    //'payment_method' => 'pesapal',
                    'is_paid' => 1,
                    'transaction_id' => $OrderTrackingId,
                ]);
                
                
                if ($payment_info && function_exists($payment_info->success_hook)) {
                    call_user_func($payment_info->success_hook, $payment_info);
                }
                return $this->payment_response($payment_info, 'success');
                
                
            } else { // Handle FAILED and other statuses
                $paymentData = $this->payment::where('id', $paymentId)->first();
                if ($paymentData && function_exists($paymentData->failure_hook)) {
                    call_user_func($paymentData->failure_hook, $paymentData);
                }
                return $this->payment_response($payment_info, 'fail');
            }
        }
    
        return response()->json(['error' => 'Invalid response from Pesapal or missing status_code'], 500);
    }
    
}

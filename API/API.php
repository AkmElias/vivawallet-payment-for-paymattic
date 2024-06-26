<?php

namespace VivaWalletPaymentForPaymattic\API;

use VivaWalletPaymentForPaymattic\Settings\VivaWalletSettings;
use WPPayForm\App\Models\Submission;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;

class API
{
    public function init()
    {
        $this->verifyIPN();
    }
    public function verifyIPN()
    {
        if (!isset($_REQUEST['wpf_vivawallet_listener'])) {
            return;
        }

        // webhook endpoint varification call from vivawallet
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            $this->getWebhookVerificationKey();
        }

        // Set initial post data to empty string
        $post_data = '';

        $post_data = @file_get_contents('php://input');

        $body = json_decode($post_data);

        error_log(print_r($body, true));
        // commented out for now, will handle the IPN later
        $this->handleIpn($body);
        exit(200);
    }

    protected function handleIpn($data)
    {
        if (empty($data)) {
            return;
        }

        if(!$data->EventTypeId)  {
            return;
        }
        $eventId = intval($data->EventTypeId);

        // now only handles Transaction Payment Created event
        if($eventId == 1796) {
            do_action('wppayform/payment_success_vivawallet', $data->EventData);
        } else {
            do_action('wppayform/payment_failed_vivawallet', $data);
        }
        // implements the logic to handle the IPN if needed, now its not implemented
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET', $accessTokenReq = false, $accessToken = null)
    {
        if ($accessTokenReq) {
            return $this->getAccessToken($path, $formId, $args);
        }

        $keys = (new VivaWalletSettings())->getApiKeys($formId);

        $endPoint = 'https://demo-api.vivapayments.com/';
       
        if ($keys['payment_mode'] == 'live') {
            $endPoint = 'https://api.vivapayments.com/';
        }

        // construct the endpoint with specific path
        $endPoint = $endPoint . $path;

        // Headers of the request
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $accessToken
        );

        // Send the request
       if ($method == 'POST') {
            $response = wp_remote_post($endPoint, [
                'headers' => $headers,
                'body'    => json_encode($args)
            
            ]);
        } else {
            $response = wp_remote_request($endPoint, [
                'headers' => $headers,
                'body'    => $args
            ]);
        }

        if (is_wp_error($response)) {
            return [
                'response' => array(
                    'success' => 'false',
                    'error' => $response->get_error_message()
                )
            
            ];
        }

        // retrieve the body of the response
        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if (!$responseData) {
            return [
                'response' => array(
                    'success' => 'false',
                    'error' => __('Unknown Moneris API request error', 'wp-payment-form-pro')
                )
            ];
        }

        return $responseData;
    }

    public function getAccessToken($path, $args, $formId, $method = 'POST')
    {
        $keys = (new VivaWalletSettings())->getApiKeys($formId);

        $endPoint = 'https://demo-accounts.vivapayments.com/';

        if ($keys['payment_mode'] == 'live') {
            $endPoint = 'https://accounts.vivapayments.com/';
        }

        $encoded = base64_encode($keys['client_id'] . ':' . $keys['client_secret']);
        $headers = [
            'Content-type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . $encoded
        ];
        
         // construct the endpoint with specific path
        $endPoint = $endPoint . $path;
        
        if ($method == 'POST') {
            $response = wp_remote_post($endPoint, [
                'headers' => $headers,
                'body' =>  [
                    'grant_type' => 'client_credentials'
                ]
            
            ]);
        } else {
            $response = wp_remote_request($endPoint, [
                'headers' => $headers,
                'body'    => $args
            ]);
        }

        if (is_wp_error($response)) {
            return [
                'response' => array(
                    'success' => 'false',
                    'error' => $response->get_error_message()
                )
            
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);
       
        if (!$responseData) {
            return [
                'response' => array(
                    'success' => 'false',
                    'error' => __('Invalid Vivalwallet API request.', 'wp-payment-form-pro')
                )
            ];
        }
        return $responseData;
    }

    public function getWebhookVerificationKey()
    {
        $lastRequest = get_option('vivawallet_webhook_verification_time', 0);
        
        if ($lastRequest) {
            $timeDiff = time() - $lastRequest;
            // we allow only 60 req per minute, which won't be able to down the server
            if ($timeDiff < 1) {
                wp_send_json_error(__('Too many requests. Please try again later.', 'wp-payment-form-pro'), 429);
            }
        }

        $keys = (new VivaWalletSettings())->getApiKeys();

        $endPoint = 'https://demo.vivapayments.com/';

        if ($keys['payment_mode'] == 'live') {
            $endPoint = 'https://www.vivapayments.com/';
        }

        $encoded = base64_encode($keys['merchant_id'] . ':' . $keys['api_key']);
        $headers = [
            'Content-type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . $encoded
        ];
        
         // construct the endpoint with specific path
        $endPoint = $endPoint . 'api/messages/config/token';
       
        $response = wp_remote_request($endPoint, [
            'headers' => $headers,
            'body'    => []
        ]);
        
        if (is_wp_error($response)) {
           wp_send_json_error($response->get_error_message(), 400);
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);
       
        if (!$responseData) {
            wp_send_json_error(__('Invalid Vivalwallet API request.', 'wp-payment-form-pro'), 400);
        }

        update_option('vivawallet_webhook_verification_time', time());

        return wp_send_json($responseData, 200);
    }
    
}
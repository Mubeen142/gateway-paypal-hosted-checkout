<?php

namespace Modules\PayPalExpressCheckoutGateway\Entities;

use App\Models\Gateways\PaymentGatewayInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Gateways\Gateway;
use Illuminate\Http\Request;
use App\Models\PackagePrice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Settings;
use App\Models\User;

/**
 * Class ExampleGateway
 *
 * ExampleGateway implements the PaymentGatewayInterface, defining the contract for payment gateways within the system.
 * It provides methods to handle payments, receive responses from the payment gateway, process refunds, configure the gateway,
 * fetch configuration, and check subscriptions.
 *
 * @package Modules\ExampleGateway\Entities
 */
class PayPalExpressCheckoutGateway implements PaymentGatewayInterface
{

    /**
     * The method is responsible for preparing and processing the payment get the gateway and payment objects
     * use dd($gateway $payment) for debugging
     *
     * @param Gateway $gateway
     * @param Payment $payment
     */
    public static function processGateway(Gateway $gateway, Payment $payment)
    {
        return view('paypal_express_checkout_gateway::gateways.edit.paypal-checkout', compact('payment'));
    }

    /**
     * Create a PayPal order and redirect the user to PayPal.
     */
    public static function createOrder(Payment $payment)
    {
        // Get an access token from PayPal
        $accessToken = self::getAccessToken();

        // Create an order
        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => 'PUHF',
                        'amount' => [
                            'currency_code' => 'USD',
                            'value' => '49.99'
                        ]
                    ]
                ],
                'application_context' => [
                    'brand_name'   => 'Your Brand',
                    'landing_page' => 'NO_PREFERENCE',
                    'user_action'  => 'PAY_NOW',
                    'return_url'   => route('payment.success', $payment->id),
                    'cancel_url'   => route('payment.cancel', $payment->id),
                ]
            ]);

        $order = $response->json();
        
        if (isset($order['links'])) {
            // Find the link for payer approval
            foreach ($order['links'] as $link) {
                if ($link['rel'] === 'approve') {
                    // Redirect the user to PayPal to complete the payment
                    return redirect($link['href']);
                }
            }
        }

        return response()->json(['error' => 'Unable to create PayPal order'], 500);
    }

    protected static function createWebhookUrl()
    {
        $gateway = self::getGateway();
        $accessToken = self::getAccessToken();

        $response = Http::withToken($accessToken)
            ->post(self::apiEndpoint('/notifications/webhooks'), [
                "url" => route('payment.return', ['gateway' => self::endpoint()]),
                "event_types" => [
                    ['name' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
                ],
            ]);
        
        if ($response->failed()) {
            return null;
        }

        $webhook = $response->json();

        if(self::isSandboxMode())
        {
            Settings::put('PayPalExpressCheckoutGateway:sandbox_webhook_id', $webhook['id']);
            return;
        }

        Settings::put('PayPalExpressCheckoutGateway:webhook_id', $webhook['id']);
    }

    protected static function getWebhookId()
    {
        $webhookId = self::isSandboxMode() ? settings('PayPalExpressCheckoutGateway:sandbox_webhook_id') : settings('PayPalExpressCheckoutGateway:webhook_id');

        if(!$webhookId)
        {
            self::createWebhookUrl();
            return self::getWebhookId();
        }

        return $webhookId;
    }

    /**
     * Handles the response from the payment gateway. It uses a Request object to receive and handle the response appropriately.
     * endpoint: payment/return/{endpoint_gateway}
     * @param Request $request
     */
    public static function returnGateway(Request $request)
    {
        return response()->json(['status' => 'ok']);
    }

    public static function processRefund(Payment $payment, array $data)
    {
        // todo
    }

    /**
     * Defines the configuration for the payment gateway. It returns an array with data defining the gateway driver,
     * type, class, endpoint, refund support, etc.
     *
     * @return array
     */
    public static function drivers(): array
    {
        return [
            'PayPalExpressCheckoutGateway' => [
                'driver' => 'PayPalExpressCheckoutGateway',
                'type' => 'once', // subscription
                'class' => 'Modules\PayPalExpressCheckoutGateway\Entities\PayPalExpressCheckoutGateway',
                'endpoint' => self::endpoint(),
                'refund_support' => false,
                'blade_edit_path' => 'paypal_express_checkout_gateway::gateways.edit.paypal-checkout', // optional
            ]
        ];
    }

    /**
     * Checks the status of a subscription in the payment gateway. If the subscription is active, it returns true; otherwise, it returns false.
     * Do not change this method if you are not using subscriptions
     * @param Gateway $gateway
     * @param $subscriptionId
     * @return bool
     */
    public static function checkSubscription(Gateway $gateway, $subscriptionId): bool
    {
        return false;
    }

    /**
     * Defines the endpoint for the payment gateway. This is an ID used to automatically determine which gateway to use.
     *
     * @return string
     */
    public static function endpoint(): string
    {
        return 'paypal-express-checkout';
    }

    public static function getGateway()
    {
        return Gateway::where('driver', 'PayPalExpressCheckoutGateway')->first();
    }

    public static function isSandboxMode(): bool
    {
        $gateway = self::getGateway();

        return $gateway->config['paypal_mode'] === 'sandbox';
    }

    public static function getAccessToken()
    {
        return Cache::remember('PayPalExpressCheckoutGateway:access_token', 60, function () {
            $gateway = self::getGateway();
            $clientId = $gateway->config['paypal_client_id'];
            $clientSecret = $gateway->config['paypal_client_secret'];

            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post(self::apiEndpoint('/oauth2/token'), [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                return null;
            }

            return $response->json()['access_token'];
        });
    }

    public static function apiUrl()
    {
        return self::isSandboxMode() ? 'https://api-m.sandbox.paypal.com/v1' : 'https://api-m.paypal.com/v1';
    }

    public static function apiEndpoint($path = '')
    {
        return self::apiUrl() . $path;
    }

    /**
     * Returns an array with the configuration for the payment gateway.
     * These options are displayed for the administrator to configure.
     * You can access them: $gateway->config()
     * @return array
     */
    public static function getConfigMerge(): array
    {
        return [
            'paypal_client_id' => '',
            'paypal_client_secret' => '',
            'paypal_mode' => ['sandbox', 'live'],
            // more parameters ...
        ];
    }
}
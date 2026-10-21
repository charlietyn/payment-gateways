<?php

/*
|--------------------------------------------------------------------------
| ronu/payment-gateways
|--------------------------------------------------------------------------
|
| Publish with:  php artisan vendor:publish --tag=payment-gateways-config
|
| Every gateway has the same sections:
|
|   driver       mercadopago | stripe | paypal | any driver added with GatewayFactory::extend()
|   environment  emulator (Payment Emulator Lab) | sandbox (test credentials) | live (real money)
|   credentials  what the provider authenticates with
|   webhooks     how notifications are verified
|   options      provider behaviour (currency, API version, branding…)
|   emulator     only with environment = emulator
|   http         timeouts (s), retries (2) and backoff (retry_delay_ms, max_retry_delay_ms)
|
| Going from the emulator to the real provider = change `environment`
| and the credentials. The code that takes payments does not change.
|
| Secrets come from the environment only. An unset env() keeps the default.
*/

// With or without the profile: http://localhost:8080 and http://localhost:8080/plaza are the same emulator.
$emulator = [
    'api_url' => env('PAYMENT_EMULATOR_URL', 'http://localhost:8080'),
    'checkout_url' => env('PAYMENT_EMULATOR_CHECKOUT_URL', 'http://localhost:3001'),
];

return [

    // The gateway app(Gateway::class) returns.
    'default' => env('PAYMENT_GATEWAY', 'mercadopago'),

    // One log line per provider call (method, path, status, duration). Null: the default channel.
    'log_channel' => env('PAYMENT_GATEWAYS_LOG_CHANNEL'),

    'gateways' => [

        /*
        | Mercado Pago — Orders API (cards with the Brick, tickets) and Checkout Pro.
        | Emulator profile: Plaza.
        */
        'mercadopago' => [
            'driver' => 'mercadopago',
            'environment' => env('MERCADOPAGO_ENVIRONMENT', 'emulator'),

            'credentials' => [
                'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),      // TEST-… / APP_USR-…
            ],

            'webhooks' => [
                'secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),          // "Secret signature" of your webhook
                'tolerance_seconds' => 600,
            ],

            'options' => [
                'currency' => env('MERCADOPAGO_CURRENCY', 'ARS'),       // the currency of your site (MLA = ARS)
                'ticket_method' => 'pagofacil',                         // createPayment() without a card
                'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'), // Checkout Pro
                'statement_descriptor' => env('MERCADOPAGO_STATEMENT_DESCRIPTOR'),
            ],

            'emulator' => $emulator + ['scenario' => 'plaza/payment-pending'],
        ],

        /*
        | Stripe — PaymentIntents (Elements) and Checkout Sessions.
        | Emulator profile: Vector.
        */
        'stripe' => [
            'driver' => 'stripe',
            'environment' => env('STRIPE_ENVIRONMENT', 'emulator'),

            'credentials' => [
                'secret_key' => env('STRIPE_SECRET_KEY'),               // sk_test_… / sk_live_…
            ],

            'webhooks' => [
                'secret' => env('STRIPE_WEBHOOK_SECRET'),               // whsec_…
                'tolerance_seconds' => 300,
            ],

            'options' => [
                'api_version' => env('STRIPE_API_VERSION'),             // pin it: the version you tested
            ],

            'emulator' => $emulator + ['scenario' => 'vector/payment-requires-action'],
        ],

        /*
        | PayPal — Orders v2 + Payments v2, OAuth client credentials.
        | Emulator profile: Harbor.
        */
        'paypal' => [
            'driver' => 'paypal',
            'environment' => env('PAYPAL_ENVIRONMENT', 'emulator'),

            'credentials' => [
                'client_id' => env('PAYPAL_CLIENT_ID'),
                'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            ],

            'webhooks' => [
                'id' => env('PAYPAL_WEBHOOK_ID'),                       // the id PayPal gave your webhook
                'tolerance_seconds' => 300,
            ],

            'options' => [
                'brand_name' => env('PAYPAL_BRAND_NAME'),
                'shipping_preference' => 'NO_SHIPPING',                 // GET_FROM_FILE if you ship goods
            ],

            'emulator' => $emulator,
        ],

    ],

];

<?php

/*
 * ronu/payment-gateways — publish with:
 *   php artisan vendor:publish --tag=payment-gateways-config
 *
 * Each named gateway picks a preset (see GatewayFactory::PRESETS) and its
 * credentials. Emulator presets (plaza, vector, harbor) take emulator_url and,
 * for Plaza, emulator_checkout_url. Secrets come from the environment only.
 */
return [
    'default' => env('PAYMENT_GATEWAY', 'plaza'),

    'gateways' => [
        'plaza' => [
            'preset' => 'plaza',
            'emulator_url' => env('PAYMENT_EMULATOR_URL', 'http://localhost:8080'),
            'emulator_checkout_url' => env('PAYMENT_EMULATOR_CHECKOUT_URL', 'http://localhost:3001'),
            'access_token' => env('PLAZA_ACCESS_TOKEN'),
            'webhook_secret' => env('PLAZA_WEBHOOK_SECRET'),
        ],
        'mercadopago' => [
            'preset' => 'mercadopago',
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        ],
        'vector' => [
            'preset' => 'vector',
            'emulator_url' => env('PAYMENT_EMULATOR_URL', 'http://localhost:8080'),
            'secret_key' => env('VECTOR_SECRET_KEY'),
            'webhook_secret' => env('VECTOR_WEBHOOK_SECRET'),
        ],
        'stripe' => [
            'preset' => 'stripe',
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'harbor' => [
            'preset' => 'harbor',
            'emulator_url' => env('PAYMENT_EMULATOR_URL', 'http://localhost:8080'),
            'client_id' => env('HARBOR_CLIENT_ID'),
            'client_secret' => env('HARBOR_CLIENT_SECRET'),
            'webhook_id' => env('HARBOR_WEBHOOK_ID'),
        ],
        'paypal' => [
            'preset' => env('PAYPAL_SANDBOX', true) ? 'paypal_sandbox' : 'paypal',
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],
    ],
];

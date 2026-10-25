<?php

return [

    /*
    |--------------------------------------------------------------------------
    | E-shop payment methods
    |--------------------------------------------------------------------------
    |
    | Every method is off unless configured here. Secrets come from the
    | environment only and are never rendered, returned or logged.
    |
    */

    // Hosted PayHere Checkout API. Only the sandbox endpoint is used: switching
    // to live is a deliberate code change, not an environment toggle.
    'payhere' => [
        'enabled' => (bool) env('PAYHERE_ENABLED', false),
        'merchant_id' => env('PAYHERE_MERCHANT_ID'),
        'merchant_secret' => env('PAYHERE_MERCHANT_SECRET'),
        'checkout_url' => 'https://sandbox.payhere.lk/pay/checkout',
    ],

    // Development-only simulated payments. All conditions must hold, and the
    // production hosts in App\Payments\DummyPaymentGate are always refused.
    'dummy' => [
        'enabled' => (bool) env('PAYMENT_DUMMY_ENABLED', false),
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENT_DUMMY_ALLOWED_HOSTS', ''))))),
        'allowed_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAYMENT_DUMMY_ALLOWED_EMAILS', ''))))),
    ],

    // Bank transfer evidence uploads per submission.
    'bank_transfer' => [
        'max_evidence_files' => 3,
    ],

    // Delivery countries offered at checkout (ISO 3166-1 alpha-2 => name).
    // Sri Lanka only until a delivery/shipping policy is approved.
    'delivery_countries' => [
        'LK' => 'Sri Lanka',
    ],

];

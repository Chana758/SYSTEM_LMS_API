<?php

/*
 | Payments (B5.1). KHQR paid into the school's account.
 | PAYMENT_DRIVER=bakong  - Bakong KHQR (NBC), money goes straight to a Bakong account (main choice)
 | PAYMENT_DRIVER=payway  - ABA PayWay merchant (needs an ABA merchant contract)
 | PAYMENT_DRIVER=fake    - test QR for demos
 | (staff press "Simulate paid") and is refused when APP_ENV=production.
 */
return [
    'driver' => env('PAYMENT_DRIVER', 'fake'),

    'currency' => 'KHR',

    // Minutes a QR can be paid before it expires (PayWay minimum is 3).
    'qr_lifetime_minutes' => (int) env('PAYMENT_QR_LIFETIME', 10),

    // Wait at least this long between two status checks of one payment.
    'check_interval_seconds' => 5,

    'bakong' => [
        // Production: https://api-bakong.nbc.gov.kh   Test (SIT): https://sit-api-bakong.nbc.gov.kh
        'base_url'      => env('BAKONG_BASE_URL', 'https://api-bakong.nbc.gov.kh'),
        'token'         => env('BAKONG_TOKEN', ''),
        'account_id'    => env('BAKONG_ACCOUNT_ID', ''),          // e.g. library@aclb
        'merchant_name' => env('BAKONG_MERCHANT_NAME', 'Smart Nexus Library'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'timeout'       => 15,
    ],

    'payway' => [
        'base_url'    => env('PAYWAY_BASE_URL', 'https://checkout-sandbox.payway.com.kh'),
        'merchant_id' => env('PAYWAY_MERCHANT_ID'),
        'api_key'     => env('PAYWAY_API_KEY'),
        'qr_template' => env('PAYWAY_QR_TEMPLATE', 'template3_color'),
        'timeout'     => 15,
    ],
];

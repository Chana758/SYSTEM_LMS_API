<?php

/*
 | Payments (B5.1). KHQR through ABA PayWay.
 | PAYMENT_DRIVER=payway talks to ABA; PAYMENT_DRIVER=fake makes a test QR for demos
 | (staff press "Simulate paid") and is refused when APP_ENV=production.
 */
return [
    'driver' => env('PAYMENT_DRIVER', 'fake'),

    'currency' => 'KHR',

    // Minutes a QR can be paid before it expires (PayWay minimum is 3).
    'qr_lifetime_minutes' => (int) env('PAYMENT_QR_LIFETIME', 10),

    // Wait at least this long between two status checks of one payment.
    'check_interval_seconds' => 5,

    'payway' => [
        'base_url'    => env('PAYWAY_BASE_URL', 'https://checkout-sandbox.payway.com.kh'),
        'merchant_id' => env('PAYWAY_MERCHANT_ID'),
        'api_key'     => env('PAYWAY_API_KEY'),
        'qr_template' => env('PAYWAY_QR_TEMPLATE', 'template3_color'),
        'timeout'     => 15,
    ],
];

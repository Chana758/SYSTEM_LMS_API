<?php

return [
    // true ONLY on the dedicated demo deployment (with its own database)
    'enabled' => (bool) env('DEMO_MODE', false),
    'password' => env('DEMO_PASSWORD'),
    'email_domain' => env('DEMO_EMAIL_DOMAIN', 'demo-lms.example'),
];

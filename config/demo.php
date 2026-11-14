<?php

return [
    'enabled' => (bool) env('DEMO_MODE', false),

    'password' => env('DEMO_PASSWORD'),

    'email_domain' => env('DEMO_EMAIL_DOMAIN', 'demo-lms.example'),

    'database_name' => env('DEMO_DATABASE_NAME'),
];
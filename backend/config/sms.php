<?php

return [
    'enabled' => (bool) env('SMS_ENABLED', false),
    'default_sender' => env('SMS_DEFAULT_SENDER', 'Biljettera'),
    'dry_run' => (bool) env('SMS_DRY_RUN', false),

    'elks' => [
        'base_url' => env('ELKS_BASE_URL', 'https://api.46elks.com/a1'),
        'username' => env('ELKS_USERNAME'),
        'password' => env('ELKS_PASSWORD'),
        'timeout_seconds' => (int) env('ELKS_TIMEOUT_SECONDS', 10),
    ],
];

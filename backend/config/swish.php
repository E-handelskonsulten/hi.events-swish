<?php

return [
    'enabled' => (bool) env('SWISH_ENABLED', false),
    'environment' => env('SWISH_ENVIRONMENT', 'mss'),
    'payee_alias' => env('SWISH_PAYEE_ALIAS'),
    'cert_path' => env('SWISH_CERT_PATH'),
    'key_path' => env('SWISH_KEY_PATH'),
    'key_passphrase' => env('SWISH_KEY_PASSPHRASE'),
    'ca_path' => env('SWISH_CA_PATH'),

    'callback_base_url' => env('SWISH_CALLBACK_BASE_URL', env('APP_URL', 'http://localhost')),

    'base_urls' => [
        'mss' => env('SWISH_MSS_BASE_URL', 'https://mss.cpc.getswish.net'),
        'production' => env('SWISH_PRODUCTION_BASE_URL', 'https://cpc.getswish.net'),
    ],

    'timeout_seconds' => (int) env('SWISH_TIMEOUT_SECONDS', 10),
    'connect_timeout_seconds' => (int) env('SWISH_CONNECT_TIMEOUT_SECONDS', 5),
    'status_poll_min_interval_seconds' => (int) env('SWISH_STATUS_POLL_MIN_INTERVAL_SECONDS', 3),
];

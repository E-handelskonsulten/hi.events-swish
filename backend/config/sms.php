<?php

return [
    'enabled' => (bool) env('SMS_ENABLED', false),
    'default_sender' => env('SMS_DEFAULT_SENDER', 'Biljettera'),
    'dry_run' => (bool) env('SMS_DRY_RUN', false),

    // Numbers that must never receive an SMS from this installation, e.g. the
    // Swish MSS test payer alias that ends up on orders paid in the sandbox.
    // Enforced in production by default; other environments opt in.
    'blocked_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SMS_BLOCKED_RECIPIENTS', '+46701234567')),
    ))),
    'blocklist_enforced' => (bool) env('SMS_BLOCKLIST_ENFORCED', env('APP_ENV') === 'production'),

    'elks' => [
        'base_url' => env('ELKS_BASE_URL', 'https://api.46elks.com/a1'),
        'username' => env('ELKS_USERNAME'),
        'password' => env('ELKS_PASSWORD'),
        'timeout_seconds' => (int) env('ELKS_TIMEOUT_SECONDS', 10),
    ],
];

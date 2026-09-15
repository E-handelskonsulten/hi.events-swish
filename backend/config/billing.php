<?php

return [
    'summary_email' => env('BILLING_SUMMARY_EMAIL'),
    'default_platform_fee_per_ticket' => (float) env('BILLING_DEFAULT_PLATFORM_FEE_PER_TICKET', 6.00),
    'default_sms_fee_per_message' => (float) env('BILLING_DEFAULT_SMS_FEE_PER_MESSAGE', 0.50),
    'currency' => env('BILLING_CURRENCY', 'SEK'),
    'timezone' => env('BILLING_TIMEZONE', 'Europe/Stockholm'),
];

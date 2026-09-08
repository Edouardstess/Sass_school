<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SchoolFlow third-party integrations
    |--------------------------------------------------------------------------
    | Every provider is optional. When credentials are absent the corresponding
    | gateway/driver reports itself unavailable rather than silently failing or
    | pretending to have succeeded.
    */

    'moncash' => [
        'enabled' => (bool) env('MONCASH_ENABLED', false),
        'client_id' => env('MONCASH_CLIENT_ID'),
        'client_secret' => env('MONCASH_CLIENT_SECRET'),
        'mode' => env('MONCASH_MODE', 'sandbox'),   // sandbox | live
        'webhook_secret' => env('MONCASH_WEBHOOK_SECRET'),
    ],

    'natcash' => [
        'enabled' => (bool) env('NATCASH_ENABLED', false),
        'merchant_id' => env('NATCASH_MERCHANT_ID'),
        'api_key' => env('NATCASH_API_KEY'),
        // Provisioned with the merchant account; see docs/PAYMENTS.md.
        'base_url' => env('NATCASH_BASE_URL'),
        'webhook_secret' => env('NATCASH_WEBHOOK_SECRET'),
    ],

    'stripe' => [
        'enabled' => (bool) env('STRIPE_ENABLED', false),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),       // log | twilio | null
        'from' => env('SMS_FROM', 'SchoolFlow'),
        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),  // log | cloud_api | null
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_TOKEN'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('AI_MODEL', 'claude-sonnet-5'),
    ],

];

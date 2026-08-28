<?php

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IPROG SMS (delivery only)
    |--------------------------------------------------------------------------
    |
    | LMLinga owns OTP generation/verification. IPROG is used only to queue
    | outbound SMS via POST /sms_messages. Never put the real token in
    | .env.example or source control.
    |
    */
    'iprog' => [
        'base_url' => env('IPROG_BASE_URL', 'https://www.iprogsms.com/api/v1'),
        'api_token' => env('IPROG_API_TOKEN'),
        'timeout' => (int) env('IPROG_HTTP_TIMEOUT', 10),
    ],

];

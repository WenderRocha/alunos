<?php

declare(strict_types = 1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'evolution' => [
        'base_url'             => env('EVOLUTION_API_URL'),
        'api_key'              => env('EVOLUTION_API_KEY'),
        'instance'             => env('EVOLUTION_INSTANCE'),
        'timeout'              => (int) env('EVOLUTION_API_TIMEOUT', 15),
        'payload_format'       => env('EVOLUTION_TEXT_PAYLOAD_FORMAT', 'top-level'),
        'default_country_code' => env('EVOLUTION_DEFAULT_COUNTRY_CODE', '55'),
    ],

];

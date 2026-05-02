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

    'ocr' => [
        'tesseract_bin' => env('OCR_TESSERACT_BIN', 'tesseract'),
        'language' => env('OCR_LANGUAGE', 'ind+eng'),
        'oem' => (int) env('OCR_OEM', 1),
        'psm' => (int) env('OCR_PSM', 6),
        'min_word_confidence' => (float) env('OCR_MIN_WORD_CONFIDENCE', 35),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'ca_bundle' => env('GEMINI_CA_BUNDLE'),
        'ssl_verify' => env('GEMINI_SSL_VERIFY', true),
    ],

];

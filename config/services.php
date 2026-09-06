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

    // NewsArticleGenerator (News Feed "Generate dengan AI") and the Dashboard sales-insight
    // summary. A Gemini API key from https://aistudio.google.com/apikey ("Create API key") —
    // not a Google/Gemini CLI sign-in session, which authenticates a different, non-API surface.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
    ],

    // Push notifications to the mobile app via Firebase Cloud Messaging (HTTP v1) — see
    // App\Services\Push\FcmClient. Both values empty = push disabled, everything else still works.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Absolute path to the service-account JSON. Keep it out of public/ and out of git.
        'credentials_path' => env('FCM_CREDENTIALS_PATH', storage_path('app/private/fcm-service-account.json')),
        // Seconds per HTTP call. Pushes are sent inline after commit, so this bounds the extra
        // latency an approval can incur when Google is slow.
        'timeout' => (int) env('FCM_TIMEOUT_SECONDS', 5),
    ],

];

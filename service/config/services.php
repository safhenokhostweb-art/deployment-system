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

    /*
    |--------------------------------------------------------------------------
    | GitHub App (Phase 4-E)
    |--------------------------------------------------------------------------
    |
    | Deliberately no long-lived user/account token lives anywhere in this
    | app — every GitHub API call is authenticated per-request by signing a
    | JWT with `private_key` (RS256, via PHP's built-in openssl_sign — no
    | new Composer dependency) and exchanging it for a short-lived (1 hour)
    | installation access token. All five values are unset until a real
    | GitHub App is registered at github.com/settings/apps and its
    | credentials are filled into .env — see docs/PHASE_4E_GITHUB_PROJECT_WIZARD_REPORT.md.
    |
    */
    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
    ],

];

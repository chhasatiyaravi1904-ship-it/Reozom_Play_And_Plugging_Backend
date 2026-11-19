<?php

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

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'linkedin-openid' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect' => env('LINKEDIN_REDIRECT_URI'),
    ],

    'twitter' => [
        // Opts Socialite into the OAuth2 Twitter/X provider instead of the
        // legacy OAuth1 flow, so it can use stateless() like the others.
        'oauth' => 2,
        'client_id' => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect' => env('TWITTER_REDIRECT_URI'),
    ],

    // Stripe Checkout. Keys live only in .env (test keys: pk_test_/sk_test_), never in code.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Separate endpoint/secret for events from agents' Connect accounts
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'currency' => strtolower(env('STRIPE_CURRENCY', 'usd')),
        // Stripe's standard US card fee, recovered from the agent on seller payments
        'processing_fee_percent' => (float) env('STRIPE_FEE_PERCENT', 2.9),
        'processing_fee_fixed_cents' => (int) env('STRIPE_FEE_FIXED_CENTS', 30),
    ],

];

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

    'razorpay' => [
        'key_id'     => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'test_mode' => env('RAZORPAY_TEST_MODE', true),
        'live_subscriptions_enabled' => env('RAZORPAY_LIVE_SUBSCRIPTIONS_ENABLED', false),
        'usd_recurring_enabled' => env('RAZORPAY_USD_RECURRING_ENABLED', false),
        'subscription_total_count' => [
            'monthly' => (int) env('RAZORPAY_SUBSCRIPTION_MONTHLY_COUNT', 120),
            'yearly' => (int) env('RAZORPAY_SUBSCRIPTION_YEARLY_COUNT', 10),
        ],
        'plans' => [
            'growth' => [
                'monthly' => ['INR' => env('RAZORPAY_PLAN_GROWTH_MONTHLY_INR'), 'USD' => env('RAZORPAY_PLAN_GROWTH_MONTHLY_USD')],
                'yearly' => ['INR' => env('RAZORPAY_PLAN_GROWTH_YEARLY_INR'), 'USD' => env('RAZORPAY_PLAN_GROWTH_YEARLY_USD')],
            ],
            'business' => [
                'monthly' => ['INR' => env('RAZORPAY_PLAN_BUSINESS_MONTHLY_INR'), 'USD' => env('RAZORPAY_PLAN_BUSINESS_MONTHLY_USD')],
                'yearly' => ['INR' => env('RAZORPAY_PLAN_BUSINESS_YEARLY_INR'), 'USD' => env('RAZORPAY_PLAN_BUSINESS_YEARLY_USD')],
            ],
        ],
    ],

    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'redirect_uri' => env('META_REDIRECT_URI'),
        'whatsapp_config_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
        'whatsapp_redirect_uri' => env('WHATSAPP_REDIRECT_URI'),
        'whatsapp_feature_type' => env('WHATSAPP_EMBEDDED_SIGNUP_FEATURE', 'whatsapp_business_app_onboarding'),
        'whatsapp_signup_version' => env('WHATSAPP_EMBEDDED_SIGNUP_VERSION', 'v4'),
        'whatsapp_session_info_version' => env('WHATSAPP_SESSION_INFO_VERSION', '3'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'pubsub_topic' => env('GMAIL_PUBSUB_TOPIC'),
        'pubsub_verification_token' => env('GMAIL_PUBSUB_VERIFICATION_TOKEN'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
        'notification_url' => env('MICROSOFT_NOTIFICATION_URL'),
    ],

    'lead_integrations' => [
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],

];

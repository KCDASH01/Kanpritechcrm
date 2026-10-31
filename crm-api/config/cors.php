<?php

return [

    /*
     * Paths that should have CORS headers applied.
     * 'api/*' covers all API routes.
     */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Allow requests from the CRM front-end and the Lead Scraping app (SSO source).
     */
    'allowed_origins' => array_values(array_unique(array_filter([
        'http://localhost:3000',   // lead-scraping-app
        'http://localhost:3002',   // crm-app
        env('FRONTEND_URL'),       // deployed CRM frontend (staging or production)
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

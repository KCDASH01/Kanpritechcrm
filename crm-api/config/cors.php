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
    'http://localhost:3000',
    'http://localhost:3002',
    'https://growneq.com',
    'https://www.growneq.com',
    'https://admin.growneq.com',
    env('FRONTEND_URL'),
]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

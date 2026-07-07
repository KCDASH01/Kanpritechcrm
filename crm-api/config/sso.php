<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SSO Secret & Algorithm
    |--------------------------------------------------------------------------
    | Shared JWT secret with the Lead Scraping App.
    | Set SSO_SECRET and (optionally) SSO_ALGO in your .env file.
    */
    'secret' => env('SSO_SECRET', ''),
    'algo'   => env('SSO_ALGO', 'HS256'),
];

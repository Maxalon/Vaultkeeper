<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The SPA and API are served from the same origin in every environment
    | (vault.kontrollzentrale.de/* + /api/*), so cross-origin requests are
    | not part of the normal flow. We still publish an explicit policy
    | rather than relying on Laravel's permissive default — without this
    | file, a future deploy that splits the API onto api.vault.* would
    | inherit `allowed_origins => ['*']` and become a real cross-origin
    | risk.
    |
    | If the API is ever moved to a different origin, add the SPA host to
    | `allowed_origins` (don't replace it with '*'). Keep
    | `supports_credentials` false: the API uses Bearer JWTs in the
    | Authorization header, not cookies, so credentialed CORS is never
    | needed and would only complicate the policy.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // env('APP_URL') alone — every official frontend lives on the same host
    // as the API. CI / local dev override APP_URL to http://localhost so the
    // Vite dev server still works.
    'allowed_origins' => array_filter([env('APP_URL')]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

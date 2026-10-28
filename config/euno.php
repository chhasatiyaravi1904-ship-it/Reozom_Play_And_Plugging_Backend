<?php

return [
    /*
    |--------------------------------------------------------------------------
    | EunoDoc Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Eunodoc e-signature microservice used across
    | Reozom for electronic document execution, coordinate tagging, and audit trail.
    |
    */

    'api_url' => env('EUNO_API_URL', 'https://eunodocback.reozom.com/api/v1'),
    'web_url' => env('EUNO_WEB_URL', 'https://eunodoc.reozom.com'),
    'auth_token' => env('EUNO_AUTH_TOKEN', 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJfaWQiOiI2NTI2ZjdiNDM2MWZlZGM0OTU4OWU1MzMiLCJpYXQiOjE3MjY2NDU5NTl9.SZ381_ZWGf4t_w30V8L2fsFQmlwkYy-BNSjnztq_iZs'),
    'retries' => env('EUNO_RETRIES', 3),
    'mock_mode' => env('EUNO_MOCK_MODE', false),
];

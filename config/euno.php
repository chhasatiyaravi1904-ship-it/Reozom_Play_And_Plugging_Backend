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
    // Secret: set EUNO_AUTH_TOKEN in .env ("Bearer <jwt>"); never commit a default here.
    'auth_token' => env('EUNO_AUTH_TOKEN', ''),
    'retries' => env('EUNO_RETRIES', 3),
    'mock_mode' => env('EUNO_MOCK_MODE', false),
    // qpdf command used to split a signed packet into one signed PDF per document (as Reozom).
    // Without qpdf the whole signed packet is served for each document.
    'qpdf_path' => env('QPDF_PATH', 'qpdf'),
];

<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

if (! function_exists('api_success')) {
    /**
     * Build a successful JSON response.
     */
    function api_success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, $message, $status);
    }
}

if (! function_exists('api_error')) {
    /**
     * Build a failed JSON response.
     *
     * @param  array<string, array<int, string>>|null  $errors
     */
    function api_error(string $message, int $status = 422, ?array $errors = null): JsonResponse
    {
        return ApiResponse::error($message, $status, $errors);
    }
}

// Autoloader for Smalot\PdfParser if needed
spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'Smalot\\PdfParser\\')) {
        $file = __DIR__ . '/../vendor/smalot/pdfparser/src/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

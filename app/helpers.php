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

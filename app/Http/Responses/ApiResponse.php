<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * Build a successful JSON response.
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'code' => $status,
            'message' => $message,
            'data' => $data,
        ], fn (mixed $value): bool => $value !== null), $status);
    }

    /**
     * Build a failed JSON response.
     *
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, int $status = 422, ?array $errors = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'code' => $status,
            'message' => $message,
            'errors' => $errors,
        ], fn (mixed $value): bool => $value !== null), $status);
    }
}

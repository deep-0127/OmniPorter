<?php

namespace OmniPorter\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(string $message = '', mixed $data = null, int $code = 200): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if (!is_null($data)) {
            $response['data'] = $data;
        }

        return response()->json($response, $code);
    }

    public static function error(string $message = 'Something went wrong.', mixed $errors = null, int $code = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if (!is_null($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    public static function validationError(mixed $errors, string $message = 'Validation failed.'): JsonResponse
    {
        return self::error($message, $errors, 422);
    }

    public static function unauthorized(string $message = 'Unauthorized access.'): JsonResponse
    {
        return self::error($message, null, 401);
    }

    public static function notFound(string $message = 'Resource not found.'): JsonResponse
    {
        return self::error($message, null, 404);
    }
}

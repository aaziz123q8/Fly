<?php

declare(strict_types=1);

namespace App\Core;

class Response
{
    private const CONTENT_TYPE = 'Content-Type: application/json; charset=UTF-8';
    private const JSON_FLAGS   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    // -------------------------------------------------------------------------
    // Success responses
    // -------------------------------------------------------------------------

    /**
     * Generic JSON response.
     */
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header(self::CONTENT_TYPE);
        echo json_encode($data, self::JSON_FLAGS);
        exit;
    }

    /**
     * 201 Created.
     */
    public static function created(mixed $data): never
    {
        self::json($data, 201);
    }

    /**
     * 204 No Content.
     */
    public static function noContent(): never
    {
        http_response_code(204);
        header(self::CONTENT_TYPE);
        exit;
    }

    // -------------------------------------------------------------------------
    // Error responses
    // -------------------------------------------------------------------------

    /**
     * Generic error response.
     */
    public static function error(string $message, int $status = 400, ?string $code = null): never
    {
        $body = ['error' => $code ?? self::statusToCode($status), 'message' => $message];
        self::json($body, $status);
    }

    /**
     * 400 Bad Request with validation errors.
     */
    public static function validationError(array $errors): never
    {
        self::json([
            'error'   => 'validation_failed',
            'message' => 'The given data was invalid.',
            'errors'  => $errors,
        ], 422);
    }

    /**
     * 401 Unauthorized.
     */
    public static function unauthorized(string $message = 'Unauthenticated.'): never
    {
        self::error($message, 401, 'unauthenticated');
    }

    /**
     * 403 Forbidden.
     */
    public static function forbidden(string $message = 'Forbidden.'): never
    {
        self::error($message, 403, 'forbidden');
    }

    /**
     * 404 Not Found.
     */
    public static function notFound(string $message = 'Resource not found.'): never
    {
        self::error($message, 404, 'not_found');
    }

    /**
     * 429 Too Many Requests.
     */
    public static function tooManyRequests(string $message = 'Too many requests.'): never
    {
        http_response_code(429);
        header(self::CONTENT_TYPE);
        header('Retry-After: 60');
        echo json_encode(['error' => 'too_many_requests', 'message' => $message], self::JSON_FLAGS);
        exit;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function statusToCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            500 => 'server_error',
            default => 'error',
        };
    }
}

<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * HTTP helpers: JSON output, error envelope, correlation id, security headers,
 * JSON body parsing. Every API response passes through here so the format
 * stays consistent across all endpoints.
 */
final class Http
{
    private static ?string $correlationId = null;

    public static function correlationId(): string
    {
        if (self::$correlationId === null) {
            self::$correlationId = $_SERVER['HTTP_X_CORRELATION_ID'] ?? self::uuidV4();
        }

        return self::$correlationId;
    }

    /** Success envelope: { data, meta } */
    public static function json(mixed $data, int $status = 200): void
    {
        self::sendHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['data' => $data, 'meta' => ['correlation_id' => self::correlationId()]],
            JSON_UNESCAPED_SLASHES
        );
    }

    /** Error envelope: { error: { code, message, fields? }, meta } */
    public static function error(string $code, string $message, int $status, array $fields = []): void
    {
        self::sendHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        echo json_encode(
            ['error' => $error, 'meta' => ['correlation_id' => self::correlationId()]],
            JSON_UNESCAPED_SLASHES
        );
    }

    /** 204 No Content (e.g. successful logout). */
    public static function noContent(): void
    {
        self::sendHeaders();
        http_response_code(204);
    }

    /** Decode and validate the JSON request body of write operations. */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            Http::error('invalid_json', 'Request body is not valid JSON.', 400);
            exit;
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Baseline security headers for API responses. */
    public static function sendHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

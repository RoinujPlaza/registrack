<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * Global error/exception handling. Clients always receive the standard JSON
 * error envelope; detailed diagnostics go to the server log only.
 */
final class ErrorHandler
{
    private static string $logFile = '';

    public static function register(string $logFile): void
    {
        self::$logFile = $logFile;
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_exception_handler([self::class, 'handleException']);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function handleException(\Throwable $e): void
    {
        self::log(sprintf(
            "UNCAUGHT %s: %s in %s:%d\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        if (!headers_sent()) {
            Http::error('internal_error', 'An unexpected error occurred.', 500);
        }
        exit(1);
    }

    public static function log(string $message): void
    {
        $line = sprintf(
            "[%s] [%s] %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            Http::correlationId(),
            $message
        );

        if (self::$logFile !== '') {
            $dir = dirname(self::$logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
        }
    }
}

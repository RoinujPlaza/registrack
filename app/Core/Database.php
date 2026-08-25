<?php

declare(strict_types=1);

namespace RegisTrack\Core;

use PDO;

/**
 * Single PDO connection per request.
 * Fulfils the Singleton entry of the design-pattern matrix at request scope:
 * one shared connection, no global mutable session state.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(Config $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            (string) $config->get('db.host', '127.0.0.1'),
            (string) $config->get('db.port', '3306'),
            (string) $config->get('db.name', 'registrack')
        );

        self::$connection = new PDO(
            $dsn,
            (string) $config->get('db.user', 'root'),
            (string) $config->get('db.password', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        return self::$connection;
    }
}

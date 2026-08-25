<?php

declare(strict_types=1);

namespace RegisTrack\Core;

use PDO;

/**
 * Per-request application context (Singleton in the design-pattern matrix).
 * Holds shared services; controllers/services obtain dependencies from here
 * instead of using globals.
 */
final class AppContext
{
    private static ?self $instance = null;

    private Config $config;

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    public static function init(Config $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('AppContext has not been initialised.');
        }

        return self::$instance;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): PDO
    {
        return Database::connection($this->config);
    }
}

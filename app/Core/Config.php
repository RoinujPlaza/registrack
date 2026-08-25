<?php

declare(strict_types=1);

namespace RegisTrack\Core;

/**
 * Application configuration loader.
 * Values come from config/config.php and may be overridden by environment
 * variables (dot notation -> uppercase underscore, e.g. db.password -> DB_PASSWORD).
 */
final class Config
{
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $configFile): self
    {
        if (!is_file($configFile)) {
            throw new \RuntimeException(
                'Configuration file not found: ' . $configFile
                . '. Copy config/config.example.php to config/config.php.'
            );
        }

        /** @var array $values */
        $values = require $configFile;

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $envKey = strtoupper(str_replace('.', '_', $key));
        $envValue = getenv($envKey);
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }

        $node = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}

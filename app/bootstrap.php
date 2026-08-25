<?php

declare(strict_types=1);

/**
 * REGIS-TRACK bootstrap: autoloader + configuration.
 * Shared by the web front controller (public/index.php) and CLI workers
 * (cron/*) so both always run identical wiring.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'RegisTrack\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

return RegisTrack\Core\Config::load(dirname(__DIR__) . '/config/config.php');

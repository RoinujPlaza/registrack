<?php

/**
 * REGIS-TRACK — Front controller.
 * All HTTP requests enter here. Session hardening, error handling, routing,
 * and authorization are centralized so no endpoint can bypass them.
 */

declare(strict_types=1);

use RegisTrack\Controllers\AdminController;
use RegisTrack\Controllers\AuthController;
use RegisTrack\Core\AppContext;
use RegisTrack\Core\Config;
use RegisTrack\Core\ErrorHandler;
use RegisTrack\Core\Http;
use RegisTrack\Core\Router;

// --- Autoloader (RegisTrack\* -> app/) ---------------------------------------
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

// --- Bootstrap ----------------------------------------------------------------
// Register error handling FIRST so even bootstrap failures return the JSON
// error envelope instead of raw HTML error output. All persistence uses UTC.
ErrorHandler::register(dirname(__DIR__) . '/logs/app.log');
date_default_timezone_set('UTC');

$config = Config::load(dirname(__DIR__) . '/config/config.php');
AppContext::init($config);
ErrorHandler::register((string) $config->get('log.file', dirname(__DIR__) . '/logs/app.log'));

// --- Session hardening (FR1) ---------------------------------------------------
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('REGISTRACK_SESSID');
session_start();

// --- Routes ---------------------------------------------------------------------
$router = new Router();

// Phase 2 — Authentication & RBAC (FR1)
$router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
$router->add('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->add('GET', '/api/v1/me', [AuthController::class, 'me']);
$router->add('POST', '/api/v1/auth/password/reset-request', [AuthController::class, 'requestPasswordReset']);
$router->add('POST', '/api/v1/auth/password/reset', [AuthController::class, 'resetPassword']);
$router->add('GET', '/api/v1/admin/users', [AdminController::class, 'listUsers']);
$router->add('POST', '/api/v1/admin/users', [AdminController::class, 'createUser']);
$router->add('PATCH', '/api/v1/admin/users/{id}', [AdminController::class, 'updateUser']);

// Phase 1 verification endpoint: liveness + database connectivity.
$router->add('GET', '/health', static function (array $params): void {
    $dbStatus = 'down';
    $dbVersion = null;

    try {
        $statement = AppContext::instance()->db()->query('SELECT VERSION() AS v');
        $row = $statement->fetch();
        if (is_array($row)) {
            $dbStatus = 'up';
            $dbVersion = (string) $row['v'];
        }
    } catch (\Throwable $e) {
        ErrorHandler::log('Health check DB failure: ' . $e->getMessage());
    }

    $healthy = $dbStatus === 'up';

    Http::json(
        [
            'status'      => $healthy ? 'ok' : 'degraded',
            'db'          => ['status' => $dbStatus, 'version' => $dbVersion],
            'php_version' => PHP_VERSION,
            'time_utc'    => gmdate('Y-m-d\TH:i:s\Z'),
        ],
        $healthy ? 200 : 503
    );
});

// --- Dispatch ---------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';

$router->dispatch($method, $path);

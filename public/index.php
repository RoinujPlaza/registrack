<?php

/**
 * REGIS-TRACK — Front controller.
 * All HTTP requests enter here. Session hardening, error handling, routing,
 * and authorization are centralized so no endpoint can bypass them.
 */

declare(strict_types=1);

use RegisTrack\Controllers\AdminController;
use RegisTrack\Controllers\AuthController;
use RegisTrack\Controllers\DocumentTypeController;
use RegisTrack\Controllers\NotificationController;
use RegisTrack\Controllers\RequestController;
use RegisTrack\Controllers\StaffController;
use RegisTrack\Core\AppContext;
use RegisTrack\Core\ErrorHandler;
use RegisTrack\Core\Http;
use RegisTrack\Core\Router;

// --- Bootstrap (autoloader + config live in app/bootstrap.php) ----------------
$config = require dirname(__DIR__) . '/app/bootstrap.php';

// Register error handling FIRST so even bootstrap failures return the JSON
// error envelope instead of raw HTML error output. All persistence uses UTC.
ErrorHandler::register(dirname(__DIR__) . '/logs/app.log');
date_default_timezone_set('UTC');

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

// Phase 3 — Request submission (FR2)
$router->add('GET', '/api/v1/document-types', [DocumentTypeController::class, 'list']);
$router->add('POST', '/api/v1/requests', [RequestController::class, 'submit']);
$router->add('GET', '/api/v1/requests/mine', [RequestController::class, 'listMine']);
$router->add('GET', '/api/v1/requests/mine/{trackingNumber}', [RequestController::class, 'getMine']);
$router->add('POST', '/api/v1/requests/mine/{trackingNumber}/cancel', [RequestController::class, 'cancel']);

// Phase 4 — Workflow engine (FR3/FR5): staff queue, detail, transitions
$router->add('GET', '/api/v1/staff/requests', [StaffController::class, 'queue']);
$router->add('GET', '/api/v1/requests/{id}', [StaffController::class, 'detail']);
$router->add('POST', '/api/v1/requests/{id}/transition', [StaffController::class, 'transition']);

// Phase 5 — Notification panel (FR4)
$router->add('GET', '/api/v1/notifications', [NotificationController::class, 'list']);
$router->add('POST', '/api/v1/notifications/{id}/read', [NotificationController::class, 'markRead']);

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

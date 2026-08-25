<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\Http;
use RegisTrack\Repositories\DocumentTypeRepository;
use RegisTrack\Services\RequestService;

/**
 * Student-facing request endpoints (FR2) and the shared document-type list.
 * Ownership is enforced in the service/repository layer: a student can only
 * ever touch rows whose student_id matches the session.
 */
final class RequestController
{
    /** POST /api/v1/requests */
    public static function submit(array $params): void
    {
        $student = Auth::requireRole(['student']);
        Csrf::validate();

        $idempotencyKey = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        $body = Http::jsonBody();

        $result = RequestService::submit($student, $body, $idempotencyKey);
        if ($result['ok']) {
            Http::json($result['data'], $result['replay'] ? 200 : 201);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status'], $result['fields'] ?? []);
    }

    /** GET /api/v1/requests/mine?page=&page_size=&status= */
    public static function listMine(array $params): void
    {
        $student = Auth::requireRole(['student']);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($_GET['page_size'] ?? 20)));
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;

        $result = (new \RegisTrack\Repositories\RequestRepository())
            ->listForStudent((int) $student['id'], $status, $page, $pageSize);

        Http::json([
            'items' => array_map([RequestService::class, 'presentPublic'], $result['items']),
            'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $result['total']],
        ]);
    }

    /** GET /api/v1/requests/mine/{trackingNumber} */
    public static function getMine(array $params): void
    {
        $student = Auth::requireRole(['student']);

        $result = RequestService::detailForStudent($student, (string) $params['trackingNumber']);
        if ($result['ok']) {
            Http::json($result['data']);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status']);
    }

    /** POST /api/v1/requests/mine/{trackingNumber}/cancel */
    public static function cancel(array $params): void
    {
        $student = Auth::requireRole(['student']);
        Csrf::validate();

        $body = Http::jsonBody();
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
        if ($reason !== null && mb_strlen($reason) > 500) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, [
                'reason' => 'Reason must be at most 500 characters.',
            ]);
            return;
        }

        $result = RequestService::cancel($student, (string) $params['trackingNumber'], $reason);
        if ($result['ok']) {
            Http::json($result['data']);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status']);
    }
}

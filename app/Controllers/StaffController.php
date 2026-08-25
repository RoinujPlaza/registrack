<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\Http;
use RegisTrack\Repositories\RequestRepository;
use RegisTrack\Services\RequestService;
use RegisTrack\Services\RequestStateMachine;

/**
 * Registrar staff endpoints (FR3 workflow + queue). Admin shares full access;
 * students have their own scoped endpoints and never reach these.
 */
final class StaffController
{
    /** GET /api/v1/staff/requests?status=&q=&document_type_id=&date_from=&date_to=&page= */
    public static function queue(array $params): void
    {
        Auth::requireRole(['staff', 'admin']);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($_GET['page_size'] ?? 20)));

        $filters = [];
        $status = (string) ($_GET['status'] ?? '');
        if ($status !== '' && RequestStateMachine::isValidStatus($status)) {
            $filters['status'] = $status;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $filters['q'] = $q;
        }
        $typeId = (int) ($_GET['document_type_id'] ?? 0);
        if ($typeId > 0) {
            $filters['document_type_id'] = $typeId;
        }
        foreach (['date_from', 'date_to'] as $dateFilter) {
            $value = trim((string) ($_GET[$dateFilter] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $filters[$dateFilter] = $value;
            }
        }

        $result = (new RequestRepository())->listForStaff($filters, $page, $pageSize);

        Http::json([
            'items' => $result['items'],
            'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $result['total']],
        ]);
    }

    /** GET /api/v1/requests/{id} — staff detail with history + audit. */
    public static function detail(array $params): void
    {
        Auth::requireRole(['staff', 'admin']);

        $id = (int) $params['id'];
        if ($id <= 0) {
            Http::error('not_found', 'Request not found.', 404);
            return;
        }

        $result = RequestService::detailForStaff($id);
        if ($result['ok']) {
            Http::json($result['data']);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status']);
    }

    /** POST /api/v1/requests/{id}/transition — state-machine guarded (FR3). */
    public static function transition(array $params): void
    {
        $actor = Auth::requireRole(['staff', 'admin']);
        Csrf::validate();

        $id = (int) $params['id'];
        if ($id <= 0) {
            Http::error('not_found', 'Request not found.', 404);
            return;
        }

        $body = Http::jsonBody();
        $toStatus = (string) ($body['to'] ?? '');
        $remark = isset($body['remark']) ? trim((string) $body['remark']) : null;
        $version = (int) ($body['version'] ?? 0);

        $fields = [];
        if ($toStatus === '') {
            $fields['to'] = 'Target status is required.';
        }
        if ($version < 1) {
            $fields['version'] = 'The version of the request you viewed is required (concurrency guard).';
        }
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $result = RequestService::transition($actor, $id, $toStatus, $remark, $version);
        if ($result['ok']) {
            Http::json($result['data']);
            return;
        }

        Http::error($result['code'], $result['message'], $result['status'], $result['fields'] ?? []);
    }
}

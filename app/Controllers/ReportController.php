<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Http;
use RegisTrack\Services\AuditService;
use RegisTrack\Services\ReportService;

/**
 * Report endpoints (FR6 / use case Table 13): filtered transaction summary.
 * Staff and Administrator access; CSV downloads are audited (report.exported).
 */
final class ReportController
{
    /** GET /api/v1/admin/reports/summary?from=&to=&status=&document_type_id=&format=json|csv */
    public static function summary(array $params): void
    {
        Auth::requireRole(['staff', 'admin']);

        $input = [
            'from' => isset($_GET['from']) ? (string) $_GET['from'] : null,
            'to' => isset($_GET['to']) ? (string) $_GET['to'] : null,
            'status' => isset($_GET['status']) ? (string) $_GET['status'] : null,
            'document_type_id' => isset($_GET['document_type_id']) ? (int) $_GET['document_type_id'] : null,
        ];

        $result = ReportService::summary($input);
        if (!$result['ok']) {
            Http::error($result['code'], $result['message'], $result['status'], $result['fields'] ?? []);
            return;
        }

        if (($_GET['format'] ?? 'json') === 'csv') {
            AuditService::record('report.exported', 'report', null, null, [
                'format' => 'csv',
                'filters' => $result['summary']['filters'],
            ]);
            Http::csv($result['csv'], 'registrack-report-' . gmdate('Ymd-His') . '.csv');
            return;
        }

        Http::json(['summary' => $result['summary']]);
    }
}

<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use RegisTrack\Repositories\ReportRepository;

/**
 * FR6 / use case Table 13: filtered transaction summary report.
 * JSON for the dashboard (charts/tables), CSV for download, print via the
 * browser. Empty selections return zero values plus an informational message
 * (Figure 14 behaviour) rather than an error.
 */
final class ReportService
{
    /**
     * @param array{from?:string, to?:string, status?:string, document_type_id?:int} $input
     * @return array{ok:bool, summary?:array, csv?:string, status?:int, code?:string, message?:string, fields?:array}
     */
    public static function summary(array $input): array
    {
        $fields = [];
        $timezone = new \DateTimeZone('UTC');
        $today = new \DateTime('today', $timezone);

        // Default range: last 30 days including today.
        // NOTE: compute defaults BEFORE mutating $today — DateTime::modify mutates in place.
        $defaultTo = $today->format('Y-m-d');
        $defaultFrom = $today->modify('-29 days')->format('Y-m-d');
        $from = isset($input['from']) && $input['from'] !== '' ? (string) $input['from'] : $defaultFrom;
        $to = isset($input['to']) && $input['to'] !== '' ? (string) $input['to'] : $defaultTo;

        foreach (['from' => $from, 'to' => $to] as $key => $value) {
            $date = \DateTime::createFromFormat('Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                $fields[$key] = 'Date must use the YYYY-MM-DD format.';
            }
        }
        if ($fields !== []) {
            return self::failure($fields);
        }

        if ($from > $to) {
            $fields['from'] = 'The start date must be on or before the end date.';
            return self::failure($fields);
        }

        $spanDays = (new \DateTime($from))->diff(new \DateTime($to))->days;
        if ($spanDays > ReportRepository::MAX_RANGE_DAYS) {
            $fields['from'] = 'The date range may cover at most ' . ReportRepository::MAX_RANGE_DAYS . ' days.';
            return self::failure($fields);
        }

        $status = isset($input['status']) && $input['status'] !== '' ? (string) $input['status'] : null;
        if ($status !== null && !RequestStateMachine::isValidStatus($status)) {
            $fields['status'] = 'Unknown status filter.';
            return self::failure($fields);
        }

        $repository = new ReportRepository();
        $filter = $repository->buildFilter([
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'document_type_id' => isset($input['document_type_id']) ? (int) $input['document_type_id'] : null,
        ]);

        $byStatus = $repository->countByStatus($filter);
        $byType = $repository->summaryByType($filter);

        // Fill every day of the range with zero values (Figure 14 behaviour).
        $byDay = [];
        $cursor = new \DateTime($from);
        $end = new \DateTime($to);
        while ($cursor <= $end) {
            $byDay[$cursor->format('Y-m-d')] = ['day' => $cursor->format('Y-m-d'), 'submitted' => 0, 'released' => 0];
            $cursor->modify('+1 day');
        }
        foreach ($repository->submissionsByDay($filter) as $row) {
            $byDay[(string) $row['day']]['submitted'] = (int) $row['submitted'];
        }
        foreach ($repository->releasesByDay($filter) as $row) {
            $byDay[(string) $row['day']]['released'] = (int) $row['released'];
        }

        $totalSubmitted = array_sum(array_map(static fn (array $row): int => (int) $row['count'], $byStatus));

        $summary = [
            'filters' => [
                'from' => $from,
                'to' => $to,
                'status' => $status,
                'document_type_id' => $input['document_type_id'] ?? null,
            ],
            'totals' => [
                'submitted' => $totalSubmitted,
                'by_status' => array_column($byStatus, 'count', 'status'),
            ],
            'by_status' => array_map(
                static fn (array $row): array => [
                    'status' => (string) $row['status'],
                    'label' => RequestStateMachine::label((string) $row['status']),
                    'count' => (int) $row['count'],
                ],
                $byStatus
            ),
            'by_document_type' => array_map(
                static fn (array $row): array => [
                    'document_type_id' => (int) $row['document_type_id'],
                    'document_type_name' => (string) $row['document_type_name'],
                    'total' => (int) $row['total'],
                    'pending' => (int) $row['pending'],
                    'needs_information' => (int) $row['needs_information'],
                    'in_process' => (int) $row['in_process'],
                    'ready_for_release' => (int) $row['ready_for_release'],
                    'released' => (int) $row['released'],
                    'rejected' => (int) $row['rejected'],
                    'cancelled' => (int) $row['cancelled'],
                ],
                $byType
            ),
            'by_day' => array_values($byDay),
            'message' => $totalSubmitted === 0
                ? 'No transactions match the selected filters. Try widening the date range or clearing filters.'
                : null,
        ];

        return ['ok' => true, 'summary' => $summary, 'csv' => self::renderCsv($summary)];
    }

    /** RFC 4180 CSV of the per-document-type summary (the downloadable table). */
    public static function renderCsv(array $summary): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV buffer.');
        }

        fputcsv($handle, [
            'document_type', 'total', 'pending', 'needs_information', 'in_process',
            'ready_for_release', 'released', 'rejected', 'cancelled',
        ]);
        foreach ($summary['by_document_type'] as $row) {
            fputcsv($handle, [
                $row['document_type_name'], $row['total'], $row['pending'], $row['needs_information'],
                $row['in_process'], $row['ready_for_release'], $row['released'], $row['rejected'], $row['cancelled'],
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @return array{ok:false, status:int, code:string, message:string, fields:array} */
    private static function failure(array $fields): array
    {
        return [
            'ok' => false,
            'status' => 422,
            'code' => 'validation_failed',
            'message' => 'Please correct the highlighted fields.',
            'fields' => $fields,
        ];
    }
}

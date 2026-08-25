<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use RegisTrack\Core\AppContext;

/**
 * Aggregate queries for the summary report (use case Table 13).
 * All aggregates honour the same filter set: submitted-date range, status,
 * document type. Filters are built centrally so every aggregate stays
 * consistent with the others.
 */
final class ReportRepository
{
    /** Upper bound on report range length — keeps aggregate payloads bounded. */
    public const MAX_RANGE_DAYS = 366;

    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    /** @return array{from:string, to:string, params:array, where:string} */
    public function buildFilter(array $filters): array
    {
        $where = ['r.submitted_at >= ?', 'r.submitted_at < DATE_ADD(?, INTERVAL 1 DAY)'];
        $params = [$filters['from'], $filters['to']];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['document_type_id'])) {
            $where[] = 'r.document_type_id = ?';
            $params[] = $filters['document_type_id'];
        }

        return [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'params' => $params,
            'where' => 'WHERE ' . implode(' AND ', $where),
        ];
    }

    /** @return array<int, array{status:string, count:int}> */
    public function countByStatus(array $filter): array
    {
        $statement = $this->db->prepare(
            "SELECT r.status, COUNT(*) AS count
             FROM requests r
             {$filter['where']}
             GROUP BY r.status
             ORDER BY count DESC"
        );
        $statement->execute($filter['params']);

        return $statement->fetchAll();
    }

    /** Per document type: total plus per-status breakdown. */
    public function summaryByType(array $filter): array
    {
        $statement = $this->db->prepare(
            "SELECT t.id AS document_type_id, t.name AS document_type_name,
                    COUNT(*) AS total,
                    SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN r.status = 'needs_information' THEN 1 ELSE 0 END) AS needs_information,
                    SUM(CASE WHEN r.status = 'in_process' THEN 1 ELSE 0 END) AS in_process,
                    SUM(CASE WHEN r.status = 'ready_for_release' THEN 1 ELSE 0 END) AS ready_for_release,
                    SUM(CASE WHEN r.status = 'released' THEN 1 ELSE 0 END) AS released,
                    SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
             FROM requests r
             JOIN document_types t ON t.id = r.document_type_id
             {$filter['where']}
             GROUP BY t.id, t.name
             ORDER BY total DESC, t.name ASC"
        );
        $statement->execute($filter['params']);

        return $statement->fetchAll();
    }

    /** @return array<int, array{day:string, submitted:int}> submissions per day */
    public function submissionsByDay(array $filter): array
    {
        $statement = $this->db->prepare(
            "SELECT DATE(r.submitted_at) AS day, COUNT(*) AS submitted
             FROM requests r
             {$filter['where']}
             GROUP BY DATE(r.submitted_at)
             ORDER BY day ASC"
        );
        $statement->execute($filter['params']);

        return $statement->fetchAll();
    }

    /** @return array<int, array{day:string, released:int}> releases per day */
    public function releasesByDay(array $filter): array
    {
        $statement = $this->db->prepare(
            "SELECT DATE(r.released_at) AS day, COUNT(*) AS released
             FROM requests r
             {$filter['where']}
               AND r.released_at IS NOT NULL
             GROUP BY DATE(r.released_at)
             ORDER BY day ASC"
        );
        $statement->execute($filter['params']);

        return $statement->fetchAll();
    }
}

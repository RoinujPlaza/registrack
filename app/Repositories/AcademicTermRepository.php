<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use RegisTrack\Core\AppContext;

/**
 * School-year / semester catalogue. Requests are stamped with the term active
 * at submission, so once-per-semester rules stay stable even if term dates are
 * corrected later.
 */
final class AcademicTermRepository
{
    public const SEMESTERS = ['first', 'second', 'summer'];

    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    /** @return array<int, array> newest school year first */
    public function listAll(): array
    {
        $statement = $this->db->query(
            'SELECT id, school_year, semester, starts_on, ends_on, is_current
             FROM academic_terms
             ORDER BY school_year DESC,
                      FIELD(semester, \'first\', \'second\', \'summer\') ASC'
        );

        return $statement->fetchAll();
    }

    public function findCurrent(): ?array
    {
        $statement = $this->db->query(
            'SELECT id, school_year, semester, starts_on, ends_on, is_current
             FROM academic_terms
             WHERE is_current = 1
             ORDER BY starts_on DESC
             LIMIT 1'
        );
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** The term covering one Philippine calendar date (YYYY-MM-DD). */
    public function findForDate(string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, school_year, semester, starts_on, ends_on, is_current
             FROM academic_terms
             WHERE starts_on <= ? AND ends_on >= ?
             ORDER BY starts_on DESC
             LIMIT 1'
        );
        $statement->execute([$date, $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, school_year, semester, starts_on, ends_on, is_current
             FROM academic_terms
             WHERE id = ?
             LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Duplicate guard for the (school_year, semester) unique key. */
    public function findByPeriod(string $schoolYear, string $semester): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, school_year, semester, starts_on, ends_on, is_current
             FROM academic_terms
             WHERE school_year = ? AND semester = ?
             LIMIT 1'
        );
        $statement->execute([$schoolYear, $semester]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array{school_year:string, semester:string, starts_on:string, ends_on:string} $data */
    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO academic_terms (school_year, semester, starts_on, ends_on)
             VALUES (?, ?, ?, ?)'
        );
        $statement->execute([
            $data['school_year'],
            $data['semester'],
            $data['starts_on'],
            $data['ends_on'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array{school_year:string, semester:string, starts_on:string, ends_on:string} $data */
    public function update(int $id, array $data): int
    {
        $statement = $this->db->prepare(
            'UPDATE academic_terms
             SET school_year = ?, semester = ?, starts_on = ?, ends_on = ?
             WHERE id = ?'
        );
        $statement->execute([
            $data['school_year'],
            $data['semester'],
            $data['starts_on'],
            $data['ends_on'],
            $id,
        ]);

        return $statement->rowCount();
    }

    /** Mark one term as the current term; all others are cleared atomically. */
    public function setCurrent(int $id): void
    {
        $db = $this->db;
        $db->beginTransaction();
        try {
            $db->exec('UPDATE academic_terms SET is_current = 0 WHERE is_current = 1');
            $statement = $db->prepare('UPDATE academic_terms SET is_current = 1 WHERE id = ?');
            $statement->execute([$id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function semesterLabel(string $semester): string
    {
        return match ($semester) {
            'first' => '1st Semester',
            'second' => '2nd Semester',
            'summer' => 'Summer',
            default => $semester,
        };
    }

    /** Human label used in staff messages: "1st Semester, S.Y. 2026-2027". */
    public static function label(array $term): string
    {
        return self::semesterLabel((string) $term['semester'])
            . ', S.Y. ' . (string) $term['school_year'];
    }
}

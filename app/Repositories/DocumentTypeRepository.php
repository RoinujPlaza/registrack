<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use PDOStatement;
use RegisTrack\Core\AppContext;

/**
 * All SQL for the document-type catalogue (FR2 dropdown source).
 */
final class DocumentTypeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    /** @return array<int, array> active types, ordered by name */
    public function listActive(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, description FROM document_types WHERE is_active = 1 ORDER BY name'
        );

        return $statement->fetchAll();
    }

    public function findActive(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, description FROM document_types WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}

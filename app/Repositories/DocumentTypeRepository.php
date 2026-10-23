<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use RegisTrack\Core\AppContext;

/**
 * All SQL for the document-type catalogue (FR2 dropdown source) and its
 * institution-configurable metadata and issuance policy.
 */
final class DocumentTypeRepository
{
    /** Fixed picker/admin groups; 'both' is shared by Student and Graduate. */
    public const CATALOG_GROUPS = ['student', 'graduate', 'both', 'cav', 'other'];

    /** Documents carry a quantity; services/forms are one transaction. */
    public const ITEM_TYPES = ['document', 'service'];

    private const SELECT_COLUMNS = 'id, name, description, catalog_group, item_type,
        issuance_policy, policy_note, instructions, sort_order';

    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    /** @return array<int, array> active types, grouped and ordered for the picker */
    public function listActive(): array
    {
        $statement = $this->db->query(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM document_types
             WHERE is_active = 1
             ORDER BY sort_order, name'
        );

        return $statement->fetchAll();
    }

    /** @return array<int, array> every type (admin settings), grouped and ordered */
    public function listAll(): array
    {
        $statement = $this->db->query(
            'SELECT ' . self::SELECT_COLUMNS . ', is_active
             FROM document_types
             ORDER BY catalog_group, sort_order, name'
        );

        return $statement->fetchAll();
    }

    public function findActive(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_COLUMNS . '
             FROM document_types
             WHERE id = ? AND is_active = 1
             LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_COLUMNS . ', is_active
             FROM document_types
             WHERE id = ?
             LIMIT 1'
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findByName(string $name): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_COLUMNS . ', is_active
             FROM document_types
             WHERE name = ?
             LIMIT 1'
        );
        $statement->execute([$name]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Insert a catalogue row.
     *
     * @param array{name:string, description:?string, catalog_group:string, item_type:string,
     *               issuance_policy:string, policy_note:?string, instructions:?string,
     *               sort_order:int, is_active:bool} $data
     */
    public function create(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO document_types
                (name, description, catalog_group, item_type, issuance_policy,
                 policy_note, instructions, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['name'],
            $data['description'],
            $data['catalog_group'],
            $data['item_type'],
            $data['issuance_policy'],
            $data['policy_note'],
            $data['instructions'],
            $data['sort_order'],
            $data['is_active'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update catalogue metadata and issuance policy in one write.
     *
     * @param array{name:string, description:?string, catalog_group:string, item_type:string,
     *               issuance_policy:string, policy_note:?string, instructions:?string,
     *               sort_order:int, is_active:bool} $data
     */
    public function update(int $id, array $data): int
    {
        $statement = $this->db->prepare(
            'UPDATE document_types
             SET name = ?, description = ?, catalog_group = ?, item_type = ?,
                 issuance_policy = ?, policy_note = ?, instructions = ?,
                 sort_order = ?, is_active = ?
             WHERE id = ?'
        );
        $statement->execute([
            $data['name'],
            $data['description'],
            $data['catalog_group'],
            $data['item_type'],
            $data['issuance_policy'],
            $data['policy_note'],
            $data['instructions'],
            $data['sort_order'],
            $data['is_active'] ? 1 : 0,
            $id,
        ]);

        return $statement->rowCount();
    }
}

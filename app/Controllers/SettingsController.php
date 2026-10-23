<?php

declare(strict_types=1);

namespace RegisTrack\Controllers;

use RegisTrack\Core\Auth;
use RegisTrack\Core\Csrf;
use RegisTrack\Core\Http;
use RegisTrack\Repositories\AcademicTermRepository;
use RegisTrack\Repositories\DocumentTypeRepository;
use RegisTrack\Services\AuditService;
use RegisTrack\Services\IssuancePolicyService;

/**
 * Admin settings for academic terms, the document catalogue, and per-document
 * issuance policies. Every write is CSRF-protected, admin-only, and audited.
 */
final class SettingsController
{
    /** Seeded catalogue items required by the demo and issuance policies. */
    private const PROTECTED_DOCUMENT_TYPE_IDS = [1, 2, 3, 4, 5];

    /** GET /api/v1/admin/settings */
    public static function show(array $params): void
    {
        Auth::requireRole(['admin']);

        $terms = (new AcademicTermRepository())->listAll();
        $current = (new AcademicTermRepository())->findCurrent();
        $types = (new DocumentTypeRepository())->listAll();

        Http::json([
            'current_term_id' => $current !== null ? (int) $current['id'] : null,
            'terms' => array_map([self::class, 'presentTerm'], $terms),
            'document_types' => array_map([self::class, 'presentDocumentType'], $types),
            'policies' => array_map(
                static fn (string $policy): array => [
                    'value' => $policy,
                    'label' => IssuancePolicyService::policyLabel($policy),
                ],
                IssuancePolicyService::POLICIES
            ),
            'catalog_groups' => array_map(
                static fn (string $group): array => [
                    'value' => $group,
                    'label' => self::catalogGroupLabel($group),
                ],
                DocumentTypeRepository::CATALOG_GROUPS
            ),
            'item_types' => array_map(
                static fn (string $type): array => [
                    'value' => $type,
                    'label' => self::itemTypeLabel($type),
                ],
                DocumentTypeRepository::ITEM_TYPES
            ),
        ]);
    }

    /** POST /api/v1/admin/terms */
    public static function createTerm(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::validate();

        $body = Http::jsonBody();
        $input = self::termInput($body);
        $fields = self::validateTermInput($input);
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $repository = new AcademicTermRepository();
        if ($repository->findByPeriod($input['school_year'], $input['semester']) !== null) {
            Http::error('duplicate', 'Academic term already exists.', 409, [
                'school_year' => 'A term for this school year and semester already exists.',
            ]);
            return;
        }

        $id = $repository->create($input);
        if (filter_var($body['is_current'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $repository->setCurrent($id);
        }

        $created = $repository->findById($id);
        AuditService::record('term.created', 'term', null, null, $created);

        Http::json(['term' => self::presentTerm($created ?? [])], 201);
    }

    /** PATCH /api/v1/admin/terms/{id} */
    public static function updateTerm(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::validate();

        $id = (int) $params['id'];
        $repository = new AcademicTermRepository();
        $term = $repository->findById($id);
        if ($term === null) {
            Http::error('not_found', 'Academic term not found.', 404);
            return;
        }

        $body = Http::jsonBody();
        $before = self::presentTerm($term);

        if (array_key_exists('is_current', $body) && filter_var($body['is_current'], FILTER_VALIDATE_BOOLEAN)) {
            $repository->setCurrent($id);
            $updated = $repository->findById($id) ?? $term;
            AuditService::record('term.current_changed', 'term', null, $before, self::presentTerm($updated));

            Http::json(['term' => self::presentTerm($updated)]);
            return;
        }

        $merged = [
            'school_year' => array_key_exists('school_year', $body)
                ? trim((string) $body['school_year'])
                : (string) $term['school_year'],
            'semester' => array_key_exists('semester', $body)
                ? trim((string) $body['semester'])
                : (string) $term['semester'],
            'starts_on' => array_key_exists('starts_on', $body)
                ? trim((string) $body['starts_on'])
                : (string) $term['starts_on'],
            'ends_on' => array_key_exists('ends_on', $body)
                ? trim((string) $body['ends_on'])
                : (string) $term['ends_on'],
        ];

        $fields = self::validateTermInput($merged);
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $duplicate = $repository->findByPeriod($merged['school_year'], $merged['semester']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            Http::error('duplicate', 'Academic term already exists.', 409, [
                'school_year' => 'A term for this school year and semester already exists.',
            ]);
            return;
        }

        $repository->update($id, $merged);
        $updated = $repository->findById($id) ?? $term;
        AuditService::record('term.updated', 'term', null, $before, self::presentTerm($updated));

        Http::json(['term' => self::presentTerm($updated)]);
    }

    /** POST /api/v1/admin/document-types */
    public static function createDocumentType(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::validate();

        $body = Http::jsonBody();
        $input = self::documentTypeInput($body, null);
        $fields = self::validateDocumentTypeInput($input);
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $repository = new DocumentTypeRepository();
        if ($repository->findByName($input['name']) !== null) {
            Http::error('duplicate', 'Document type already exists.', 409, [
                'name' => 'A document type with this name already exists.',
            ]);
            return;
        }

        $id = $repository->create($input);
        $created = $repository->findById($id);
        AuditService::record('document_type.created', 'document_type', null, null, self::presentDocumentType($created ?? []));

        Http::json(['document_type' => self::presentDocumentType($created ?? [])], 201);
    }

    /** PATCH /api/v1/admin/document-types/{id} */
    public static function updateDocumentType(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::validate();

        $id = (int) $params['id'];
        $repository = new DocumentTypeRepository();
        $type = $repository->findById($id);
        if ($type === null) {
            Http::error('not_found', 'Document type not found.', 404);
            return;
        }

        $body = Http::jsonBody();
        $input = self::documentTypeInput($body, $type);
        $fields = self::validateDocumentTypeInput($input);
        if ($fields !== []) {
            Http::error('validation_failed', 'Please correct the highlighted fields.', 422, $fields);
            return;
        }

        $duplicate = $repository->findByName($input['name']);
        if ($duplicate !== null && (int) $duplicate['id'] !== $id) {
            Http::error('duplicate', 'Document type already exists.', 409, [
                'name' => 'A document type with this name already exists.',
            ]);
            return;
        }

        $before = self::presentDocumentType($type);
        $repository->update($id, $input);
        $updated = $repository->findById($id) ?? $type;
        AuditService::record('document_type.updated', 'document_type', null, $before, self::presentDocumentType($updated));

        Http::json(['document_type' => self::presentDocumentType($updated)]);
    }

    /** DELETE /api/v1/admin/document-types/{id} */
    public static function deleteDocumentType(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::validate();

        $id = (int) $params['id'];
        $repository = new DocumentTypeRepository();
        $type = $repository->findById($id);
        if ($type === null) {
            Http::error('not_found', 'Document type not found.', 404);
            return;
        }

        if (in_array($id, self::PROTECTED_DOCUMENT_TYPE_IDS, true)) {
            Http::error('conflict', 'Core catalogue items cannot be deleted. Deactivate this item instead.', 409);
            return;
        }

        $used = $repository->countRequests($id);
        if ($used > 0) {
            Http::error('conflict', sprintf(
                'This document type is used by %d request(s). Deactivate it instead.',
                $used
            ), 409);
            return;
        }

        $before = self::presentDocumentType($type);
        $repository->delete($id);
        AuditService::record('document_type.deleted', 'document_type', null, $before, null);

        Http::noContent();
    }

    /** @param array<string, mixed> $row */
    public static function presentTerm(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'school_year' => (string) $row['school_year'],
            'semester' => (string) $row['semester'],
            'semester_label' => AcademicTermRepository::semesterLabel((string) $row['semester']),
            'starts_on' => (string) $row['starts_on'],
            'ends_on' => (string) $row['ends_on'],
            'is_current' => (bool) $row['is_current'],
            'label' => AcademicTermRepository::label($row),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function presentDocumentType(array $row): array
    {
        $policy = (string) ($row['issuance_policy'] ?? 'unlimited');
        $group = (string) ($row['catalog_group'] ?? 'student');
        $itemType = (string) ($row['item_type'] ?? 'document');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'description' => $row['description'] ?? null,
            'catalog_group' => $group,
            'catalog_group_label' => self::catalogGroupLabel($group),
            'item_type' => $itemType,
            'item_type_label' => self::itemTypeLabel($itemType),
            'is_active' => (bool) ($row['is_active'] ?? true),
            'issuance_policy' => $policy,
            'policy_label' => IssuancePolicyService::policyLabel($policy),
            'policy_note' => $row['policy_note'] ?? null,
            'instructions' => $row['instructions'] ?? null,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    public static function catalogGroupLabel(string $group): string
    {
        return [
            'student' => 'Student',
            'graduate' => 'Graduate',
            'both' => 'Student & Graduate',
            'cav' => 'Certification & Authentication',
            'other' => 'Registrar Forms / Other',
        ][$group] ?? $group;
    }

    public static function itemTypeLabel(string $type): string
    {
        return [
            'document' => 'Document',
            'service' => 'Service / Form',
        ][$type] ?? $type;
    }

    /**
     * Merge a PATCH body over the existing row (or use create defaults).
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing
     * @return array{name:string, description:?string, catalog_group:string, item_type:string,
     *               issuance_policy:string, policy_note:?string, instructions:?string,
     *               sort_order:int, is_active:bool}
     */
    private static function documentTypeInput(array $body, ?array $existing): array
    {
        $text = static function (string $key, ?string $fallback = null) use ($body): ?string {
            if (!array_key_exists($key, $body)) {
                return $fallback;
            }
            $value = trim((string) $body[$key]);

            return $value === '' ? null : $value;
        };

        return [
            'name' => array_key_exists('name', $body)
                ? trim((string) $body['name'])
                : (string) ($existing['name'] ?? ''),
            'description' => $text('description', $existing['description'] ?? null),
            'catalog_group' => array_key_exists('catalog_group', $body)
                ? trim((string) $body['catalog_group'])
                : (string) ($existing['catalog_group'] ?? 'student'),
            'item_type' => array_key_exists('item_type', $body)
                ? trim((string) $body['item_type'])
                : (string) ($existing['item_type'] ?? 'document'),
            'issuance_policy' => array_key_exists('issuance_policy', $body)
                ? trim((string) $body['issuance_policy'])
                : (string) ($existing['issuance_policy'] ?? 'unlimited'),
            'policy_note' => $text('policy_note', $existing['policy_note'] ?? null),
            'instructions' => $text('instructions', $existing['instructions'] ?? null),
            'sort_order' => array_key_exists('sort_order', $body)
                ? (int) $body['sort_order']
                : (int) ($existing['sort_order'] ?? 1000),
            'is_active' => array_key_exists('is_active', $body)
                ? filter_var($body['is_active'], FILTER_VALIDATE_BOOLEAN)
                : (bool) ($existing['is_active'] ?? true),
        ];
    }

    /**
     * @param array{name:string, description:?string, catalog_group:string, item_type:string,
     *               issuance_policy:string, policy_note:?string, instructions:?string,
     *               sort_order:int, is_active:bool} $input
     * @return array<string, string>
     */
    private static function validateDocumentTypeInput(array $input): array
    {
        $errors = [];

        $nameLength = mb_strlen($input['name']);
        if ($nameLength < 3 || $nameLength > 120) {
            $errors['name'] = 'Name must be between 3 and 120 characters.';
        }
        if ($input['description'] !== null && mb_strlen($input['description']) > 500) {
            $errors['description'] = 'Description must be at most 500 characters.';
        }
        if (!in_array($input['catalog_group'], DocumentTypeRepository::CATALOG_GROUPS, true)) {
            $errors['catalog_group'] = 'Unknown catalogue group.';
        }
        if (!in_array($input['item_type'], DocumentTypeRepository::ITEM_TYPES, true)) {
            $errors['item_type'] = 'Item type must be document or service.';
        }
        if (!in_array($input['issuance_policy'], IssuancePolicyService::POLICIES, true)) {
            $errors['issuance_policy'] = 'Unknown issuance policy.';
        }
        if ($input['policy_note'] !== null && mb_strlen($input['policy_note']) > 255) {
            $errors['policy_note'] = 'Policy note must be at most 255 characters.';
        }
        if ($input['instructions'] !== null && mb_strlen($input['instructions']) > 500) {
            $errors['instructions'] = 'Instructions must be at most 500 characters.';
        }
        if ($input['sort_order'] < 0 || $input['sort_order'] > 9999) {
            $errors['sort_order'] = 'Sort order must be between 0 and 9999.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{school_year:string, semester:string, starts_on:string, ends_on:string}
     */
    private static function termInput(array $body): array
    {
        return [
            'school_year' => trim((string) ($body['school_year'] ?? '')),
            'semester' => trim((string) ($body['semester'] ?? '')),
            'starts_on' => trim((string) ($body['starts_on'] ?? '')),
            'ends_on' => trim((string) ($body['ends_on'] ?? '')),
        ];
    }

    /**
     * @param array{school_year:string, semester:string, starts_on:string, ends_on:string} $input
     * @return array<string, string> field => error (empty when valid)
     */
    private static function validateTermInput(array $input): array
    {
        $errors = [];

        if (preg_match('/^(\d{4})-(\d{4})$/', $input['school_year'], $matches) !== 1
            || (int) $matches[2] !== (int) $matches[1] + 1) {
            $errors['school_year'] = 'School year must look like 2026-2027.';
        }

        if (!in_array($input['semester'], AcademicTermRepository::SEMESTERS, true)) {
            $errors['semester'] = 'Semester must be first, second, or summer.';
        }

        $dates = [];
        foreach (['starts_on' => 'Start date', 'ends_on' => 'End date'] as $key => $label) {
            $date = \DateTime::createFromFormat('Y-m-d', $input[$key]);
            if ($date === false || $date->format('Y-m-d') !== $input[$key]) {
                $errors[$key] = $label . ' must use the YYYY-MM-DD format.';
            } else {
                $dates[$key] = $date;
            }
        }

        if (isset($dates['starts_on'], $dates['ends_on']) && $dates['ends_on'] <= $dates['starts_on']) {
            $errors['ends_on'] = 'End date must be after the start date.';
        }

        return $errors;
    }
}

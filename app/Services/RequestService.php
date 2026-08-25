<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use PDOException;
use RegisTrack\Core\AppContext;
use RegisTrack\Repositories\AuditRepository;
use RegisTrack\Repositories\DocumentTypeRepository;
use RegisTrack\Repositories\RequestRepository;
use RuntimeException;

/**
 * FR2 business rules: guided submission with validation, idempotent creation,
 * duplicate-window warning (Decision #5), and student cancellation
 * (Decision #8). Every write is atomic: request + history + audit + staff
 * notifications commit together or not at all.
 */
final class RequestService
{
    /**
     * Submit a document request.
     *
     * @param array $student session user (role student)
     * @param array $input   raw request body
     * @param string $idempotencyKey client-generated key for double-submit safety
     * @return array{ok:bool, replay?:bool, data?:array, status?:int, code?:string, message?:string, fields?:array}
     */
    public static function submit(array $student, array $input, string $idempotencyKey): array
    {
        $config = AppContext::instance()->config();
        $maxQuantity = (int) $config->get('requests.max_quantity', 10);
        $duplicateWindowDays = (int) $config->get('requests.duplicate_window_days', 30);

        $typeId = (int) ($input['document_type_id'] ?? 0);
        $quantity = (int) ($input['quantity'] ?? 0);
        $purpose = trim((string) ($input['purpose'] ?? ''));
        $targetDate = trim((string) ($input['target_release_date'] ?? ''));

        // --- Validation (server-side, never trust the client) -------------------
        $fields = [];

        $documentType = $typeId > 0 ? (new DocumentTypeRepository())->findActive($typeId) : null;
        if ($documentType === null) {
            $fields['document_type_id'] = 'Select a valid document type.';
        }

        if ($quantity < 1 || $quantity > $maxQuantity) {
            $fields['quantity'] = "Quantity must be between 1 and $maxQuantity.";
        }

        $purposeLength = mb_strlen($purpose);
        if ($purposeLength < 3 || $purposeLength > 500) {
            $fields['purpose'] = 'Purpose must be between 3 and 500 characters.';
        }

        $dateError = self::validateTargetDate($targetDate);
        if ($dateError !== null) {
            $fields['target_release_date'] = $dateError;
        }

        $keyLength = strlen($idempotencyKey);
        if ($keyLength < 16 || $keyLength > 64) {
            $fields['idempotency_key'] = 'Idempotency key of 16-64 characters is required.';
        }

        if ($fields !== []) {
            return [
                'ok' => false,
                'status' => 422,
                'code' => 'validation_failed',
                'message' => 'Please correct the highlighted fields.',
                'fields' => $fields,
            ];
        }

        $studentId = (int) $student['id'];
        $repository = new RequestRepository();

        // --- Idempotent replay: same key returns the original request -----------
        $existing = $repository->findIdempotent($studentId, $idempotencyKey);
        if ($existing !== null) {
            return [
                'ok' => true,
                'replay' => true,
                'data' => ['request' => self::present($existing), 'warnings' => []],
            ];
        }

        // --- Duplicate window: warn but allow (Decision #5) ----------------------
        $warnings = [];
        $duplicate = $repository->duplicateWithinWindow($studentId, $typeId, $duplicateWindowDays);
        if ($duplicate !== null) {
            $warnings[] = sprintf(
                'You already requested "%s" on %s (%s). Duplicate submissions may delay processing.',
                (string) $documentType['name'],
                (string) $duplicate['submitted_date'],
                (string) $duplicate['tracking_number']
            );
        }

        // --- Atomic write: request + history + audit + staff notifications -------
        $db = AppContext::instance()->db();
        try {
            $db->beginTransaction();

            $trackingNumber = TrackingNumberService::generate($db);
            $requestId = $repository->insertRequest([
                'tracking_number' => $trackingNumber,
                'student_id' => $studentId,
                'document_type_id' => $typeId,
                'quantity' => $quantity,
                'purpose' => $purpose,
                'target_release_date' => $targetDate,
                'idempotency_key' => $idempotencyKey,
            ]);

            $repository->insertHistory([
                'request_id' => $requestId,
                'prev_status' => null,
                'new_status' => 'pending',
                'actor_id' => $studentId,
                'actor_name' => (string) $student['full_name'],
                'actor_role' => 'student',
                'remark' => 'Request submitted.',
            ]);

            AuditService::record('request.submitted', 'request', $requestId, null, [
                'tracking_number' => $trackingNumber,
                'document_type' => (string) $documentType['name'],
                'quantity' => $quantity,
                'target_release_date' => $targetDate,
            ]);

            NotificationService::notifyStaffQueue(
                $requestId,
                "REGIS-TRACK: $trackingNumber",
                sprintf(
                    'New request RT %s: %s (x%d) from %s is pending review.',
                    $trackingNumber,
                    (string) $documentType['name'],
                    $quantity,
                    (string) $student['full_name']
                )
            );

            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Lost a race on the idempotency key: return the winning request.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $winner = $repository->findIdempotent($studentId, $idempotencyKey);
                if ($winner !== null) {
                    return [
                        'ok' => true,
                        'replay' => true,
                        'data' => ['request' => self::present($winner), 'warnings' => []],
                    ];
                }
            }
            throw $e;
        }

        $created = $repository->findById($requestId);
        if ($created === null) {
            throw new RuntimeException('Created request could not be read back.');
        }

        return [
            'ok' => true,
            'replay' => false,
            'data' => ['request' => self::present($created), 'warnings' => $warnings],
        ];
    }

    /**
     * Student cancels their own request while it is still Pending (Decision #8).
     *
     * @return array{ok:bool, data?:array, status?:int, code?:string, message?:string}
     */
    public static function cancel(array $student, string $trackingNumber, ?string $reason): array
    {
        $repository = new RequestRepository();
        $studentId = (int) $student['id'];

        $request = $repository->findByTrackingForStudent($trackingNumber, $studentId);
        if ($request === null) {
            return [
                'ok' => false,
                'status' => 404,
                'code' => 'not_found',
                'message' => 'Request not found.',
            ];
        }

        if ((string) $request['status'] !== 'pending') {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'conflict',
                'message' => 'Only pending requests can be cancelled.',
            ];
        }

        $remark = 'Cancelled by student.' . ($reason !== null && $reason !== '' ? ' Reason: ' . $reason : '');

        $db = AppContext::instance()->db();
        try {
            $db->beginTransaction();

            $updated = $repository->cancelPending($requestId = (int) $request['id'], $studentId, (int) $request['version'], $remark);
            if ($updated === 0) {
                $db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'code' => 'conflict',
                    'message' => 'This request was updated concurrently. Refresh and try again.',
                ];
            }

            $repository->insertHistory([
                'request_id' => $requestId,
                'prev_status' => 'pending',
                'new_status' => 'cancelled',
                'actor_id' => $studentId,
                'actor_name' => (string) $student['full_name'],
                'actor_role' => 'student',
                'remark' => $remark,
            ]);

            AuditService::record('request.cancelled', 'request', $requestId, ['status' => 'pending'], ['status' => 'cancelled']);

            NotificationService::notifyStaffQueue(
                $requestId,
                "REGIS-TRACK: $trackingNumber",
                sprintf('Request %s was cancelled by the student.', $trackingNumber)
            );

            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $fresh = $repository->findById($requestId) ?? $request;

        return ['ok' => true, 'data' => ['request' => self::present($fresh)]];
    }

    /** @return array{ok:bool, data?:array, status?:int, code?:string, message?:string} */
    public static function detailForStudent(array $student, string $trackingNumber): array
    {
        $repository = new RequestRepository();
        $request = $repository->findByTrackingForStudent($trackingNumber, (int) $student['id']);
        if ($request === null) {
            return ['ok' => false, 'status' => 404, 'code' => 'not_found', 'message' => 'Request not found.'];
        }

        return [
            'ok' => true,
            'data' => [
                'request' => self::present($request),
                'history' => $repository->historyForRequest((int) $request['id']),
            ],
        ];
    }

    /** @return string|null error message, or null when the date is acceptable */
    private static function validateTargetDate(string $value): ?string
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return 'Target release date must use the YYYY-MM-DD format.';
        }

        $timezone = new \DateTimeZone((string) AppContext::instance()->config()->get('app.timezone', 'Asia/Manila'));
        $today = new \DateTime('today', $timezone);
        $latest = (new \DateTime('today', $timezone))->add(new \DateInterval('P365D'));

        if ($date < $today) {
            return 'Target release date cannot be in the past.';
        }
        if ($date > $latest) {
            return 'Target release date cannot be more than one year ahead.';
        }

        return null;
    }

    /**
     * Staff/admin status transition (FR3/FR4/FR5 together): state-machine
     * validated, optimistic-concurrency guarded, and written atomically with
     * history + audit + student notification.
     *
     * @param array $actor session user (staff or admin)
     * @return array{ok:bool, data?:array, status?:int, code?:string, message?:string, fields?:array}
     */
    public static function transition(array $actor, int $requestId, string $toStatus, ?string $remark, int $version): array
    {
        $repository = new RequestRepository();
        $request = $repository->findById($requestId);
        if ($request === null) {
            return ['ok' => false, 'status' => 404, 'code' => 'not_found', 'message' => 'Request not found.'];
        }

        $fromStatus = (string) $request['status'];
        $role = (string) $actor['role'];

        $transitionError = RequestStateMachine::assertTransition($fromStatus, $toStatus, $role);
        if ($transitionError !== null) {
            return [
                'ok' => false,
                'status' => 422,
                'code' => 'invalid_transition',
                'message' => $transitionError,
            ];
        }

        $remarkTrimmed = $remark !== null ? trim($remark) : '';
        if (mb_strlen($remarkTrimmed) > 500) {
            return [
                'ok' => false,
                'status' => 422,
                'code' => 'validation_failed',
                'message' => 'Please correct the highlighted fields.',
                'fields' => ['remark' => 'Remark must be at most 500 characters.'],
            ];
        }
        if (RequestStateMachine::reasonRequired($fromStatus, $toStatus) && $remarkTrimmed === '') {
            return [
                'ok' => false,
                'status' => 422,
                'code' => 'validation_failed',
                'message' => 'Please correct the highlighted fields.',
                'fields' => ['remark' => 'A remark explaining the reason is required for this transition.'],
            ];
        }

        $trackingNumber = (string) $request['tracking_number'];
        $historyRemark = $remarkTrimmed !== ''
            ? $remarkTrimmed
            : sprintf('Status changed from %s to %s.', RequestStateMachine::label($fromStatus), RequestStateMachine::label($toStatus));

        $db = AppContext::instance()->db();
        try {
            $db->beginTransaction();

            $updated = $repository->transitionUpdate($requestId, $version, $toStatus, $remarkTrimmed !== '' ? $remarkTrimmed : null);
            if ($updated === 0) {
                $db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'code' => 'conflict',
                    'message' => 'This request was updated by someone else. Refresh the request and try again.',
                ];
            }

            $repository->insertHistory([
                'request_id' => $requestId,
                'prev_status' => $fromStatus,
                'new_status' => $toStatus,
                'actor_id' => (int) $actor['id'],
                'actor_name' => (string) $actor['full_name'],
                'actor_role' => $role,
                'remark' => $historyRemark,
            ]);

            AuditService::record('status.changed', 'request', $requestId, ['status' => $fromStatus], ['status' => $toStatus]);

            NotificationService::notifyUser(
                $requestId,
                (int) $request['student_id'],
                sprintf('REGIS-TRACK: %s is now "%s"', $trackingNumber, RequestStateMachine::label($toStatus)),
                $historyRemark
            );

            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $fresh = $repository->findById($requestId);
        if ($fresh === null) {
            throw new RuntimeException('Transitioned request could not be read back.');
        }

        return ['ok' => true, 'data' => ['request' => self::present($fresh)]];
    }

    /**
     * Staff/admin request detail: full processing history (FR3) plus the
     * append-only audit trail view (FR5).
     *
     * @return array{ok:bool, data?:array, status?:int, code?:string, message?:string}
     */
    public static function detailForStaff(int $requestId): array
    {
        $repository = new RequestRepository();
        $request = $repository->findById($requestId);
        if ($request === null) {
            return ['ok' => false, 'status' => 404, 'code' => 'not_found', 'message' => 'Request not found.'];
        }

        return [
            'ok' => true,
            'data' => [
                'request' => self::present($request),
                'history' => $repository->historyForRequest($requestId),
                'audit' => (new AuditRepository())->listForRequest($requestId),
            ],
        ];
    }

    /** Public projection for list endpoints (delegates to the full projection). */
    public static function presentPublic(array $request): array
    {
        return self::present($request);
    }

    /** Public projection of a request row (no internal fields). */
    private static function present(array $request): array
    {
        return [
            'id' => (int) $request['id'],
            'tracking_number' => (string) $request['tracking_number'],
            'document_type_id' => (int) $request['document_type_id'],
            'document_type_name' => (string) $request['document_type_name'],
            'quantity' => (int) $request['quantity'],
            'purpose' => (string) $request['purpose'],
            'target_release_date' => (string) $request['target_release_date'],
            'status' => (string) $request['status'],
            'current_remark' => $request['current_remark'],
            'version' => (int) $request['version'],
            'submitted_at' => (string) $request['submitted_at'],
            'released_at' => $request['released_at'],
        ];
    }
}

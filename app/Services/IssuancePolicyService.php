<?php

declare(strict_types=1);

namespace RegisTrack\Services;

use RegisTrack\Core\Time;
use RegisTrack\Repositories\AcademicTermRepository;
use RegisTrack\Repositories\DocumentTypeRepository;
use RegisTrack\Repositories\RequestRepository;

/**
 * Issuance limits per document type.
 *
 * Research basis (Philippine HEI practice):
 *  - Honorable Dismissal / Transfer Credential: issued once only.
 *  - Certificate of Graduation: once only.
 *  - Transcript of Records: once only.
 *  - Certificate of Enrollment and Grade Certification: once per semester.
 *
 * The policy is stored per document type so the Registrar can change it without
 * a code change. Enforcement happens server-side on every staff transition;
 * a blocked duplicate may only be rejected. Administrators are read-only.
 */
final class IssuancePolicyService
{
    public const POLICIES = ['unlimited', 'once_per_semester', 'once_per_school_year', 'once_ever'];

    private const BLOCKING_STATUSES = ['released', 'ready_for_release'];
    private const ACTIVE_STATUSES = ['pending', 'in_process', 'needs_information'];

    /** @return array<string, mixed> */
    public static function evaluate(array $request, ?int $excludeRequestId = null): array
    {
        $documentType = self::documentTypeFor($request);
        $policy = (string) ($documentType['issuance_policy'] ?? 'unlimited');

        $base = [
            'policy' => $policy,
            'policy_label' => self::policyLabel($policy),
            'period_label' => null,
            'blocked' => false,
            'warning' => false,
            'message' => null,
            'student_message' => null,
            'prior' => null,
            'active' => null,
        ];

        if ($policy === 'unlimited') {
            return $base;
        }

        $term = self::termFor($request);
        $base['period_label'] = ($policy === 'once_ever' || $term === null)
            ? null
            : AcademicTermRepository::label($term);

        if ($term === null && $policy !== 'once_ever') {
            $base['warning'] = true;
            $base['message'] = 'No academic term covers this request, so the '
                . self::policyLabel($policy) . ' rule cannot be verified. Set the current term in Admin Settings.';
            $base['student_message'] = 'The Registrar has not yet configured the academic term for this request. '
                . 'Staff will verify the ' . self::policyLabel($policy) . ' rule before approval.';

            return $base;
        }

        $priors = (new RequestRepository())->priorRequestsForPolicy(
            (int) $request['student_id'],
            (int) $request['document_type_id'],
            $excludeRequestId ?? (int) ($request['id'] ?? 0)
        );

        $blocking = [];
        $active = [];
        foreach ($priors as $prior) {
            if (!self::matchesPeriod($policy, $term, $prior)) {
                continue;
            }
            if (in_array((string) $prior['status'], self::BLOCKING_STATUSES, true)) {
                $blocking[] = $prior;
            } elseif (in_array((string) $prior['status'], self::ACTIVE_STATUSES, true)) {
                $active[] = $prior;
            }
        }

        $documentName = (string) ($documentType['name'] ?? 'this document');

        if ($blocking !== []) {
            $prior = $blocking[0];
            $base['blocked'] = true;
            $base['prior'] = self::presentPrior($prior);
            $base['message'] = self::blockMessage($documentName, $policy, $base['period_label'], $base['prior'], false);
            $base['student_message'] = self::blockMessage($documentName, $policy, $base['period_label'], $base['prior'], true);

            return $base;
        }

        if ($active !== []) {
            $prior = $active[0];
            $base['warning'] = true;
            $base['active'] = self::presentPrior($prior);
            $base['message'] = self::activeMessage($documentName, $base['period_label'], $base['active'], false);
            $base['student_message'] = self::activeMessage($documentName, $base['period_label'], $base['active'], true);
        }

        return $base;
    }

    public static function policyLabel(string $policy): string
    {
        return match ($policy) {
            'once_per_semester' => 'Once per semester',
            'once_per_school_year' => 'Once per school year',
            'once_ever' => 'Once only',
            default => 'Unlimited',
        };
    }

    /** @return array<string, mixed> */
    private static function documentTypeFor(array $request): array
    {
        if (isset($request['issuance_policy'])) {
            return [
                'name' => $request['document_type_name'] ?? 'this document',
                'issuance_policy' => $request['issuance_policy'],
            ];
        }

        return (new DocumentTypeRepository())->findById((int) $request['document_type_id']) ?? [];
    }

    /** The term a request belongs to: its stamped term, else the date's term. */
    private static function termFor(array $request): ?array
    {
        $repository = new AcademicTermRepository();

        if (!empty($request['term_id'])) {
            $term = $repository->findById((int) $request['term_id']);
            if ($term !== null) {
                return $term;
            }
        }

        $date = Time::manilaDate(isset($request['submitted_at']) ? (string) $request['submitted_at'] : null);

        return $date === null ? null : $repository->findForDate($date);
    }

    /**
     * Does a prior request fall inside the same policy period as the current
     * request? Legacy rows without term_id are matched by submission date.
     */
    private static function matchesPeriod(string $policy, ?array $term, array $prior): bool
    {
        if ($policy === 'once_ever') {
            return true;
        }

        if ($term === null) {
            return false;
        }

        $priorSchoolYear = $prior['school_year'] ?? null;
        $priorSemester = $prior['semester'] ?? null;

        if ($priorSchoolYear === null) {
            $date = Time::manilaDate(isset($prior['submitted_at']) ? (string) $prior['submitted_at'] : null);
            $derived = $date === null ? null : (new AcademicTermRepository())->findForDate($date);
            $priorSchoolYear = $derived['school_year'] ?? null;
            $priorSemester = $derived['semester'] ?? null;
        }

        if ($policy === 'once_per_school_year') {
            return $priorSchoolYear === $term['school_year'];
        }

        return $priorSchoolYear === $term['school_year'] && $priorSemester === $term['semester'];
    }

    /** @return array<string, mixed> */
    private static function presentPrior(array $prior): array
    {
        $term = null;
        if (!empty($prior['school_year'])) {
            $term = [
                'school_year' => (string) $prior['school_year'],
                'semester' => (string) $prior['semester'],
            ];
        }

        return [
            'id' => (int) $prior['id'],
            'tracking_number' => (string) $prior['tracking_number'],
            'status' => (string) $prior['status'],
            'status_label' => RequestStateMachine::label((string) $prior['status']),
            'submitted_at' => Time::toManila(isset($prior['submitted_at']) ? (string) $prior['submitted_at'] : null),
            'released_at' => Time::toManila(isset($prior['released_at']) && $prior['released_at'] !== null
                ? (string) $prior['released_at']
                : null),
            'term_label' => $term !== null ? AcademicTermRepository::label($term) : null,
        ];
    }

    private static function blockMessage(
        string $documentName,
        string $policy,
        ?string $periodLabel,
        array $prior,
        bool $forStudent
    ): string {
        $when = $prior['released_at'] ?? $prior['submitted_at'];
        $date = Time::formatManila($when !== null ? (string) $when : null);
        $who = $forStudent ? 'You already received' : 'This student already received';
        $period = ($periodLabel !== null && $policy !== 'once_ever') ? ' for ' . $periodLabel : '';
        $limit = match ($policy) {
            'once_per_semester' => 'once per semester',
            'once_per_school_year' => 'once per school year',
            default => 'only once',
        };

        return sprintf(
            '%s "%s"%s on %s (%s). This document can be issued %s.',
            $who,
            $documentName,
            $period,
            $date,
            $prior['tracking_number'],
            $limit
        );
    }

    private static function activeMessage(
        string $documentName,
        ?string $periodLabel,
        array $prior,
        bool $forStudent
    ): string {
        $period = $periodLabel !== null ? ' for ' . $periodLabel : '';
        $who = $forStudent ? 'You already have' : 'This student already has';
        $tail = $forStudent
            ? 'Wait for it to be processed before submitting another.'
            : 'Review the earlier request before approving this one.';

        return sprintf(
            '%s an active "%s" request%s (%s, %s). %s',
            $who,
            $documentName,
            $period,
            $prior['tracking_number'],
            $prior['status_label'],
            $tail
        );
    }
}

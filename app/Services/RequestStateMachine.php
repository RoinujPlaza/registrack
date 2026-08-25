<?php

declare(strict_types=1);

namespace RegisTrack\Services;

/**
 * FR3 workflow: the single source of truth for legal status transitions.
 *
 * The four DOCX labels (Pending, In-Process, Ready for Release, Released) are
 * the happy path; needs_information / rejected / cancelled exist because the
 * DOCX's own use cases require reject, hold-for-info, and student-cancel flows
 * (see DECISIONS.md). All rules are enforced server-side here — the UI only
 * mirrors them.
 */
final class RequestStateMachine
{
    /** Human-readable status labels (FR3 display wording). */
    public const LABELS = [
        'pending' => 'Pending',
        'needs_information' => 'Needs Information',
        'in_process' => 'In-Process',
        'ready_for_release' => 'Ready for Release',
        'released' => 'Released',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Legal transitions: from => to => rules.
     * reason: whether a remark is required for the transition.
     * Students never use this machine — they have the dedicated cancel flow.
     */
    private const TRANSITIONS = [
        'pending' => [
            'in_process' => ['reason' => 'optional'],
            'needs_information' => ['reason' => 'required'],
            'rejected' => ['reason' => 'required'],
        ],
        'needs_information' => [
            'pending' => ['reason' => 'optional'],
            'rejected' => ['reason' => 'required'],
        ],
        'in_process' => [
            'ready_for_release' => ['reason' => 'optional'],
            'needs_information' => ['reason' => 'required'],
        ],
        'ready_for_release' => [
            'released' => ['reason' => 'optional'],
        ],
        // released, rejected, cancelled are terminal: no entries.
    ];

    private const TRANSITION_ROLES = ['staff', 'admin'];

    public static function isValidStatus(string $status): bool
    {
        return array_key_exists($status, self::LABELS);
    }

    /**
     * Validate a transition attempt.
     *
     * @return string|null error message, or null when the transition is legal
     */
    public static function assertTransition(string $from, string $to, string $role): ?string
    {
        if (!self::isValidStatus($to)) {
            return 'Unknown target status.';
        }
        if ($from === $to) {
            return 'The request is already in this status.';
        }
        if (!self::isValidStatus($from)) {
            return 'The request is in an unknown status.';
        }

        $rule = self::TRANSITIONS[$from][$to] ?? null;
        if ($rule === null) {
            return sprintf(
                'Transition from "%s" to "%s" is not allowed.',
                self::LABELS[$from],
                self::LABELS[$to]
            );
        }

        if (!in_array($role, self::TRANSITION_ROLES, true)) {
            return 'Your role is not permitted to change request statuses.';
        }

        return null;
    }

    public static function reasonRequired(string $from, string $to): bool
    {
        return (self::TRANSITIONS[$from][$to] ?? [])['reason'] === 'required';
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}

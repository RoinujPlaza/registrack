# REGIS-TRACK — Scope Freeze & Working Decisions

Status: Frozen for implementation (Phase 0). Each decision below is reversible but requires
an explicit change request, because it affects the data model, API contract, or security posture.

Source of truth: `Registrack-Jumawan.docx` (registrar-specific content, lines 1–283).
The activity-diagram narratives in Figures 15–24 describe an unrelated system ("ConsultEase",
faculty consultation appointments) and are **excluded from scope** as contamination. The
mis-titled Figure 8 ("Process Appointment Request") is implemented per its own narrative
(document-request processing).

## Working decisions (defaults adopted pending stakeholder confirmation)

| # | Topic | Decision | Basis |
|---|-------|----------|-------|
| 1 | Hosting | XAMPP for development; InfinityFree only for the graded demo with **anonymized data**; real student data requires an institution-controlled/approved server | DOCX names InfinityFree, but the project's own confidentiality/backup NFRs cannot be met on free shared hosting |
| 2 | Timeline | 16-week Gantt is the build baseline; if the 20-week reading is confirmed, extra weeks go to UAT/pilot, not features | DOCX contradicts itself (16 vs 20) |
| 3 | Credentials | "Institutional credentials" = institutional email + locally stored password; accounts are admin-provisioned (single + CSV import); no SSO (delimitation forbids integration) | Resolves standalone-vs-integration contradiction |
| 4 | Release | Physical pickup at the registrar window; the release transition records releasing staff, timestamp, and optional remarks | DOCX never defines digital delivery |
| 5 | Duplicates | Technical double-submit blocked via idempotency key (Data Integrity NFR); same student + same document type within `duplicate_window_days` (default 30) produces a **warning**, not a block. Exception: document types with an issuance policy (`once_per_semester`, `once_per_school_year`, `once_ever`) are **blocked on every staff transition except Rejected**; staff may only reject a blocked duplicate, while an administrator override requires a written reason and is audited | Legitimate repeat requests must remain possible, while once-only documents follow Philippine HEI practice |
| 6 | Notifications | In-system notifications are authoritative and written in the same DB transaction as the status change; email is best-effort via a cron dispatcher with retry + failure flagging (use case Table 9); channel configurable via `system_settings` | FR4 "depending on the configuration set by the institution" |
| 7 | Records scope | "Manage Records" = student profile/contact data held by REGIS-TRACK itself (name, program, contact). REGIS-TRACK is **not** the authoritative academic-record system; conflict handling = duplicate account/profile detection with admin escalation | Resolves records-management vs no-integration contradiction |
| 8 | Cancellation | Students may cancel their own requests while status = Pending | Required for lifecycle completeness; not forbidden by DOCX |
| 9 | Reports | Filtered tabular summaries + CSV download + browser print. No chart-heavy analytics in v1 | Use case Table 13 satisfied pragmatically |
| 10 | Test data | UAT uses anonymized seed data only | Privacy |
| 11 | Issuance limits | TOR, Certificate of Graduation, and Honorable Dismissal = once only; Certificate of Enrollment and Grade Certification = once per semester. "Once" means one request per period; quantity 1–10 may still be requested. Prior `released` / `ready_for_release` rows block; active `pending` / `in_process` / `needs_information` rows warn only; `cancelled` / `rejected` do not consume an issuance. Policies and terms are admin-configurable. A blocked duplicate can only be rejected by staff; an administrator may override with a written reason, audited as `request.issuance_override`. Staff may reply to the student without a status change; the reply is audited (`request.message_sent`) and delivered as a notification | Philippine HEI practice and registrar policy; prevents duplicate issuance without blocking legitimate repeat requests |
| 12 | Time zone | Storage is UTC (PDO session pinned to `+00:00`); API and UI present Asia/Manila (`+08:00`) with no dependence on the device time zone | Registrar timestamps must reflect Philippine time; avoids the prior double-conversion (+8h) display bug |
| 13 | Queue focus + retention | The staff queue defaults to active requests (pending, needs_information, in_process, ready_for_release), newest first and 10 per page. Status filters expose released/cancelled/rejected or all requests; Older/Newer pagination reaches older rows. Requests are never deleted. The separate Request History page was removed; per-request Processing History and Audit Trail remain. Notifications link to the related request, and opening the panel marks unread in-system notifications as read automatically | Keeps the working queue focused while preserving the full request record for audit and follow-up |
| 14 | Catalogue expansion (Phase 1) | The catalogue has four student-facing groups (student, graduate, cav, other), a `document`/`service` item type, admin-editable name/group/type/policy/active metadata, and a queue group filter. Description, note, and instruction are not shown in the admin table; sort order is hidden and new items default to the end of their group. Existing five document types keep their IDs, names, and issuance policies so historical requests are unchanged. Shared generic TOR and GWA use the `both` group. CAV is four service variants (employment abroad, board exam, apostille, student visa); the eight "other" papers are service items. New issuance defaults are `unlimited` with duplicate warnings, except Permit/Certificate of Continuing (`once_per_semester`) and TOR-HD Transfer/TOR-HD Masteral/Diploma/Certificate of Completion (`once_ever`). Group membership is not an eligibility rule in Phase 1. Permit/Continuing/IHS meanings, TOR policy grouping, graduate eligibility, attachment requirements, and whether the Cancellation Form is enrollment cancellation remain open for stakeholder confirmation | Additive, reversible catalogue expansion that preserves existing request data while making all listed registrar papers requestable; provisional defaults are admin-editable |

## Transaction lifecycle (server-enforced)

Happy path uses the four DOCX labels; the three additional states are **required by the
DOCX's own use cases** (Process Request 3a/3b, escalation, student cancellation):

```
Pending → In-Process → Ready for Release → Released
Pending → Needs Information → Pending   (hold / resubmit)
Pending → Rejected                      (reason required)
Pending → Cancelled                     (student, own request)
Needs Information → Rejected
In-Process → Needs Information
Ready for Release → Released            (releaser + remarks recorded)
```

Rejected, Cancelled, Released are terminal.

## Audit guarantee (honest wording)

"Unalterable" is implemented as: append-only application behavior, same-transaction writes,
and a least-privilege DB user with **no UPDATE/DELETE on audit/history tables**
(`database/grants.sql`). Cryptographic hash chaining is a documented future enhancement.

## Real-time definition

Near-real-time: status changes visible to an open dashboard within ~60 seconds (polling).
Email delivery is asynchronous and has no latency guarantee.

## Excluded from v1 (per delimitation or contamination)

- Payments/fees (delimitation)
- Integration with any SIS/LMS/SSO (delimitation)
- ConsultEase faculty-appointment features (contamination)
- Document file uploads and generated-document delivery (never specified)
- Record merge/delete machinery on academic records (out of authority)

## Environment note (verified 2026-08-25)

XAMPP on the development machine ships MariaDB 10.4.32, not MySQL 8.0 as stated in the DOCX.
The schema and all SQL used by REGIS-TRACK are compatible with both; no action needed,
but deployment targets should be checked against this list: PHP >= 8.0, MariaDB >= 10.4 or MySQL >= 8.0.

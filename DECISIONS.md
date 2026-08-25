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
| 5 | Duplicates | Technical double-submit blocked via idempotency key (Data Integrity NFR); same student + same document type within `duplicate_window_days` (default 30) produces a **warning**, not a block | Legitimate repeat requests must remain possible |
| 6 | Notifications | In-system notifications are authoritative and written in the same DB transaction as the status change; email is best-effort via a cron dispatcher with retry + failure flagging (use case Table 9); channel configurable via `system_settings` | FR4 "depending on the configuration set by the institution" |
| 7 | Records scope | "Manage Records" = student profile/contact data held by REGIS-TRACK itself (name, program, contact). REGIS-TRACK is **not** the authoritative academic-record system; conflict handling = duplicate account/profile detection with admin escalation | Resolves records-management vs no-integration contradiction |
| 8 | Cancellation | Students may cancel their own requests while status = Pending | Required for lifecycle completeness; not forbidden by DOCX |
| 9 | Reports | Filtered tabular summaries + CSV download + browser print. No chart-heavy analytics in v1 | Use case Table 13 satisfied pragmatically |
| 10 | Test data | UAT uses anonymized seed data only | Privacy |

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

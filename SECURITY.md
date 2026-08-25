# REGIS-TRACK — Security Self-Assessment

Status: Phase 7 hardening pass. Supports the DOCX evaluation objective
("evaluate ... security using established software quality criteria").
Each item is either **implemented** (with proof: test suite or script) or
**deferred** (explicitly out of v1 scope, with the reason).

## Implemented

| Control | Implementation | Proof |
|---|---|---|
| Password storage | `password_hash()` bcrypt, rehash-on-login, 8–72 char policy | api-tests (weak password 422) |
| Brute force: per-account | Lockout after 5 failures / 15 min (use case Table 4a) | api-tests (423 on 6th attempt) |
| Brute force: per-IP | Throttle: 20 failures / 10 min → 429, driven by append-only audit data | hardening-tests |
| Session hardening | HttpOnly + SameSite=Lax + Secure-on-HTTPS cookies, regeneration on login, 30-min idle + 8-h absolute lifetime, server-side logout | api-tests (logout 401), hardening-tests (absolute expiry) |
| CSRF | Per-session token, required `X-CSRF-Token` on all authenticated writes | every write in every suite (403 without) |
| SQL injection | PDO prepared statements only; no dynamic SQL from input | all suites |
| Authorization (RBAC) | Central `Auth::requireRole()` + object-level ownership in queries; students scoped to own rows | api/requests/workflow suites (403/404 boundaries) |
| Existence hiding | Foreign tracking numbers/notifications return 404, not 403 | requests/notifications suites |
| Audit integrity | `audit_events` + `request_history` are INSERT-only for the app DB user (`database/grants.sql`); writes happen in the same transaction as business changes | hardening-tests (UPDATE/DELETE/DROP denied) |
| Least-privilege runtime | App connects as `registrack_app` (no DDL, no audit mutations) | hardening-tests (full API pass under restricted user) |
| Security headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `CSP: default-src 'none'`, `Cache-Control: no-store` | Phase 1 verification, every response |
| Error hygiene | Generic client messages, correlation IDs, details only to server log | all suites (no stack traces in responses) |
| Secrets management | `config/config.php` gitignored; env-var overrides supported; no credentials in repo | `.gitignore` + commit history |
| Account enumeration | Generic login errors; password reset always 202 | api-tests |
| Export auditing | Every CSV report download writes `report.exported` with filter snapshot | reports-tests |

## Deferred (documented, not silently missing)

| Control | Reason | Trigger to add |
|---|---|---|
| MFA for staff/admin | No institutional identity provider available (delimitation forbids integration) | When TCGC provides SSO/MFA |
| HTTPS termination | Dev runs plain HTTP via XAMPP; production host must present a valid certificate | Deployment (Phase 8) — block pilot without TLS |
| Audit hash chaining | Shared-host key storage undermines the guarantee; append-only DB privileges chosen instead | Institution-controlled server with protected key storage |
| Dependency scanning | Zero-dependency PHP (no composer packages) shrinks supply-chain surface; PHP runtime patching remains manual | Adopt when any dependency is introduced |
| phpMyAdmin hardening | Dev-only tool; must never be exposed publicly in deployment | Deployment runbook (Phase 8) |
| Rate limiting on all endpoints | Login + write endpoints covered via CSRF/lockout/throttle; read endpoints are low-risk at institutional scale | If abuse observed in pilot |
| Data retention automation | Retention policy pending institutional decision (Open Question from architecture review) | Registrar + DPA review |

## Deployment gates (Phase 8 must enforce)

1. TLS only — no pilot with real student data over plain HTTP.
2. `database/grants.sql` applied; app runs as `registrack_app`, never root.
3. phpMyAdmin unreachable from the public internet.
4. Nightly `database/backup.ps1` scheduled; restore drill executed before go-live.
5. `APP_DEBUG` off; config secrets set via environment, not committed files.

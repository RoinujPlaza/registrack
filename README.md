# REGIS-TRACK — Registrar Transaction Tracking for TCGC

> Web-based registrar transaction tracking for **Tangub City Global College — Office of the College Registrar**. Students submit academic document requests and track them in real time; registrar staff process them through a server-enforced workflow. Every action is captured in an **append-only audit trail**.

![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white) ![MySQL](https://img.shields.io/badge/MySQL%20%7C%20MariaDB-4479A1?logo=mysql&logoColor=white) ![Vanilla JS](https://img.shields.io/badge/Frontend-Vanilla%20JS%20SPA-F7DF1E?logo=javascript&logoColor=black)

**Live repo:** `github.com/RoinujPlaza/registrack` • **Spec source:** `Registrack-Jumawan.docx` • **Decisions:** [`DECISIONS.md`](./DECISIONS.md)

---

## What is this system?

REGIS-TRACK digitizes the TCGC registrar's paper workflow:

* **Students** (`/student`) — request documents and registrar services from a grouped catalogue (TOR variants, certificates, CAV, and registrar forms), track status via a unique **tracking number**, get notified on every state change, cancel while `Pending`.
* **Registrar Staff** (`/staff`) — triage a filtered queue, move requests through a **state machine** guarded by the server, leave remarks/reasons, view immutable history + audit trail.
* **Admin** — read-only oversight of the request queue and details; manage accounts (create, soft-delete/restore; student numbers are auto-generated), configure **catalogue groups/item metadata + academic terms + per-document issuance policies**, generate **summary reports** (JSON/CSV/print), filter by date/status/type/group.
* **System** — enforces workflow rules, prevents double-submit (idempotency key), warns on duplicate requests (30-day window) and blocks repeat issuances for once-only/once-per-term documents, queues dual-channel notifications (in-app + best-effort email via cron), and guarantees audit integrity with a least-privilege DB user (`no UPDATE/DELETE` on audit tables).

### Lifecycle (server-enforced)

```
Pending → In-Process → Ready for Release → Released
Pending → Needs Information → Pending   (hold / resubmit)
Pending → Rejected                      (reason required)
Pending → Cancelled                     (student, own request)
Needs Information → Rejected
In-Process → Needs Information
In-Process → Rejected
Ready for Release → Rejected
Ready for Release → Released
```
`Released / Rejected / Cancelled` are terminal. Every transition writes to `request_history` + `audit_events` in the **same DB transaction**.

---

## Tools used

| Layer | Technology |
|-------|------------|
| Frontend | HTML5, CSS3, **vanilla JavaScript SPA** (no framework) — hash routes `#/login #/student #/staff #/reports #/notifications #/admin/users #/admin/settings` |
| Backend | **PHP 8.4.27+** — front controller (`public/index.php`) + modular monolith (`App/Core`, `Controllers`, `Services`, `Repositories`) |
| Database | **MySQL 8 / MariaDB 10.4+** (XAMPP) — PDO prepared statements, append-only audit |
| Server | Apache (XAMPP prod) or `php -S` built-in server (dev) |
| Cron | `cron/dispatch_notifications.php` — async email dispatcher with retry/exhaustion |
| VCS | Git + GitHub |
| Tests | PowerShell API tests (253 total) |

**Why no framework?** — Mandated by the specification (modular monolith, vanilla SPA).

---

## Project layout

```
registrack/
├── public/                 # Web root — only exposed directory
│   ├── index.php           # Front controller + session hardening + routing
│   ├── index.html          # SPA shell
│   ├── assets/             # styles.css, app.js, background.jpg, icon-128.png
│   └── .htaccess
├── app/
│   ├── Core/               # Config, Database, Router, Http, ErrorHandler, AppContext, Time
│   ├── Controllers/        # HTTP layer: validate + authorize
│   ├── Services/           # Business rules, state machine, notifications, issuance policy
│   └── Repositories/       # All SQL lives here (PDO)
├── config/
│   └── config.example.php  # Copy to config/config.php (gitignored)
├── database/
│   ├── schema.sql          # Full schema — apply as admin user
│   ├── seed.sql            # Anonymized dev/UAT data (password: Password123!)
│   ├── migrations/         # Incremental upgrades for existing databases
│   └── grants.sql          # Least-privilege runtime user
├── cron/
│   └── dispatch_notifications.php
├── tests/                  # PowerShell API suites + run.ps1
├── logs/                   # Runtime log (gitignored)
├── DECISIONS.md            # Scope freeze — read before changing behavior
├── DEPLOYMENT.md           # Production runbook
└── SECURITY.md             # Security checklist
```

---

## How to clone & set up

### Prerequisites

* **Git**, **PHP 8.4.27+** (`php -v`), **MySQL 8 or MariaDB 10.4+** (via [XAMPP](https://www.apachefriends.org/))
* Windows PowerShell (or Git Bash)

### 1. Clone

```powershell
git clone https://github.com/RoinujPlaza/registrack.git
cd registrack
```

### 2. Configure database

Copy the example config and edit credentials:

```powershell
Copy-Item config/config.example.php config/config.php
# then edit config/config.php — DB host/port/name/user/password
```

`config.example.php` defaults to XAMPP (`root` / no password / `registrack` DB). For production, use credentials from `database/grants.sql`.

### 3. Load schema & seed data (one-time)

Start **Apache** + **MySQL** in the XAMPP Control Panel first.

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/schema.sql"
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/seed.sql"
# existing database (do not re-run schema.sql): apply migrations in order
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/migrations/003_issuance_policy.sql"
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/migrations/004_tor_once_ever.sql"
# optional: least-privilege user (production)
& "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/grants.sql"
```

### 4. Run the app (no Apache needed for dev)

```powershell
php -S 127.0.0.1:8090 -t public
```

Open **http://127.0.0.1:8090/** — you should see the TCGC background + centered login card (fits 100vh, no scroll).

Verify health:

```powershell
Invoke-RestMethod http://127.0.0.1:8090/health
# → {"status":"ok","db":{"status":"up","version":"10.4.32-MariaDB"}, ...}
```

For Apache vhost, point `DocumentRoot` at `registrack/public` (a root `.htaccess` also redirects into `public/` if serving from project root).

### 5. Log in — seeded accounts (all passwords `Password123!`)

| Email | Role | Name |
|-------|------|------|
| `admin@tcgc.edu.ph` | Admin | TCGC Registrar Administrator |
| `staff1@tcgc.edu.ph` | Staff | Registrar Staff One |
| `staff2@tcgc.edu.ph` | Staff | Registrar Staff Two |
| `student1@tcgc.edu.ph` | Student | Juan Dela Cruz (2023-00001) |
| `student2@tcgc.edu.ph` | Student | Maria Santos |
| `student3@tcgc.edu.ph` | Student | Pedro Ramos |

Hash routes: `#/login` → `#/student` (students) or `#/staff` (staff/admin) → `#/reports`, `#/notifications`, `#/admin/users`, `#/admin/settings`.

The staff **Request queue** defaults to **Active requests** (Pending, Needs Information,
In-Process, Ready for Release), newest first and 10 per page. Use the status filter to
view Released, Cancelled, Rejected, or all requests; the Older/Newer controls reach older
rows. Requests are never deleted. Opening the **Notifications** page automatically marks
unread in-system notifications as read, and each notification links to its related
request (staff/admin) or to the student's own request detail.

### Catalogue groups and issuance limits

The active catalogue is organised into four student-facing groups:

* **Student** — enrollment/grade papers, permits, undergraduate TOR variants, and transfer credentials.
* **Graduate** — graduate TOR variants, diploma, completion certificate, and Latin-Honors/Civil Service papers.
* **Certification & Authentication (CAV)** — CAV for employment abroad, board exam, apostille, and student visa.
* **Registrar Forms / Other** — change of personal information, tutorial, adding/dropping, withdrawal, cancellation, graduation application, deficiency, and accreditation forms.

Items marked **Student & Graduate** (the generic TOR and GWA) appear in both Student and
Graduate. Documents ask for a quantity; CAV and registrar forms are service transactions
with a fixed quantity of one. Group membership is a display/organisation filter in Phase 1,
not an eligibility check.

The original five document types keep their existing IDs and issuance policies so historical
requests and policy checks are unchanged. New catalogue rows use conservative defaults
(`unlimited` with duplicate warnings) except Permit/Certificate of Continuing
(once per semester) and TOR-HD for Transfer, TOR-HD for Masteral, Diploma, and Certificate
of Completion (once only). Administrators can change any policy, group, type, name, or
active state without deleting historical requests.

| Legacy document | Policy | Enforcement |
|-----------------|--------|-------------|
| Transcript of Records | Once only | Blocked; staff can only reject a duplicate |
| Certificate of Enrollment | Once per semester | Blocked; staff can only reject a duplicate |
| Grade Certification | Once per semester | Blocked; staff can only reject a duplicate |
| Certificate of Graduation | Once only | Blocked; staff can only reject a duplicate |
| Honorable Dismissal | Once only | Blocked; staff can only reject a duplicate |

Admins adjust the policy, catalogue metadata, active state, and school-year/semester
calendar in **Admin → Settings** (`#/admin/settings`). Administrators have read-only
oversight of the request queue and request details; only registrar staff can change a
request's status or message the student. When a request is blocked, staff see only the
**Rejected** action; the rejection reason is prefilled and appears in that request's
processing history. There is no administrator override. Cancelled/rejected requests do
not consume an issuance.

Terminal requests (`Released`, `Rejected`, `Cancelled`) no longer show a duplicate
warning, so an older released request does not point at a newer request. Requests that
were already released before this rule are left unchanged; the rule applies to new
duplicates.

When staff see the duplicate alert, they can also send a prefilled reply to the student
("You already received …") without changing the status. The reply becomes the request's
latest message and is delivered immediately to the student's notification panel; it is
audited as `request.message_sent`.

**Time zone:** the database stores UTC (the PDO session is pinned to `+00:00`), and every
timestamp returned by the API is ISO-8601 Asia/Manila (`+08:00`). The browser also formats
with `timeZone: 'Asia/Manila'`, so the display is correct regardless of device settings.

---

## Running tests

```powershell
# from project root — rebuilds the dev DB, then runs every suite
powershell -ExecutionPolicy Bypass -File tests\run.ps1 -ResetDatabase

# one suite against an already-pristine DB
powershell -ExecutionPolicy Bypass -File tests\timezone-tests.ps1
```

237 API tests across eight PowerShell suites: `timezone`, `issuance`, `api`, `requests`,
`workflow`, `notifications`, `reports`, `hardening`. `-ResetDatabase` is **destructive** —
use it only on a development database. Suites also need MySQL running and `php` on `PATH`.

---

## API overview

Base: `/api/v1` — JSON, `X-CSRF-Token` required for non-GET when authenticated.

* `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`
* `GET /api/v1/document-types`
* `POST /api/v1/requests` (header `Idempotency-Key`), `GET /api/v1/requests/mine`, `GET /api/v1/requests/mine/{tracking}`, `POST /api/v1/requests/mine/{tracking}/cancel`
* `GET /api/v1/staff/requests?active_only=1&q=&status=&document_type_id=&catalog_group=&date_from=&date_to=&page=&page_size=`, `GET /api/v1/requests/{id}` (staff and read-only admin), `POST /api/v1/requests/{id}/transition` (staff only; body `to`, `remark`, `version` — concurrency guard), `POST /api/v1/requests/{id}/message` (staff reply without a status change)
* `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read`, `POST /api/v1/notifications/read-all`
* `GET /api/v1/admin/reports/summary?from=&to=&status=&format=csv`, `GET /api/v1/admin/users?include_disabled=`, `POST /api/v1/admin/users`, `PATCH /api/v1/admin/users/{id}`, `DELETE /api/v1/admin/users/{id}` (soft delete)
* `GET /api/v1/admin/settings`, `POST /api/v1/admin/terms`, `PATCH /api/v1/admin/terms/{id}`, `POST /api/v1/admin/document-types`, `PATCH /api/v1/admin/document-types/{id}`, `DELETE /api/v1/admin/document-types/{id}` (unused custom items only)
* `GET /health`

---

## UI notes

* **Login** — full-viewport background `public/assets/background.jpg` (TCGC seal), sharp (no blur), centered card `400px`, `16px` radius, soft shadow, `100dvh` no-scroll.
* **App shell** — sage wash `#eef3ee` outer, soft cards `16px`, `soft-table` rows (`12px` pill badges — Pending yellow, Ready mint, Released green, Rejected red), `report-toolbar` pills, `pagination` mimic, content gap `1.6rem` (no more “nagtapad”).
* **Sidebar** — pinned bottom profile card (initials + `TCGC Registrar Administrator` + `Dark mode` + `Log out`) like the reference design, `240px` fixed.
* **Request queue** — defaults to active requests, newest first, 10 per page; status and catalogue-group filters expose terminal (Released/Cancelled/Rejected) and all requests, with Older/Newer pagination. Nothing is deleted.
* **Student request form** — group filter plus a grouped type picker; selecting a document shows quantity, purpose, and release date, while CAV/forms switch to details and a preferred appointment date without a quantity field.
* **Admin settings** — edit catalogue name/group/type/policy/active inline, or add a new catalogue item.
* **Notifications** — each notification card opens the related request; opening the page auto-marks unread items as read and clears the sidebar badge immediately.
* **Dark mode** — toggle above Log out, persisted in `localStorage`.

---

## Security notes

* App user from `grants.sql` has **no UPDATE/DELETE on `request_history` / `audit_events`** — basis of FR5 append-only guarantee.
* Session hardening: `HttpOnly`, `SameSite=Lax`, absolute lifetime, per-IP login throttle + lockout.
* Never expose phpMyAdmin publicly; administer DB from localhost/VPN only.
* See `SECURITY.md` checklist + `DECISIONS.md #1` for hosting.

---

## Roadmap

- [x] Phase 0 — scope freeze
- [x] Phase 1 — skeleton
- [x] Phase 2 — auth & RBAC (26/26 tests)
- [x] Phase 3 — request submission (35/35)
- [x] Phase 4 — workflow engine + audit (31/31)
- [x] Phase 5 — notifications + cron dispatcher (34/34)
- [x] Phase 6 — search + reports (38/38)
- [x] Phase 7 — hardening + backup drill (14/14)
- [x] Phase 8 — SPA + deployment runbook (manual smoke)
- [x] Phase 9 — Asia/Manila timestamps + issuance policies (timezone 14/14, issuance 61/61)
- [x] Phase 10 — active queue + clickable notifications + auto-read (237 total)
- [x] Phase 11 — registrar catalogue foundation: groups, CAV/forms, admin CRUD, queue group filter (253 total)

---

## Credits

* App icon: [“Checklist” by Magnific](https://www.flaticon.com/free-icon/checklist_2666469) via Flaticon — free with attribution.
* TCGC background: `public/assets/background.jpg` — Tangub City Global College.



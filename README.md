# REGIS-TRACK — Registrar Transaction Tracking for TCGC

> Web-based registrar transaction tracking for **Tangub City Global College — Office of the College Registrar**. Students submit academic document requests and track them in real time; registrar staff process them through a server-enforced workflow. Every action is captured in an **append-only audit trail**.

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white) ![MySQL](https://img.shields.io/badge/MySQL%20%7C%20MariaDB-4479A1?logo=mysql&logoColor=white) ![Vanilla JS](https://img.shields.io/badge/Frontend-Vanilla%20JS%20SPA-F7DF1E?logo=javascript&logoColor=black) 

**Live repo:** `github.com/RoinujPlaza/registrack` • **Spec source:** `Registrack-Jumawan.docx` • **Decisions:** [`DECISIONS.md`](./DECISIONS.md)

---

## What is this system?

REGIS-TRACK digitizes the TCGC registrar's paper workflow:

* **Students** (`/student`) — request documents (TOR, Certificate of Enrollment, Grade Certification, etc.), track status via a unique **tracking number**, get notified on every state change, cancel while `Pending`.
* **Registrar Staff / Admin** (`/staff`, `/admin/users`) — triage a filtered queue, move requests through a **state machine** guarded by the server, leave remarks/reasons, view immutable history + audit trail.
* **Admin** — manage accounts, generate **summary reports** (JSON/CSV/print), filter by date/status/type.
* **System** — enforces workflow rules, prevents double-submit (idempotency key), warns on duplicate requests (30-day window), queues dual-channel notifications (in-app + best-effort email via cron), and guarantees audit integrity with a least-privilege DB user (`no UPDATE/DELETE` on audit tables).

### Lifecycle (server-enforced)

```
Pending → In-Process → Ready for Release → Released
Pending → Needs Information → Pending   (hold / resubmit)
Pending → Rejected                      (reason required)
Pending → Cancelled                     (student, own request)
Needs Information → Rejected
In-Process → Needs Information
Ready for Release → Released
```
`Released / Rejected / Cancelled` are terminal. Every transition writes to `request_history` + `audit_events` in the **same DB transaction**.

---

## Tools used

| Layer | Technology |
|-------|------------|
| Frontend | HTML5, CSS3, **vanilla JavaScript SPA** (no framework) — hash routes `#/login #/student #/staff #/reports #/notifications #/admin/users` |
| Backend | **PHP 8.x** — front controller (`public/index.php`) + modular monolith (`App/Core`, `Controllers`, `Services`, `Repositories`) |
| Database | **MySQL 8 / MariaDB 10.4+** (XAMPP) — PDO prepared statements, append-only audit |
| Server | Apache (XAMPP prod) or `php -S` built-in server (dev) |
| Cron | `cron/dispatch_notifications.php` — async email dispatcher with retry/exhaustion |
| VCS | Git + GitHub |
| Tests | PHP API tests (164 total) + browser E2E smoke test |

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
│   ├── Core/               # Config, Database, Router, Http, ErrorHandler, AppContext
│   ├── Controllers/        # HTTP layer: validate + authorize
│   ├── Services/           # Business rules, state machine, notifications
│   └── Repositories/       # All SQL lives here (PDO)
├── config/
│   └── config.example.php  # Copy to config/config.php (gitignored)
├── database/
│   ├── schema.sql          # Full schema — apply as admin user
│   ├── seed.sql            # Anonymized dev/UAT data (password: Password123!)
│   └── grants.sql          # Least-privilege runtime user
├── cron/
│   └── dispatch_notifications.php
├── tests/                  # API test suites (Phase 2-7)
├── logs/                   # Runtime log (gitignored)
├── DECISIONS.md            # Scope freeze — read before changing behavior
├── DEPLOYMENT.md           # Production runbook
└── SECURITY.md             # Security checklist
```

---

## How to clone & set up

### Prerequisites

* **Git**, **PHP 8.0+** (`php -v`), **MySQL 8 or MariaDB 10.4+** (via [XAMPP](https://www.apachefriends.org/))
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

Hash routes: `#/login` → `#/student` (students) or `#/staff` (staff/admin) → `#/reports`, `#/notifications`, `#/admin/users`.

---

## Running tests

```powershell
# from project root
php tests/run.php
# or per-phase: php tests/phase3_requests/run.php
```
164 API tests (Phase 2-7) + SPA E2E smoke test. See `tests/` for suites.

---

## API overview

Base: `/api/v1` — JSON, `X-CSRF-Token` required for non-GET when authenticated.

* `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`
* `GET /api/v1/document-types`
* `POST /api/v1/requests` (header `Idempotency-Key`), `GET /api/v1/requests/mine`, `GET /api/v1/requests/mine/{tracking}`, `POST /api/v1/requests/mine/{tracking}/cancel`
* `GET /api/v1/staff/requests?q=&status=&document_type_id=&date_from=&date_to=&page=&page_size=`, `GET /api/v1/requests/{id}`, `POST /api/v1/requests/{id}/transition` (body `to`, `remark`, `version` — concurrency guard)
* `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read`
* `GET /api/v1/admin/reports/summary?from=&to=&status=&format=csv`, `GET /api/v1/admin/users`, `POST /api/v1/admin/users`
* `GET /health`

---

## UI notes

* **Login** — full-viewport background `public/assets/background.jpg` (TCGC seal), sharp (no blur), centered card `400px`, `16px` radius, soft shadow, `100dvh` no-scroll.
* **App shell** — sage wash `#eef3ee` outer, soft cards `16px`, `soft-table` rows (`12px` pill badges — Pending yellow, Ready mint, Released green, Rejected red), `report-toolbar` pills, `pagination` mimic, content gap `1.6rem` (no more “nagtapad”).
* **Sidebar** — pinned bottom profile card (initials + `TCGC Registrar Administrator` + `Dark mode` + `Log out`) like the reference design, `240px` fixed.
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
- [x] Phase 3 — request submission (33/33)
- [x] Phase 4 — workflow engine + audit (31/31)
- [x] Phase 5 — notifications + cron dispatcher (28/28)
- [x] Phase 6 — search + reports (32/32)
- [x] Phase 7 — hardening + backup drill (14/14 — 164 total)
- [x] Phase 8 — SPA + deployment runbook — E2E passed

---

## Credits

* App icon: [“Checklist” by Magnific](https://www.flaticon.com/free-icon/checklist_2666469) via Flaticon — free with attribution.
* TCGC background: `public/assets/background.jpg` — Tangub City Global College.



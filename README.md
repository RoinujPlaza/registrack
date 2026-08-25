# REGIS-TRACK

Web-based registrar transaction tracking system for the TCGC – Office of the
College Registrar. Students submit academic document requests and track them
in real time; registrar staff process them through a server-enforced workflow;
every action is captured in an append-only audit trail.

- Requirements source: `../Registrack-Jumawan.docx`
- Scope freeze and working decisions: `DECISIONS.md` (read before changing behavior)

## Stack (mandated by the specification)

| Layer     | Technology |
|-----------|------------|
| Frontend  | HTML5, CSS3, vanilla JavaScript SPA |
| Backend   | PHP 8.x (front controller + modular monolith) |
| Database  | MySQL 8 / MariaDB via XAMPP |
| Server    | Apache (XAMPP dev) |
| VCS       | Git |

## Project layout

```
registrack/
├── DECISIONS.md            # Phase 0 scope freeze — authoritative working decisions
├── public/                 # Web root (only exposed directory)
│   ├── index.php           # Front controller: session hardening, routing
│   └── .htaccess
├── app/
│   ├── Core/               # Config, Database, Router, Http, ErrorHandler, AppContext
│   ├── Controllers/        # (Phase 2+) HTTP layer: validate + authorize
│   ├── Services/           # (Phase 3+) Business rules, state machine, notifications
│   └── Repositories/       # (Phase 2+) All SQL lives here (PDO prepared statements)
├── config/
│   └── config.example.php  # Copy to config/config.php (gitignored)
├── database/
│   ├── schema.sql          # Full schema — apply as admin user
│   ├── seed.sql            # Anonymized dev/UAT data
│   └── grants.sql          # Least-privilege runtime user (audit tables are INSERT-only)
├── cron/
│   └── dispatch_notifications.php  # Phase 5: async email dispatcher
└── logs/                   # Runtime application log (gitignored)
```

## Local setup (XAMPP)

1. Start **Apache** and **MySQL** from the XAMPP control panel.
2. Copy `config/config.example.php` to `config/config.php` and adjust DB credentials.
3. Load the schema and seed data (PowerShell, one-time):

   ```powershell
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/schema.sql"
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "source C:/opencode/sofeng/registrack/database/seed.sql"
   ```

4. Run the API (dev, no Apache needed):

   ```powershell
   php -S 127.0.0.1:8090 -t public
   ```

5. Verify: `GET http://127.0.0.1:8090/health` → `{"status":"ok", "db":{"status":"up", ...}}`

For Apache serving, point a vhost `DocumentRoot` at `registrack/public`
(a root `.htaccess` fallback redirects into `public/` if serving from the project root).

## Security notes

- The application user in production is created by `database/grants.sql` and has
  **no UPDATE/DELETE on `request_history` / `audit_events`** — the basis of the
  FR5 append-only audit guarantee.
- Never expose phpMyAdmin publicly; administer the DB from localhost/VPN only.
- Real student data must not be loaded outside the approved hosting decision
  (`DECISIONS.md` #1).

## Roadmap status

- [x] Phase 0 — scope freeze, decisions, exclusions
- [x] Phase 1 — skeleton: front controller, router, config, error handling, schema, seed
- [x] Phase 2 — authentication & RBAC (login, lockout, sessions, user admin) — 26/26 API tests passing
- [ ] Phase 3 — request submission (FR2) with idempotency
- [ ] Phase 4 — workflow engine (FR3/FR5): state machine, history, audit, concurrency
- [ ] Phase 5 — notifications (FR4): in-system + cron email dispatcher
- [ ] Phase 6 — search/filter (FR6) + reports
- [ ] Phase 7 — hardening (security checklist, backup/restore drill)
- [ ] Phase 8 — UAT + deployment

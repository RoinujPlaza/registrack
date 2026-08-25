# REGIS-TRACK — Deployment Runbook

Read `DECISIONS.md` #1 first: **InfinityFree is demo-only with anonymized data;
real student records require an institution-controlled/approved server.**

## A. Graded demo (InfinityFree or XAMPP demo machine)

Data rule: **anonymized seed data only** (`database/seed.sql` — fictional names).

1. Upload the repository (or via Git) — on InfinityFree point the domain's
   document root at `public/` if possible; otherwise rely on the root
   `.htaccess` funnel into `public/`.
2. Create the MySQL database via the host panel (note host/name/user).
3. Import `database/schema.sql` then `database/seed.sql` (phpMyAdmin import).
4. Create `config/config.php` from the example with the host's DB credentials.
5. Set `system_settings.notification_email_enabled = '0'` (in-system only —
   free hosts typically block outbound SMTP).
6. Smoke test: open `/` → login page; log in as `admin@tcg.edu.ph`;
   `/health` returns `"status":"ok"`.

## B. Pilot with real data (approved institutional host) — gates

All five gates in `SECURITY.md` are **mandatory**:

1. **TLS only** — valid certificate; HTTP redirects to HTTPS; app sets the
   session cookie `Secure` automatically when HTTPS is detected.
2. **Least privilege** — apply `database/grants.sql` (set strong passwords),
   point `config.php` at `registrack_app`; never run the app as root DB user.
3. **phpMyAdmin** unreachable from the public internet (localhost/VPN only).
4. **Backups** — schedule `database/backup.ps1` (or an equivalent cron
   `mysqldump --single-transaction --databases registrack`) nightly; run
   `database/restore.ps1` once before go-live and keep the drill result.
5. **Debug off** — `app.debug = false`; secrets via environment variables.

### Pilot go-live steps

1. Provision PHP 8.x + MySQL 8/MariaDB 10.4+ host; document root → `public/`.
2. Import `schema.sql` (fresh) — do **not** import `seed.sql` (fictional data).
3. Create the first admin: insert manually via SQL using a bcrypt hash
   (`php -r "echo password_hash('...', PASSWORD_DEFAULT);"`) or run the app
   with seed, promote a real account, then delete seed accounts.
4. Configure `notifications` (institutional SMTP relay) and set
   `notification_email_enabled='1'` only after a successful test send.
5. Schedule the email dispatcher: cron `*/5 * * * * php .../cron/dispatch_notifications.php`
   (or trigger manually if the host has no cron).
6. Schedule nightly backup; verify the first backup restores.
7. Run the six test suites once against staging data before opening to users.

## Rollback

- App code: keep the previous release directory; switch the vhost back.
- Database: `database/restore.ps1 -BackupFile <dump>` (verified drill exists).

## Known operational notes

- XAMPP dev ships MariaDB 10.4 (DOCX says MySQL 8) — schema is compatible
  with both; verify the host version matches the list in `DECISIONS.md`.
- "Real-time" = near-real-time: dashboards poll while open; email is async
  via cron (no latency guarantee).
- Tracking numbers are opaque and random; never expose sequential IDs.

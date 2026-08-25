-- =============================================================================
-- REGIS-TRACK — Least-privilege database users (apply in production/pilot).
-- Local XAMPP development may use root; deployment must use these accounts.
--
-- Key property: the application user has NO UPDATE/DELETE on the append-only
-- tables (request_history, audit_events). This is the technical basis of the
-- FR5 "unalterable" audit guarantee.
-- =============================================================================

-- Application runtime user -----------------------------------------------------
CREATE USER IF NOT EXISTS 'registrack_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG';

GRANT SELECT, INSERT, UPDATE ON registrack.users            TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.document_types   TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.requests         TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT         ON registrack.request_history  TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT         ON registrack.audit_events     TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.notifications    TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.password_resets  TO 'registrack_app'@'localhost';
GRANT SELECT, UPDATE         ON registrack.system_settings   TO 'registrack_app'@'localhost';

-- Migration/administrative user (schema changes only, run manually) ------------
CREATE USER IF NOT EXISTS 'registrack_migrator'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG';
GRANT ALL PRIVILEGES ON registrack.* TO 'registrack_migrator'@'localhost';

FLUSH PRIVILEGES;

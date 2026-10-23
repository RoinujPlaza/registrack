-- Migration 003 - timestamp normalisation and per-document issuance policy
-- Run once against an existing database (schema.sql already contains these
-- objects for fresh installs). Apply with an administrative MySQL user.
--
-- Storage remains UTC; the application pins its PDO session to +00:00 and
-- converts to Asia/Manila only when presenting values. The CONVERT_TZ calls
-- below use the fixed +08:00 offset (the Philippines has no daylight saving).

USE registrack;

-- 1. Document-type issuance policy -------------------------------------------
ALTER TABLE document_types
    ADD COLUMN issuance_policy ENUM('unlimited','once_per_semester','once_per_school_year','once_ever')
        NOT NULL DEFAULT 'unlimited' AFTER description,
    ADD COLUMN policy_note VARCHAR(255) NULL AFTER issuance_policy;

-- 2. Academic terms -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS academic_terms (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_year VARCHAR(9)   NOT NULL,
    semester    ENUM('first','second','summer') NOT NULL,
    starts_on   DATE         NOT NULL,
    ends_on     DATE         NOT NULL,
    is_current  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_academic_terms_period UNIQUE (school_year, semester),
    CONSTRAINT chk_academic_terms_dates CHECK (ends_on > starts_on),
    INDEX idx_academic_terms_dates (starts_on, ends_on),
    INDEX idx_academic_terms_current (is_current)
) ENGINE = InnoDB;

-- 3. Request -> term stamp ----------------------------------------------------
ALTER TABLE requests
    ADD COLUMN term_id INT UNSIGNED NULL AFTER document_type_id,
    ADD CONSTRAINT fk_requests_term FOREIGN KEY (term_id) REFERENCES academic_terms(id),
    ADD INDEX idx_requests_term (term_id);

-- 4. Institution policy values (aligned with schema.sql / seed.sql) -----------
UPDATE document_types
SET issuance_policy = 'once_ever',
    policy_note = 'Issued once only.'
WHERE name = 'Transcript of Records (TOR)';

UPDATE document_types
SET issuance_policy = 'once_per_semester',
    policy_note = 'Issued once per semester.'
WHERE name IN ('Certificate of Enrollment', 'Grade Certification');

UPDATE document_types
SET issuance_policy = 'once_ever',
    policy_note = 'Issued once only.'
WHERE name IN ('Certificate of Graduation', 'Honorable Dismissal');

-- 5. Term catalogue (adjust dates to the institution's official calendar) -----
INSERT INTO academic_terms (school_year, semester, starts_on, ends_on, is_current) VALUES
    ('2025-2026', 'first',  '2025-08-04', '2025-12-19', 0),
    ('2025-2026', 'second', '2026-01-12', '2026-05-22', 0),
    ('2025-2026', 'summer', '2026-06-01', '2026-07-10', 0),
    ('2026-2027', 'first',  '2026-08-03', '2026-12-18', 1),
    ('2026-2027', 'second', '2027-01-11', '2027-05-21', 0),
    ('2026-2027', 'summer', '2027-06-01', '2027-07-09', 0)
ON DUPLICATE KEY UPDATE
    starts_on = VALUES(starts_on),
    ends_on = VALUES(ends_on),
    is_current = VALUES(is_current);

-- 6. Backfill term_id for existing requests using the Philippine calendar date.
UPDATE requests r
JOIN academic_terms t
    ON DATE(CONVERT_TZ(r.submitted_at, '+00:00', '+08:00'))
       BETWEEN t.starts_on AND t.ends_on
SET r.term_id = t.id
WHERE r.term_id IS NULL;

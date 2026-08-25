-- =============================================================================
-- REGIS-TRACK — Database schema v1.0
-- Target: MySQL 8.0+ / MariaDB 10.4+ (XAMPP), InnoDB, utf8mb4
-- Apply as an administrative user (root in local dev). The runtime application
-- connects through the least-privilege user defined in grants.sql.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS registrack
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE registrack;

-- -----------------------------------------------------------------------------
-- D1 — Users (identity + role; RBAC anchor)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    email           VARCHAR(190)     NOT NULL,
    password_hash   VARCHAR(255)     NOT NULL,
    role            ENUM('student','staff','admin') NOT NULL,
    status          ENUM('active','disabled')       NOT NULL DEFAULT 'active',
    student_number  VARCHAR(32)      NULL,
    full_name       VARCHAR(150)     NOT NULL,
    program         VARCHAR(150)     NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME         NULL,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_student_number UNIQUE (student_number),
    CONSTRAINT chk_users_student_number
        CHECK (role <> 'student' OR (student_number IS NOT NULL AND student_number <> ''))
) ENGINE = InnoDB;

-- -----------------------------------------------------------------------------
-- Document type catalogue (admin-configurable)
-- -----------------------------------------------------------------------------
CREATE TABLE document_types (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)  NOT NULL,
    description VARCHAR(500)  NULL,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_document_types_name UNIQUE (name)
) ENGINE = InnoDB;

-- -----------------------------------------------------------------------------
-- D2 — Requests (transaction core; current status is a query-optimizing copy —
--       the authoritative progression lives in request_history)
-- -----------------------------------------------------------------------------
CREATE TABLE requests (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tracking_number     VARCHAR(20)     NOT NULL,                 -- RT-YYYYMMDD-XXXXXX
    student_id          INT UNSIGNED    NOT NULL,
    document_type_id    INT UNSIGNED    NOT NULL,
    quantity            SMALLINT UNSIGNED NOT NULL,
    purpose             VARCHAR(500)    NOT NULL,
    target_release_date DATE            NOT NULL,
    status              ENUM('pending','needs_information','in_process',
                             'ready_for_release','released','rejected','cancelled')
                        NOT NULL DEFAULT 'pending',
    current_remark      VARCHAR(500)    NULL,
    assigned_to         INT UNSIGNED    NULL,                     -- optional claim
    version             INT UNSIGNED    NOT NULL DEFAULT 1,       -- optimistic concurrency
    idempotency_key     CHAR(36)        NOT NULL,                 -- double-submit guard
    submitted_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at         DATETIME        NULL,
    PRIMARY KEY (id),
    CONSTRAINT uq_requests_tracking_number UNIQUE (tracking_number),
    CONSTRAINT uq_requests_idempotency UNIQUE (student_id, idempotency_key),
    CONSTRAINT fk_requests_student      FOREIGN KEY (student_id)       REFERENCES users(id),
    CONSTRAINT fk_requests_doc_type     FOREIGN KEY (document_type_id) REFERENCES document_types(id),
    CONSTRAINT fk_requests_assigned_to  FOREIGN KEY (assigned_to)      REFERENCES users(id),
    CONSTRAINT chk_requests_quantity CHECK (quantity > 0),
    INDEX idx_requests_status_submitted (status, submitted_at),
    INDEX idx_requests_student (student_id),
    INDEX idx_requests_doc_type (document_type_id),
    INDEX idx_requests_target_date (target_release_date)
) ENGINE = InnoDB;

-- -----------------------------------------------------------------------------
-- Processing history (FR3) — append-only. Actor name/role are snapshots so
-- history survives later renames or deactivations.
-- -----------------------------------------------------------------------------
CREATE TABLE request_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id  BIGINT UNSIGNED NOT NULL,
    prev_status ENUM('pending','needs_information','in_process',
                     'ready_for_release','released','rejected','cancelled') NULL,
    new_status  ENUM('pending','needs_information','in_process',
                     'ready_for_release','released','rejected','cancelled') NOT NULL,
    actor_id    INT UNSIGNED    NULL,                -- NULL = system action
    actor_name  VARCHAR(150)    NOT NULL,
    actor_role  VARCHAR(20)     NOT NULL,
    remark      VARCHAR(500)    NULL,
    created_at  TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    CONSTRAINT fk_history_request FOREIGN KEY (request_id) REFERENCES requests(id),
    INDEX idx_history_request (request_id, created_at)
) ENGINE = InnoDB;

-- -----------------------------------------------------------------------------
-- D3 — Audit events (FR5) — append-only. No UPDATE/DELETE privilege is granted
--      to the application user (see grants.sql).
-- -----------------------------------------------------------------------------
CREATE TABLE audit_events (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id     BIGINT UNSIGNED NULL,
    actor_id       INT UNSIGNED    NULL,
    actor_name     VARCHAR(150)    NOT NULL,
    actor_role     VARCHAR(20)     NOT NULL,
    action         VARCHAR(64)     NOT NULL,          -- e.g. request.submitted, status.changed
    entity         VARCHAR(64)     NOT NULL,          -- e.g. request, user, document_type
    before_value   JSON            NULL,
    after_value    JSON            NULL,
    ip_hash        CHAR(64)        NULL,              -- sha256 of client IP (privacy-preserving)
    correlation_id CHAR(36)        NULL,
    created_at     TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    CONSTRAINT fk_audit_request FOREIGN KEY (request_id) REFERENCES requests(id),
    INDEX idx_audit_request (request_id),
    INDEX idx_audit_created (created_at)
) ENGINE = InnoDB;

-- -----------------------------------------------------------------------------
-- Notifications (FR4) — in-system rows are authoritative; email rows are
-- dispatched asynchronously by cron/dispatch_notifications.php with retry.
-- -----------------------------------------------------------------------------
CREATE TABLE notifications (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id      BIGINT UNSIGNED NULL,
    recipient_id    INT UNSIGNED    NOT NULL,
    channel         ENUM('in_system','email')     NOT NULL DEFAULT 'in_system',
    delivery_status ENUM('created','sent','failed','exhausted') NOT NULL DEFAULT 'created',
    subject         VARCHAR(200)    NOT NULL,
    body            TEXT            NOT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME        NULL,
    last_error      VARCHAR(500)    NULL,
    read_at         DATETIME        NULL,
    created_at      TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    CONSTRAINT fk_notifications_request   FOREIGN KEY (request_id)   REFERENCES requests(id),
    CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users(id),
    INDEX idx_notifications_recipient (recipient_id, created_at),
    INDEX idx_notifications_dispatch (channel, delivery_status)
) ENGINE = InnoDB;

-- -----------------------------------------------------------------------------
-- Institution-configurable behaviour (FR4 channel toggle, duplicate window, …)
-- -----------------------------------------------------------------------------
CREATE TABLE system_settings (
    setting_key   VARCHAR(64)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE = InnoDB;

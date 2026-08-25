-- Migration 002 — password reset tokens (Phase 2: Auth & RBAC)
-- Tokens are stored hashed; the raw token only ever exists in the reset link.

USE registrack;

CREATE TABLE IF NOT EXISTS password_resets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED    NOT NULL,
    token_hash CHAR(64)        NOT NULL,              -- sha256 of raw token
    expires_at DATETIME        NOT NULL,
    used_at    DATETIME        NULL,
    created_at TIMESTAMP(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    CONSTRAINT uq_password_resets_token UNIQUE (token_hash),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_password_resets_user (user_id)
) ENGINE = InnoDB;

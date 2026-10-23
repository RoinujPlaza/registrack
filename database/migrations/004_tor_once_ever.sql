-- Migration 004 - Transcript of Records is issued once only
-- Run once against an existing database that has already applied migration 003.
-- Fresh installs get this value from schema.sql/seed.sql; this migration aligns
-- databases that were created while TOR was still unlimited.

USE registrack;

UPDATE document_types
SET issuance_policy = 'once_ever',
    policy_note = 'Issued once only.'
WHERE name = 'Transcript of Records (TOR)';

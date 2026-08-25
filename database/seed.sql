-- =============================================================================
-- REGIS-TRACK — Seed data (development/UAT only; anonymized per Decision #10)
-- Seed password for ALL accounts below: Password123!
-- (bcrypt hash generated via password_hash(); rotate before any real use)
-- =============================================================================

USE registrack;

-- Document types (FR2 dropdown source) -----------------------------------------
INSERT INTO document_types (name, description, is_active) VALUES
    ('Transcript of Records (TOR)',      'Complete official academic transcript.', 1),
    ('Certificate of Enrollment',        'Certification of current enrollment status.', 1),
    ('Grade Certification',              'Certified copy of grades for a term.', 1),
    ('Certificate of Graduation',        'Certification that a student completed a program.', 1),
    ('Honorable Dismissal',              'Transfer credential for transferring students.', 1);

-- Accounts ----------------------------------------------------------------------
INSERT INTO users (email, password_hash, role, status, student_number, full_name, program) VALUES
    ('admin@tcg.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'admin',  'active', NULL, 'TCGC Registrar Administrator', NULL),
    ('staff1@tcg.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'staff',  'active', NULL, 'Registrar Staff One', NULL),
    ('staff2@tcg.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'staff',  'active', NULL, 'Registrar Staff Two', NULL),
    ('student1@tcg.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'student', 'active', '2023-00001', 'Juan Dela Cruz', 'BS Information Technology'),
    ('student2@tcg.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'student', 'active', '2023-00002', 'Maria Santos', 'BS Education'),
    ('student3@tcg.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'student', 'active', '2022-00003', 'Pedro Ramos', 'BS Business Administration');

-- Institution-configurable settings (FR4 channel toggle + business rules) -------
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('notification_email_enabled', '0'),
    ('duplicate_window_days',      '30'),
    ('release_mode',               'pickup'),
    ('cron_last_run_at',           '');

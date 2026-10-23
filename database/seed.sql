-- =============================================================================
-- REGIS-TRACK — Seed data (development/UAT only; anonymized per Decision #10)
-- Seed password for ALL accounts below: Password123!
-- (bcrypt hash generated via password_hash(); rotate before any real use)
-- =============================================================================

USE registrack;

-- Document types (FR2 dropdown source) -----------------------------------------
-- Four catalogue groups: student, graduate, both (shared by Student/Graduate),
-- cav (Certification & Authentication), and other (registrar forms/services).
-- The first five rows keep their historical IDs and issuance policies. New rows
-- start with conservative, admin-editable defaults.
INSERT INTO document_types
    (name, description, catalog_group, item_type, issuance_policy, policy_note, instructions, sort_order, is_active)
VALUES
    ('Transcript of Records (TOR)', 'Complete official academic transcript.',
     'both', 'document', 'once_ever', 'Issued once only.',
     'State the recipient and purpose.', 10, 1),
    ('Certificate of Enrollment', 'Certification of current enrollment status.',
     'student', 'document', 'once_per_semester', 'Issued once per semester.',
     'State the term and purpose.', 20, 1),
    ('Grade Certification', 'Certified copy of grades for a term.',
     'student', 'document', 'once_per_semester', 'Issued once per semester.',
     'State the term and purpose.', 30, 1),
    ('Certificate of Graduation', 'Certification that a student completed a program.',
     'graduate', 'document', 'once_ever', 'Issued once only.',
     'State the program and purpose.', 80, 1),
    ('Honorable Dismissal', 'Transfer credential for transferring students.',
     'student', 'document', 'once_ever', 'Issued once only.',
     'State the receiving school and purpose.', 70, 1),
    ('General Weighted Average (GWA)', 'Certification of the student''s general weighted average.',
     'both', 'document', 'unlimited', 'Can be requested as needed.',
     'State the period covered and purpose.', 40, 1),
    ('Permit', 'Registrar permit for the current term.',
     'student', 'document', 'once_per_semester', 'Issued once per semester.',
     'State the permit type, term, and purpose.', 50, 1),
    ('Certificate of Continuing', 'Certification of continuing enrollment.',
     'student', 'document', 'once_per_semester', 'Issued once per semester.',
     'State the term and purpose.', 60, 1),
    ('TOR for Employment (Undergraduate)', 'Transcript of Records for employment purposes (undergraduate).',
     'student', 'document', 'unlimited', 'Can be requested as needed.',
     'State the employer or recipient and purpose.', 15, 1),
    ('TOR-HD for Transfer', 'Transcript of Records with Honorable Dismissal for transfer.',
     'student', 'document', 'once_ever', 'Issued once only for transfer.',
     'State the receiving school and program.', 65, 1),
    ('TOR for Employment (Graduate)', 'Transcript of Records for employment purposes (graduate).',
     'graduate', 'document', 'unlimited', 'Can be requested as needed.',
     'State the employer or recipient and purpose.', 15, 1),
    ('TOR for Civil Service Eligibility (Latin Honors)', 'TOR for Civil Service Eligibility with Latin Honors notation.',
     'graduate', 'document', 'unlimited', 'Can be requested as needed.',
     'State the eligibility application and purpose.', 25, 1),
    ('TOR for Board Exam (Education, IHS, Crim)', 'TOR for board examination applications in Education, IHS, or Criminology.',
     'graduate', 'document', 'unlimited', 'Can be requested as needed.',
     'State the board examination and program.', 30, 1),
    ('TOR-HD for Masteral', 'Transcript of Records with Honorable Dismissal for masteral studies.',
     'graduate', 'document', 'once_ever', 'Issued once only for transfer to graduate studies.',
     'State the receiving university and graduate program.', 65, 1),
    ('Certificate of Completion', 'Certification that the graduate completed the program requirements.',
     'graduate', 'document', 'once_ever', 'Issued once only.',
     'State the program and purpose.', 85, 1),
    ('Diploma', 'Official diploma for a completed graduate program.',
     'graduate', 'document', 'once_ever', 'Issued once only.',
     'State the program and purpose.', 90, 1),
    ('CAV for Employment Abroad', 'Certification, authentication, and verification for employment abroad.',
     'cav', 'service', 'unlimited', 'Can be requested as needed.',
     'State the destination country, employer, and documents to verify.', 10, 1),
    ('CAV for Board Exam', 'Certification, authentication, and verification for board examination requirements.',
     'cav', 'service', 'unlimited', 'Can be requested as needed.',
     'State the board examination and documents to verify.', 20, 1),
    ('CAV for Apostille Requirements', 'Certification, authentication, and verification for documents requiring apostille.',
     'cav', 'service', 'unlimited', 'Can be requested as needed.',
     'State the destination country and documents requiring apostille.', 30, 1),
    ('CAV for Student Visa', 'Certification, authentication, and verification for student visa requirements.',
     'cav', 'service', 'unlimited', 'Can be requested as needed.',
     'State the destination country, school, and documents to verify.', 40, 1),
    ('Change of Personal Information', 'Request correction or update of personal information on record.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the field(s) to change, old value, new value, and reason.', 10, 1),
    ('Tutorial Form', 'Request a tutorial form or tutorial arrangement.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the course, term, and reason.', 20, 1),
    ('Adding and Dropping Form', 'Request an adding or dropping form for course changes.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the course(s), term, and reason.', 30, 1),
    ('Withdrawal Form', 'Request a withdrawal form.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the course or program, term, and reason.', 40, 1),
    ('Cancellation Form', 'Request a cancellation form for enrollment or registration.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State what is being cancelled and the reason.', 50, 1),
    ('Application for Graduation', 'Submit an application for graduation.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the program, expected graduation term, and contact details.', 60, 1),
    ('Deficiency Form', 'Request a deficiency form or resolve a deficiency.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the deficiency and the course or term involved.', 70, 1),
    ('Accreditation Form', 'Request an accreditation form.',
     'other', 'service', 'unlimited', 'Can be requested as needed.',
     'State the accreditation purpose and program.', 80, 1);

-- Academic terms (development placeholders — adjust in Admin → Settings) --------
INSERT INTO academic_terms (school_year, semester, starts_on, ends_on, is_current) VALUES
    ('2025-2026', 'first',  '2025-08-04', '2025-12-19', 0),
    ('2025-2026', 'second', '2026-01-12', '2026-05-22', 0),
    ('2025-2026', 'summer', '2026-06-01', '2026-07-10', 0),
    ('2026-2027', 'first',  '2026-08-03', '2026-12-18', 1),
    ('2026-2027', 'second', '2027-01-11', '2027-05-21', 0),
    ('2026-2027', 'summer', '2027-06-01', '2027-07-09', 0);

-- Accounts ----------------------------------------------------------------------
INSERT INTO users (email, password_hash, role, status, student_number, full_name, program) VALUES
    ('admin@tcgc.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'admin',  'active', NULL, 'TCGC Registrar Administrator', NULL),
    ('staff1@tcgc.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'staff',  'active', NULL, 'Registrar Staff One', NULL),
    ('staff2@tcgc.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'staff',  'active', NULL, 'Registrar Staff Two', NULL),
    ('student1@tcgc.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'student', 'active', '2023-00001', 'Juan Dela Cruz', 'BS Information Technology'),
    ('student2@tcgc.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'student', 'active', '2023-00002', 'Maria Santos', 'BS Education'),
    ('student3@tcgc.edu.ph',
     '$2y$10$0amOA5CgyNX6/W/XKdWv0eaPOFW6FEVhwV6RoPXKMX01M7/C5X6LO',
     'student', 'active', '2022-00003', 'Pedro Ramos', 'BS Business Administration');

-- Institution-configurable settings (FR4 channel toggle + business rules) -------
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('notification_email_enabled', '0'),
    ('duplicate_window_days',      '30'),
    ('release_mode',               'pickup'),
    ('cron_last_run_at',           '');

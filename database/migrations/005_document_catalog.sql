-- Migration 005 - Expand the document catalogue into registrar groups
-- Run once against an existing database that has already applied migration 004.
-- Fresh installs get the same shape and rows from schema.sql/seed.sql.
--
-- catalog_group drives the student picker tabs:
--   student / graduate -> also include rows whose group is 'both'
--   cav / other         -> exact group only
--
-- item_type drives the request form: documents ask for a quantity, while
-- services (CAV and registrar forms) are submitted as one transaction.

USE registrack;

ALTER TABLE document_types
    ADD COLUMN catalog_group ENUM('student','graduate','both','cav','other')
        NOT NULL DEFAULT 'student' AFTER description,
    ADD COLUMN item_type ENUM('document','service')
        NOT NULL DEFAULT 'document' AFTER catalog_group,
    ADD COLUMN instructions VARCHAR(500) NULL AFTER policy_note,
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER instructions,
    ADD INDEX idx_document_types_catalog (is_active, catalog_group, sort_order);

-- Existing catalogue rows keep their names, IDs, and issuance policies so
-- historical requests and their policy checks are unchanged.
UPDATE document_types
SET catalog_group = 'both', item_type = 'document', sort_order = 10,
    instructions = 'State the recipient and purpose.'
WHERE name = 'Transcript of Records (TOR)';

UPDATE document_types
SET catalog_group = 'student', item_type = 'document', sort_order = 20,
    instructions = 'State the term and purpose.'
WHERE name = 'Certificate of Enrollment';

UPDATE document_types
SET catalog_group = 'student', item_type = 'document', sort_order = 30,
    instructions = 'State the term and purpose.'
WHERE name = 'Grade Certification';

UPDATE document_types
SET catalog_group = 'graduate', item_type = 'document', sort_order = 80,
    instructions = 'State the program and purpose.'
WHERE name = 'Certificate of Graduation';

UPDATE document_types
SET catalog_group = 'student', item_type = 'document', sort_order = 70,
    instructions = 'State the receiving school and purpose.'
WHERE name = 'Honorable Dismissal';

-- New student, graduate, CAV, and other registrar items. INSERT IGNORE keeps
-- the migration safe to re-run if an administrator already added a row by hand.
INSERT IGNORE INTO document_types
    (name, description, catalog_group, item_type, issuance_policy, policy_note, instructions, sort_order, is_active)
VALUES
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

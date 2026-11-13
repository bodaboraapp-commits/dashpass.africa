-- MtaaPass migration 20261111_000013
-- Privacy centre: data-subject requests submitted from the public legal pages
-- (opt in / opt out of optional communications, access, correction and deletion).
--
-- Deliberately has no foreign key to `users`: a request may come from someone who
-- has no account (a visitor, a former resident, or a person whose data was
-- captured by an estate administrator), and the request must still be recorded.
-- Contact details are stored as supplied so the request can be verified and
-- actioned; they are never exposed on a public page.

CREATE TABLE privacy_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    reference VARCHAR(24) NOT NULL,
    request_type ENUM('opt_in', 'opt_out', 'access', 'correction', 'deletion') NOT NULL,
    subject_name VARCHAR(180) NULL,
    subject_phone VARCHAR(20) NULL,
    subject_email VARCHAR(190) NULL,
    message TEXT NULL,
    status ENUM('pending', 'in_progress', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    -- Snapshot of the published notice version the person was shown when they
    -- submitted, so a consent record can be traced to an exact text version.
    policy_version VARCHAR(20) NOT NULL DEFAULT '1.0',
    requested_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    processed_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_privacy_requests_uuid (uuid),
    UNIQUE KEY uq_privacy_requests_reference (reference),
    KEY idx_privacy_requests_status (status, requested_at),
    KEY idx_privacy_requests_contact (subject_phone, subject_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS roles (
    role_id   INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (role_id, role_name) VALUES
    (1, 'Super Administrator'),
    (2, 'Project Manager'),
    (3, 'Field Officer'),
    (4, 'Executive Director'),
    (5, 'Finance Officer'),
    (6, 'M&E Officer'),
    (7, 'Auditor'),
    (8, 'Donor/Partner')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

CREATE TABLE IF NOT EXISTS users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id       INT NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_email (email),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (role_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    project_id  INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    description TEXT NULL,
    location    VARCHAR(150) NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('Planned','Ongoing','Completed','On hold') NOT NULL DEFAULT 'Planned',
    manager_id  INT NULL,
    created_by  INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_project_dates CHECK (end_date >= start_date),
    CONSTRAINT fk_projects_manager FOREIGN KEY (manager_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_projects_creator FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
    activity_id      INT AUTO_INCREMENT PRIMARY KEY,
    project_id       INT NOT NULL,
    title            VARCHAR(150) NOT NULL,
    assigned_to      INT NULL,
    planned_start    DATE NULL,
    planned_end      DATE NULL,
    status           ENUM('Not started','In progress','Completed','Delayed') NOT NULL DEFAULT 'Not started',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completion_notes TEXT NULL,
    delay_reason     TEXT NULL,
    delay_reason_at  DATETIME NULL,
    updated_at       DATETIME NULL,
    CONSTRAINT chk_activity_progress CHECK (progress_percent BETWEEN 0 AND 100),
    CONSTRAINT fk_activities_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_activities_user FOREIGN KEY (assigned_to) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS beneficiaries (
    beneficiary_id   INT AUTO_INCREMENT PRIMARY KEY,
    beneficiary_code VARCHAR(12) NOT NULL,   -- NGBN001, NGBN002, ...
    full_name        VARCHAR(100) NOT NULL,
    sex              ENUM('Female','Male','Other') NULL,
    date_of_birth    DATE NULL,
    phone            VARCHAR(20) NULL,
    community        VARCHAR(100) NULL,
    state            VARCHAR(50) NULL,
    consent_given    TINYINT(1) NOT NULL,
    consent_date     DATE NULL,
    registered_by    INT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_beneficiary_code (beneficiary_code),
    UNIQUE KEY uq_beneficiary_person (full_name, date_of_birth, phone),
    CONSTRAINT chk_consent CHECK (consent_given = 1),
    CONSTRAINT fk_beneficiaries_user FOREIGN KEY (registered_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_beneficiaries (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    project_id     INT NOT NULL,
    beneficiary_id INT NOT NULL,
    enrolled_on    DATE NULL,
    UNIQUE KEY uq_enrolment (project_id, beneficiary_id),
    CONSTRAINT fk_pb_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pb_beneficiary FOREIGN KEY (beneficiary_id) REFERENCES beneficiaries (beneficiary_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicators (
    indicator_id     INT AUTO_INCREMENT PRIMARY KEY,
    project_id       INT NOT NULL,
    name             VARCHAR(150) NOT NULL,
    unit             VARCHAR(30) NOT NULL,
    baseline         DECIMAL(12) NOT NULL DEFAULT 0,
    target           DECIMAL(12,2) NOT NULL,
    reporting_period ENUM('Monthly','Quarterly','Annual') NOT NULL,
    CONSTRAINT fk_indicators_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicator_values (
    value_id     INT AUTO_INCREMENT PRIMARY KEY,
    indicator_id INT NOT NULL,
    period_label VARCHAR(20) NOT NULL,
    actual_value DECIMAL(12,2) NOT NULL,
    recorded_by  INT NULL,
    recorded_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_indicator_period (indicator_id, period_label),
    CONSTRAINT fk_iv_indicator FOREIGN KEY (indicator_id) REFERENCES indicators (indicator_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_iv_user FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    log_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    action     VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NULL,
    record_id  INT NULL,
    details    TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS donors (
    donor_id       INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    donor_type     ENUM('Foundation','Government','Corporate','Multilateral','Individual','Other') NOT NULL DEFAULT 'Foundation',
    contact_person VARCHAR(100) NULL,
    email          VARCHAR(100) NULL,
    phone          VARCHAR(20) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_donor_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grants (
    grant_id   INT AUTO_INCREMENT PRIMARY KEY,
    donor_id   INT NOT NULL,
    project_id INT NOT NULL,
    reference  VARCHAR(50) NOT NULL,
    amount     DECIMAL(16,2) NOT NULL,
    start_date DATE NULL,
    end_date   DATE NULL,
    status     ENUM('Pending','Active','Closed') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grant_reference (reference),
    CONSTRAINT fk_grants_donor FOREIGN KEY (donor_id) REFERENCES donors (donor_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_grants_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_grants_user FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fund_receipts (
    receipt_id  INT AUTO_INCREMENT PRIMARY KEY,
    grant_id    INT NOT NULL,
    amount      DECIMAL(16,2) NOT NULL,
    received_on DATE NOT NULL,
    reference   VARCHAR(60) NULL,
    recorded_by INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_receipts_grant FOREIGN KEY (grant_id) REFERENCES grants (grant_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_receipts_user FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_lines (
    line_id        INT AUTO_INCREMENT PRIMARY KEY,
    project_id     INT NOT NULL,
    category       ENUM('Personnel','Travel','Materials and supplies','Equipment','Training and workshops','Administration','Other') NOT NULL,
    description    VARCHAR(150) NOT NULL,
    activity_id    INT NULL,
    planned_amount DECIMAL(16,2) NOT NULL,
    created_by     INT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lines_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lines_activity FOREIGN KEY (activity_id) REFERENCES activities (activity_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lines_user FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commitments (
    commitment_id  INT AUTO_INCREMENT PRIMARY KEY,
    line_id        INT NOT NULL,
    po_number      VARCHAR(40) NOT NULL,
    vendor         VARCHAR(150) NOT NULL,
    description    VARCHAR(255) NULL,
    amount         DECIMAL(16,2) NOT NULL,
    status         ENUM('Pending approval','Approved','Paid','Cancelled','Rejected') NOT NULL DEFAULT 'Pending approval',
    submitted_by   INT NULL,
    approved_by    INT NULL,
    approved_at    DATETIME NULL,
    decision_note  VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_po_number (po_number),
    CONSTRAINT fk_commit_line FOREIGN KEY (line_id) REFERENCES budget_lines (line_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_commit_sub FOREIGN KEY (submitted_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_commit_app FOREIGN KEY (approved_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenditures (
    expenditure_id INT AUTO_INCREMENT PRIMARY KEY,
    line_id        INT NOT NULL,
    spent_on       DATE NOT NULL,
    amount         DECIMAL(16,2) NOT NULL,
    payee          VARCHAR(150) NOT NULL,
    reference      VARCHAR(60) NULL,
    description    VARCHAR(255) NULL,
    status         ENUM('Pending approval','Approved','Rejected') NOT NULL DEFAULT 'Pending approval',
    commitment_id  INT NULL,
    submitted_by   INT NULL,
    approved_by    INT NULL,
    approved_at    DATETIME NULL,
    decision_note  VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_exp_status (status),
    CONSTRAINT fk_exp_line FOREIGN KEY (line_id) REFERENCES budget_lines (line_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_exp_commit FOREIGN KEY (commitment_id) REFERENCES commitments (commitment_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_exp_sub FOREIGN KEY (submitted_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_exp_app FOREIGN KEY (approved_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- .
CREATE TABLE IF NOT EXISTS user_projects (
    user_id    INT NOT NULL,
    project_id INT NOT NULL,
    PRIMARY KEY (user_id, project_id),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_up_project FOREIGN KEY (project_id) REFERENCES projects (project_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

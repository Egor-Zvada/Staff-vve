-- Staff V2. The application stores moments in UTC; business dates are Asia/Sakhalin.
-- No destructive migrations and no imported attendance data.
CREATE TABLE IF NOT EXISTS employees (
    id INT UNSIGNED NOT NULL,
    pass_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    surname VARCHAR(160) NOT NULL DEFAULT '',
    given_name VARCHAR(160) NOT NULL DEFAULT '',
    patronymic VARCHAR(160) NOT NULL DEFAULT '',
    legacy_full_name VARCHAR(255) NULL,
    position VARCHAR(255) NOT NULL DEFAULT '',
    is_guard TINYINT NOT NULL DEFAULT 0,
    active TINYINT NOT NULL DEFAULT 1,
    is_service TINYINT NOT NULL DEFAULT 0,
    admin_role ENUM('NONE','MODERATOR','ADMIN') NOT NULL DEFAULT 'NONE',
    password_hash VARCHAR(255) NULL,
    enrollment_pin_hash VARCHAR(255) NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    started_on DATE NOT NULL DEFAULT '1970-01-01',
    ended_on DATE NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY employees_pass_code (pass_code),
    KEY employees_active (active, is_service),
    KEY employees_name (surname, given_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_id_sequence (
    id INT UNSIGNED NOT NULL,
    next_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO employee_id_sequence (id, next_id) VALUES (1, 1);

CREATE TABLE IF NOT EXISTS employee_profile_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    surname VARCHAR(160) NOT NULL,
    given_name VARCHAR(160) NOT NULL,
    patronymic VARCHAR(160) NOT NULL DEFAULT '',
    position VARCHAR(255) NOT NULL DEFAULT '',
    active TINYINT NOT NULL DEFAULT 1,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    actor_employee_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY employee_profiles_date (employee_id, valid_from),
    CONSTRAINT employee_profiles_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT employee_profiles_actor FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    start_time TIME NOT NULL DEFAULT '09:00:00',
    end_time TIME NOT NULL DEFAULT '18:00:00',
    weekdays JSON NOT NULL DEFAULT '[1,2,3,4,5]',
    late_grace_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    early_grace_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT NOT NULL DEFAULT 1,
    config_json JSON NOT NULL DEFAULT '{}',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    effective_from DATE NOT NULL DEFAULT '1970-01-01',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_group_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id INT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    config_json JSON NOT NULL,
    actor_employee_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY group_versions_date (group_id, valid_from, id),
    CONSTRAINT group_versions_group FOREIGN KEY (group_id) REFERENCES schedule_groups(id),
    CONSTRAINT group_versions_actor FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    PRIMARY KEY (id),
    KEY assignments_dates (employee_id, valid_from, valid_to),
    CONSTRAINT assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT assignments_group FOREIGN KEY (group_id) REFERENCES schedule_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendar_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NULL,
    group_id INT UNSIGNED NULL,
    work_date DATE NOT NULL,
    is_working TINYINT NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    reason VARCHAR(1000) NOT NULL DEFAULT '',
    scope_key VARCHAR(64) NOT NULL DEFAULT 'global',
    PRIMARY KEY (id),
    UNIQUE KEY calendar_scope (scope_key, work_date),
    KEY calendar_date (work_date, employee_id, group_id),
    CONSTRAINT calendar_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT calendar_group FOREIGN KEY (group_id) REFERENCES schedule_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_plan_cache (
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    plan_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, work_date),
    CONSTRAINT plans_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) NOT NULL,
    value_json JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_employee_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    private TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY audit_entity (entity_type, entity_id, created_at),
    KEY audit_actor (actor_employee_id, created_at),
    CONSTRAINT audit_employee FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL,
    `value` VARCHAR(255) NOT NULL,
    active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY credentials_value (kind, `value`),
    KEY credentials_employee (employee_id, active),
    CONSTRAINT credentials_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operation_requests (
    request_id VARCHAR(128) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    response_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY operations_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    event_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    source VARCHAR(48) NOT NULL,
    request_id VARCHAR(128) NOT NULL,
    private TINYINT NOT NULL DEFAULT 0,
    context_json JSON NOT NULL DEFAULT '{}',
    PRIMARY KEY (id),
    KEY attendance_events_day (employee_id, work_date, event_type, event_at),
    KEY attendance_events_request (request_id),
    CONSTRAINT events_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    before_at DATETIME NULL,
    after_at DATETIME NULL,
    received_at DATETIME NOT NULL,
    source VARCHAR(48) NOT NULL,
    actor_employee_id INT UNSIGNED NULL,
    session_fingerprint VARCHAR(128) NOT NULL DEFAULT '',
    private TINYINT NOT NULL DEFAULT 0,
    event_id BIGINT UNSIGNED NULL,
    request_id VARCHAR(128) NULL,
    context_json JSON NOT NULL DEFAULT '{}',
    PRIMARY KEY (id),
    KEY changes_day (employee_id, work_date, received_at),
    CONSTRAINT changes_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT changes_actor FOREIGN KEY (actor_employee_id) REFERENCES employees(id),
    CONSTRAINT changes_event FOREIGN KEY (event_id) REFERENCES attendance_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_days (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    arrival_at DATETIME NULL,
    departure_at DATETIME NULL,
    lunch_out_at DATETIME NULL,
    lunch_in_at DATETIME NULL,
    excused_out_at DATETIME NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    overrides_json JSON NOT NULL DEFAULT '{}',
    plan_json JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY attendance_day (employee_id, work_date),
    KEY attendance_dates (work_date, employee_id),
    CONSTRAINT days_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_flags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    kind VARCHAR(64) NOT NULL,
    event_type VARCHAR(24) NULL,
    evidence_key VARCHAR(255) NOT NULL,
    details_json JSON NOT NULL,
    suppressed TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active TINYINT NOT NULL DEFAULT 1,
    private TINYINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY flags_evidence (evidence_key),
    KEY flags_day (employee_id, work_date, active, suppressed, private),
    CONSTRAINT flags_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_service_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    action VARCHAR(48) NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    reason VARCHAR(2000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY private_events_day (employee_id, work_date, created_at),
    CONSTRAINT private_events_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_resolutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    finding_id BIGINT UNSIGNED NULL,
    correction_id BIGINT UNSIGNED NULL,
    event_id BIGINT UNSIGNED NULL,
    action VARCHAR(48) NOT NULL,
    reason VARCHAR(2000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY resolutions_day (employee_id, work_date),
    CONSTRAINT resolutions_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT resolutions_flag FOREIGN KEY (finding_id) REFERENCES attendance_flags(id),
    CONSTRAINT resolutions_change FOREIGN KEY (correction_id) REFERENCES attendance_changes(id),
    CONSTRAINT resolutions_event FOREIGN KEY (event_id) REFERENCES attendance_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    file_hash CHAR(64) NOT NULL,
    mode VARCHAR(32) NOT NULL,
    summary_json JSON NOT NULL,
    actor_employee_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY imports_hash (file_hash, mode),
    CONSTRAINT imports_actor FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS badge_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    config_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sheet_presets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    config_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash VARCHAR(128) NOT NULL,
    login_id VARCHAR(128) NOT NULL,
    success TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY login_rate (ip_hash, created_at),
    KEY login_identity (login_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Additive Wallet integration. Apply this file alone to an existing Staff database.
CREATE TABLE IF NOT EXISTS wallet_passes (
    employee_id INT UNSIGNED NOT NULL,
    display_id VARCHAR(32) NOT NULL,
    display_name VARCHAR(500) NULL,
    state VARCHAR(16) NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    serial_number VARCHAR(128) NULL,
    share_url TEXT NULL,
    google_save_url TEXT NULL,
    apple_pass LONGBLOB NULL,
    last_error VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (employee_id),
    UNIQUE KEY wallet_serial (serial_number),
    UNIQUE KEY wallet_request (request_id),
    KEY wallet_state (state, updated_at),
    CONSTRAINT wallet_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_attempts (
    request_id VARCHAR(64) NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    actor_employee_id INT UNSIGNED NOT NULL,
    state VARCHAR(16) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    payload_json JSON NULL,
    serial_number VARCHAR(128) NULL,
    last_error VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (request_id),
    KEY wallet_attempt_employee (employee_id, created_at),
    CONSTRAINT wallet_attempt_employee_fk FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT wallet_attempt_actor_fk FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_provider_usage (
    id INT UNSIGNED NOT NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    quota_limit INT UNSIGNED NOT NULL DEFAULT 0,
    reset_date DATE NULL,
    plan VARCHAR(32) NOT NULL DEFAULT '',
    fetched_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO wallet_provider_usage (id, used_count, quota_limit) VALUES (1, 0, 0);

CREATE TABLE IF NOT EXISTS wallet_pass_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    request_id VARCHAR(64) NOT NULL,
    display_name VARCHAR(500) NULL,
    serial_number VARCHAR(128) NOT NULL,
    share_url TEXT NOT NULL,
    google_save_url TEXT NOT NULL,
    apple_pass LONGBLOB NOT NULL,
    state VARCHAR(16) NOT NULL,
    actor_employee_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    archived_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY wallet_history_request (request_id),
    UNIQUE KEY wallet_history_serial (serial_number),
    KEY wallet_history_employee (employee_id, archived_at),
    CONSTRAINT wallet_history_employee_fk FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT wallet_history_actor_fk FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additive vacation module. Apply this file alone to an existing Staff database.
CREATE TABLE IF NOT EXISTS employee_leaves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    kind VARCHAR(24) NOT NULL DEFAULT 'vacation',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    note VARCHAR(1000) NOT NULL DEFAULT '',
    active TINYINT NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    actor_employee_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY leaves_employee_dates (employee_id, active, start_date, end_date),
    KEY leaves_period (active, start_date, end_date),
    CONSTRAINT leaves_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
    CONSTRAINT leaves_actor FOREIGN KEY (actor_employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additive private scheduler storage; no rules are enabled by this migration.
CREATE TABLE IF NOT EXISTS private_automation_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    trigger_time TIME NOT NULL,
    mark_time TIME NOT NULL,
    random_minutes TINYINT NOT NULL DEFAULT 0,
    trigger_day_offset TINYINT UNSIGNED NOT NULL DEFAULT 0,
    weekdays JSON NOT NULL,
    working_days_only TINYINT NOT NULL DEFAULT 1,
    enabled TINYINT NOT NULL DEFAULT 0,
    enabled_from DATE NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY private_automation_slot (employee_id,event_type,trigger_time,trigger_day_offset),
    KEY private_automation_enabled (enabled,valid_from,valid_to),
    CONSTRAINT private_automation_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_automation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id BIGINT UNSIGNED NOT NULL,
    rule_version INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    mark_time TIME NOT NULL,
    base_mark_time TIME NULL,
    random_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL,
    message VARCHAR(500) NOT NULL,
    request_id VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY private_automation_once (rule_id,work_date),
    KEY private_automation_recent (created_at,id),
    CONSTRAINT private_automation_run_rule FOREIGN KEY (rule_id) REFERENCES private_automation_rules(id),
    CONSTRAINT private_automation_run_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS private_automation_state (
    id INT UNSIGNED NOT NULL,
    last_started_at DATETIME NULL,
    last_finished_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_error VARCHAR(500) NOT NULL DEFAULT '',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO private_automation_state (id) VALUES (1);

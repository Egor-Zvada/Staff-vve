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

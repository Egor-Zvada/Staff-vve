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

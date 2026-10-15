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

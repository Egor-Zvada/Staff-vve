-- Additive canonical pass IDs. Internal employee primary keys and attendance history stay intact.
ALTER TABLE employees ADD COLUMN IF NOT EXISTS pass_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id;
UPDATE employees SET pass_code = CASE WHEN id < 1000 THEN LPAD(CAST(id AS CHAR), 3, '0') ELSE CAST(id AS CHAR) END WHERE pass_code IS NULL OR pass_code = '';
CREATE UNIQUE INDEX IF NOT EXISTS employees_pass_code ON employees (pass_code);

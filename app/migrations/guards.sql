-- Current guard classification: floating 24-hour shifts are outside attendance control.
-- Existing employees stay under their current schedules until an administrator sets this flag.
ALTER TABLE employees ADD COLUMN IF NOT EXISTS is_guard TINYINT NOT NULL DEFAULT 0 AFTER position;

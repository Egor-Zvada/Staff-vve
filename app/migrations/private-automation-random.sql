-- Additive option. Existing rules keep their state and exact time until explicitly enabled.
ALTER TABLE private_automation_rules ADD COLUMN IF NOT EXISTS random_minutes TINYINT NOT NULL DEFAULT 0 AFTER mark_time;
ALTER TABLE private_automation_runs ADD COLUMN IF NOT EXISTS base_mark_time TIME NULL AFTER mark_time;
ALTER TABLE private_automation_runs ADD COLUMN IF NOT EXISTS random_offset_minutes TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER base_mark_time;

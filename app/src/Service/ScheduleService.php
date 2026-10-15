<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class ScheduleService
{
    public const TIMEZONE = 'Asia/Sakhalin';
    private readonly Closure $clock;

    public function __construct(private readonly Database $db, private readonly Settings $settings, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function validateDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TIMEZONE));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Некорректная календарная дата.', 400);
        }
        return $date;
    }

    public static function validateTime(string $time): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/D', $time, $matches)) {
            throw new DomainException('Время должно иметь формат ЧЧ:ММ или ЧЧ:ММ:СС.', 400);
        }
        return $matches[1].':'.$matches[2].':'.($matches[3] ?? '00');
    }

    public function policy(array $overrides = []): array
    {
        $defaults = [
            'auto_start' => '05:00', 'auto_cutover' => '12:00', 'night_event' => 'arrival',
            'duplicate_seconds' => 3, 'manual_lag_seconds' => 120,
            'missing_after_minutes' => 15, 'close_after_minutes' => 30,
            'extra_events' => true, 'report_max_days' => 366,
        ];
        $aliases = ['auto_cutover' => 'auto_switch', 'missing_after_minutes' => 'missing_arrival_minutes', 'close_after_minutes' => 'close_delay_minutes'];
        $policy = [];
        foreach ($defaults as $key => $default) {
            $fallback = isset($aliases[$key]) ? $this->settings->get($aliases[$key], $default) : $default;
            $policy[$key] = $overrides[$key] ?? $this->settings->get($key, $fallback);
        }
        foreach (['duplicate_seconds', 'manual_lag_seconds', 'missing_after_minutes', 'close_after_minutes', 'report_max_days'] as $key) {
            $policy[$key] = max(0, (int) $policy[$key]);
        }
        $policy['report_max_days'] = max(1, min(3660, $policy['report_max_days']));
        $policy['auto_start'] = self::validateTime((string) $policy['auto_start']);
        $policy['auto_cutover'] = self::validateTime((string) $policy['auto_cutover']);
        $policy['night_event'] = in_array($policy['night_event'], ['arrival', 'departure'], true) ? $policy['night_event'] : 'arrival';
        return $policy;
    }

    /** start/end are offset-aware ISO moments; start_utc/end_utc are SQL UTC. */
    public function plan(int $employeeId, string $date): array
    {
        self::validateDate($date);
        $cached = $this->db->one('SELECT plan_json FROM schedule_plan_cache WHERE employee_id = ? AND work_date = ?', [$employeeId, $date]);
        if ($cached !== null) {
            $plan = json_decode((string) $cached['plan_json'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($plan)) {
                return $this->applyHistoryBoundary($plan);
            }
        }
        $employee = $this->db->one('SELECT * FROM employees WHERE id = ?', [$employeeId]);
        if ($employee === null) {
            throw new DomainException('Сотрудник не найден.', 404);
        }
        $profile = $this->db->one(
            'SELECT active FROM employee_profile_versions WHERE employee_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from DESC, id DESC LIMIT 1',
            [$employeeId, $date, $date]
        );
        $assignment = $this->db->one(
            'SELECT * FROM schedule_assignments WHERE employee_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from DESC, id DESC LIMIT 1',
            [$employeeId, $date, $date]
        );
        $group = null;
        $versionId = null;
        if ($assignment !== null) {
            $group = $this->db->one('SELECT * FROM schedule_groups WHERE id = ?', [(int) $assignment['group_id']]);
            $version = $this->db->one('SELECT * FROM schedule_group_versions WHERE group_id = ? AND valid_from <= ? ORDER BY valid_from DESC, id DESC LIMIT 1', [(int) $assignment['group_id'], $date]);
            if ($version !== null) {
                $group = json_decode((string) $version['config_json'], true, 512, JSON_THROW_ON_ERROR);
                $versionId = (int) $version['id'];
            } elseif ($group !== null && (string) ($group['effective_from'] ?? '1970-01-01') > $date) {
                $group = null;
            }
        }
        $weekday = (int) (new DateTimeImmutable($date, new DateTimeZone(self::TIMEZONE)))->format('N');
        $weekdays = $group === null ? [] : $this->decode($group['weekdays'] ?? []);
        $known = $group !== null && (int) ($group['active'] ?? 1) === 1;
        $working = $known && in_array($weekday, array_map('intval', $weekdays), true);
        $start = $known ? self::validateTime((string) $group['start_time']) : null;
        $end = $known ? self::validateTime((string) $group['end_time']) : null;
        $scopeGroup = $assignment === null ? 0 : (int) $assignment['group_id'];
        $exception = $this->db->one(
            'SELECT * FROM calendar_exceptions WHERE work_date = ? AND (employee_id = ? OR (employee_id IS NULL AND group_id = ?) OR (employee_id IS NULL AND group_id IS NULL)) ORDER BY CASE WHEN employee_id IS NOT NULL THEN 3 WHEN group_id IS NOT NULL THEN 2 ELSE 1 END DESC, id DESC LIMIT 1',
            [$date, $employeeId, $scopeGroup]
        );
        if ($exception !== null) {
            $working = (bool) $exception['is_working'];
            $start = $exception['start_time'] !== null ? self::validateTime((string) $exception['start_time']) : $start;
            $end = $exception['end_time'] !== null ? self::validateTime((string) $exception['end_time']) : $end;
            $known = !$working || ($start !== null && $end !== null);
        }
        // Current active/ended_on cannot identify an earlier dismissal interval after rehire.
        // The dated profile records that interval; ended_on remains an inclusive final workday.
        if ((int) $employee['is_service'] === 1 || (string) $employee['started_on'] > $date || ($employee['ended_on'] !== null && (string) $employee['ended_on'] < $date) || ($profile !== null && !(bool) $profile['active'])) {
            $working = false;
        }
        $config = $group === null ? [] : $this->decode($group['config_json'] ?? []);
        $startLocal = $start === null ? null : new DateTimeImmutable($date.' '.$start, new DateTimeZone(self::TIMEZONE));
        $endLocal = $end === null ? null : new DateTimeImmutable($date.' '.$end, new DateTimeZone(self::TIMEZONE));
        if ($startLocal !== null && $endLocal !== null && $endLocal <= $startLocal) {
            $endLocal = $endLocal->modify('+1 day');
        }
        $plan = [
            'employee_id' => $employeeId, 'work_date' => $date, 'is_working' => $working && $known, 'schedule_is_working' => $working && $known,
            'known' => $known, 'weekday' => $weekday, 'is_weekend' => $weekday >= 6,
            'start' => $startLocal?->format(DATE_ATOM), 'end' => $endLocal?->format(DATE_ATOM),
            'start_utc' => $startLocal?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'end_utc' => $endLocal?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'start_time' => $start === null ? null : substr($start, 0, 5), 'end_time' => $end === null ? null : substr($end, 0, 5),
            'late_grace_seconds' => max(0, (int) ($group['late_grace_seconds'] ?? 0)),
            'early_grace_seconds' => max(0, (int) ($group['early_grace_seconds'] ?? 0)),
            'group_name' => (string) ($group['name'] ?? 'График не назначен'),
            'group_id' => $scopeGroup ?: null, 'group_version_id' => $versionId,
            'assignment_id' => $assignment === null ? null : (int) $assignment['id'],
            'exception_id' => $exception === null ? null : (int) $exception['id'],
            'exception_reason' => (string) ($exception['reason'] ?? ''), 'policy_overrides' => $config, 'policy' => $this->policy($config),
        ];
        $plan = $this->applyHistoryBoundary($plan);
        // Freeze observed historical/current plans. Explicit administrative edits invalidate their scope.
        if ($date <= $this->now()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d')) {
            $this->insertIgnore('schedule_plan_cache', ['employee_id' => $employeeId, 'work_date' => $date, 'plan_json' => $this->json($plan), 'created_at' => $this->stamp()]);
            $saved = $this->db->scalar('SELECT plan_json FROM schedule_plan_cache WHERE employee_id = ? AND work_date = ?', [$employeeId, $date]);
            if (is_string($saved)) {
                return $this->applyHistoryBoundary(json_decode($saved, true, 512, JSON_THROW_ON_ERROR));
            }
        }
        return $plan;
    }

    public function applyHistoryBoundary(array $plan): array
    {
        $start = $this->settings->get('history_start', null);
        $plan['history_start'] = is_string($start) && $start !== '' ? self::validateDate($start) : null;
        $plan['is_working'] = (bool) ($plan['schedule_is_working'] ?? $plan['is_working']);
        $plan['history_reason'] = '';
        if ($plan['history_start'] !== null && $plan['work_date'] < $plan['history_start']) {
            $plan['is_working'] = false;
            $plan['history_reason'] = 'До начала учёта Staff V2';
        }
        return $this->applyLeave($plan);
    }

    /** A fresh overlay also applies to frozen attendance_days.plan_json snapshots. */
    public function applyLeave(array $plan): array
    {
        $plan['schedule_is_working'] = (bool) ($plan['schedule_is_working'] ?? $plan['original_is_working'] ?? $plan['is_working']);
        $plan['is_working'] = $plan['schedule_is_working'];
        if (!empty($plan['history_start']) && $plan['work_date'] < $plan['history_start']) {
            $plan['is_working'] = false;
        }
        $leave = $this->db->one('SELECT id, start_date, end_date, version FROM employee_leaves WHERE employee_id = ? AND active = 1 AND start_date <= ? AND end_date >= ? ORDER BY id DESC LIMIT 1', [(int) $plan['employee_id'], (string) $plan['work_date'], (string) $plan['work_date']]);
        $plan['on_leave'] = $leave !== null;
        $plan['leave_id'] = $leave === null ? null : (int) $leave['id'];
        $plan['leave_label'] = $leave === null ? '' : 'Отпуск';
        $plan['leave_from'] = $leave['start_date'] ?? null;
        $plan['leave_to'] = $leave['end_date'] ?? null;
        $plan['leave_version'] = $leave === null ? null : (int) $leave['version'];
        if ($leave !== null) {
            $plan['is_working'] = false;
        }
        // This classification is a current control setting, not a replacement shift schedule.
        $plan['is_guard'] = (bool) $this->db->scalar('SELECT is_guard FROM employees WHERE id = ?', [(int) $plan['employee_id']]);
        $plan['tracking_label'] = $plan['is_guard'] ? 'Охранник · без контроля графика' : '';
        if ($plan['is_guard']) { $plan['is_working'] = false; }
        return $plan;
    }

    public function commandPolicy(array $plan): array
    {
        return $this->policy(is_array($plan['policy_overrides'] ?? null) ? $plan['policy_overrides'] : []);
    }

    public function saveGroupVersion(array $data, int $actorId): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainException('Введите название группы длиной до 160 символов.', 400);
        }
        $from = self::validateDate((string) ($data['valid_from'] ?? $data['effective_from'] ?? $this->today()));
        $start = self::validateTime((string) ($data['start_time'] ?? '09:00'));
        $end = self::validateTime((string) ($data['end_time'] ?? '18:00'));
        $weekdays = $this->decode($data['weekdays'] ?? [1, 2, 3, 4, 5]);
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        sort($weekdays);
        foreach ($weekdays as $weekday) {
            if ($weekday < 1 || $weekday > 7) {
                throw new DomainException('Дни недели должны быть числами от 1 до 7.', 400);
            }
        }
        $config = $this->decode($data['config_json'] ?? []);
        $this->policy($config);
        return $this->db->transaction(function () use ($data, $actorId, $name, $from, $start, $end, $weekdays, $config): array {
            $id = (int) ($data['id'] ?? 0);
            $before = $id > 0 ? $this->db->one('SELECT * FROM schedule_groups WHERE id = ?'.$this->lock(), [$id]) : null;
            if ($id > 0 && $before === null) {
                throw new DomainException('Группа не найдена.', 404);
            }
            if ($before !== null && !$this->db->scalar('SELECT COUNT(*) FROM schedule_group_versions WHERE group_id = ?', [$id])) {
                $this->db->insert('schedule_group_versions', ['group_id' => $id, 'valid_from' => (string) $before['effective_from'], 'config_json' => $this->json($before), 'actor_employee_id' => $actorId, 'created_at' => $this->stamp()]);
            }
            $fields = [
                'name' => $name, 'start_time' => $start, 'end_time' => $end, 'weekdays' => $this->json($weekdays),
                'late_grace_seconds' => max(0, min(86400, (int) ($data['late_grace_seconds'] ?? 0))),
                'early_grace_seconds' => max(0, min(86400, (int) ($data['early_grace_seconds'] ?? 0))),
                'active' => (int) ($data['active'] ?? 1) ? 1 : 0, 'config_json' => $this->json($config),
                'version' => (int) ($before['version'] ?? 0) + 1, 'effective_from' => $from,
            ];
            if ($before === null) {
                $this->db->insert('schedule_groups', $fields);
                $id = (int) $this->db->db->lastInsertId();
            }
            $snapshot = ['id' => $id] + $fields;
            $this->db->insert('schedule_group_versions', ['group_id' => $id, 'valid_from' => $from, 'config_json' => $this->json($snapshot), 'actor_employee_id' => $actorId, 'created_at' => $this->stamp()]);
            $latest = $this->db->one('SELECT config_json FROM schedule_group_versions WHERE group_id = ? ORDER BY valid_from DESC, id DESC LIMIT 1', [$id]);
            $latestFields = $this->decode($latest['config_json']);
            unset($latestFields['id']);
            $this->update('schedule_groups', $latestFields, 'id = ?', [$id]);
            $this->audit($actorId, 'schedule.group.save', 'schedule_group', $id, $before, $snapshot);
            $this->invalidateGroup($id, $from);
            return $this->db->one('SELECT * FROM schedule_groups WHERE id = ?', [$id]) ?? $snapshot;
        });
    }

    public function saveAssignment(array $data, int $actorId): array
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $groupId = (int) ($data['group_id'] ?? 0);
        $from = self::validateDate((string) ($data['valid_from'] ?? $this->today()));
        $to = empty($data['valid_to']) ? null : self::validateDate((string) $data['valid_to']);
        if ($to !== null && $to < $from) {
            throw new DomainException('Окончание назначения раньше начала.', 400);
        }
        return $this->db->transaction(function () use ($data, $actorId, $employeeId, $groupId, $from, $to): array {
            if ($employeeId <= 0 || $this->db->one('SELECT id FROM employees WHERE id = ?'.$this->lock(), [$employeeId]) === null || $this->db->one('SELECT id FROM schedule_groups WHERE id = ?', [$groupId]) === null) {
                throw new DomainException('Сотрудник или группа не найдены.', 404);
            }
            $id = (int) ($data['id'] ?? 0);
            $before = $id > 0 ? $this->db->one('SELECT * FROM schedule_assignments WHERE id = ?', [$id]) : null;
            if ($id > 0 && ($before === null || (int) $before['employee_id'] !== $employeeId)) {
                throw new DomainException('Назначение не найдено у сотрудника.', 404);
            }
            $overlaps = $this->db->all('SELECT * FROM schedule_assignments WHERE employee_id = ? AND id <> ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'.$this->lock(), [$employeeId, $id, $to ?? '9999-12-31', $from]);
            foreach ($overlaps as $overlap) {
                if ($id === 0 && $overlap['valid_to'] === null && (string) $overlap['valid_from'] < $from) {
                    $previous = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
                    $this->db->exec('UPDATE schedule_assignments SET valid_to = ? WHERE id = ?', [$previous, (int) $overlap['id']]);
                    $this->audit($actorId, 'schedule.assignment.close', 'schedule_assignment', (int) $overlap['id'], $overlap, ['valid_to' => $previous]);
                } else {
                    throw new DomainException('Назначение пересекается с уже существующим графиком.', 409);
                }
            }
            $fields = ['employee_id' => $employeeId, 'group_id' => $groupId, 'valid_from' => $from, 'valid_to' => $to];
            if ($id === 0) {
                $this->db->insert('schedule_assignments', $fields);
                $id = (int) $this->db->db->lastInsertId();
            } else {
                $this->update('schedule_assignments', $fields, 'id = ?', [$id]);
            }
            $this->audit($actorId, 'schedule.assignment.save', 'schedule_assignment', $id, $before, $fields);
            $affectedFrom = $before === null ? $from : min($from, (string) $before['valid_from']);
            $this->invalidateEmployee($employeeId, $affectedFrom, null);
            return ['id' => $id] + $fields;
        });
    }

    public function saveException(array $data, int $actorId): array
    {
        $date = self::validateDate((string) ($data['work_date'] ?? $data['date'] ?? ''));
        $employeeId = empty($data['employee_id']) ? null : (int) $data['employee_id'];
        $groupId = $employeeId === null && !empty($data['group_id']) ? (int) $data['group_id'] : null;
        $start = empty($data['start_time']) ? null : self::validateTime((string) $data['start_time']);
        $end = empty($data['end_time']) ? null : self::validateTime((string) $data['end_time']);
        $scope = $employeeId !== null ? 'employee:'.$employeeId : ($groupId !== null ? 'group:'.$groupId : 'global');
        return $this->db->transaction(function () use ($data, $actorId, $date, $employeeId, $groupId, $start, $end, $scope): array {
            if ($employeeId !== null && $this->db->one('SELECT id FROM employees WHERE id = ?', [$employeeId]) === null) {
                throw new DomainException('Сотрудник не найден.', 404);
            }
            if ($groupId !== null && $this->db->one('SELECT id FROM schedule_groups WHERE id = ?', [$groupId]) === null) {
                throw new DomainException('Группа не найдена.', 404);
            }
            $id = (int) ($data['id'] ?? 0);
            $before = $id > 0 ? $this->db->one('SELECT * FROM calendar_exceptions WHERE id = ?', [$id]) : $this->db->one('SELECT * FROM calendar_exceptions WHERE scope_key = ? AND work_date = ?', [$scope, $date]);
            if ($id > 0 && $before === null) {
                throw new DomainException('Исключение календаря не найдено.', 404);
            }
            $fields = ['employee_id' => $employeeId, 'group_id' => $groupId, 'work_date' => $date, 'is_working' => (int) ($data['is_working'] ?? 0) ? 1 : 0, 'start_time' => $start, 'end_time' => $end, 'reason' => trim((string) ($data['reason'] ?? '')), 'scope_key' => $scope];
            if ($before === null) {
                $this->db->insert('calendar_exceptions', $fields);
                $id = (int) $this->db->db->lastInsertId();
            } else {
                $id = (int) $before['id'];
                $this->update('calendar_exceptions', $fields, 'id = ?', [$id]);
                if ((string) $before['work_date'] !== $date || (string) $before['scope_key'] !== $scope) {
                    $this->invalidateExceptionScope($before);
                }
            }
            $this->invalidateExceptionScope($fields);
            $this->audit($actorId, 'schedule.calendar.save', 'calendar_exception', $id, $before, $fields);
            return ['id' => $id] + $fields;
        });
    }

    private function invalidateExceptionScope(array $exception): void
    {
        $date = (string) $exception['work_date'];
        if ($exception['employee_id'] !== null) {
            $this->invalidateEmployee((int) $exception['employee_id'], $date, $date);
        } elseif ($exception['group_id'] !== null) {
            foreach ($this->db->all('SELECT DISTINCT employee_id FROM schedule_assignments WHERE group_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)', [(int) $exception['group_id'], $date, $date]) as $row) {
                $this->invalidateEmployee((int) $row['employee_id'], $date, $date);
            }
        } else {
            $this->db->exec('DELETE FROM schedule_plan_cache WHERE work_date = ?', [$date]);
            $this->db->exec('UPDATE attendance_days SET plan_json = NULL WHERE work_date = ?', [$date]);
        }
    }

    private function invalidateGroup(int $id, string $from): void
    {
        foreach ($this->db->all('SELECT DISTINCT employee_id FROM schedule_assignments WHERE group_id = ?', [$id]) as $row) {
            $this->invalidateEmployee((int) $row['employee_id'], $from, null);
        }
    }

    private function invalidateEmployee(int $id, string $from, ?string $to): void
    {
        $params = [$id, $from];
        $range = 'employee_id = ? AND work_date >= ?';
        if ($to !== null) {
            $range .= ' AND work_date <= ?';
            $params[] = $to;
        }
        $this->db->exec('DELETE FROM schedule_plan_cache WHERE '.$range, $params);
        $this->db->exec('UPDATE attendance_days SET plan_json = NULL WHERE '.$range, $params);
    }

    private function audit(int $actor, string $action, string $entity, int $id, ?array $before, array $after): void
    {
        $this->db->insert('audit_log', ['actor_employee_id' => $actor, 'action' => $action, 'entity_type' => $entity, 'entity_id' => (string) $id, 'before_json' => $before === null ? null : $this->json($before), 'after_json' => $this->json($after), 'created_at' => $this->stamp(), 'private' => 0]);
    }

    private function update(string $table, array $values, string $where, array $params): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($values)));
        $this->db->exec('UPDATE '.$table.' SET '.$assignments.' WHERE '.$where, [...array_values($values), ...$params]);
    }

    private function insertIgnore(string $table, array $values): void
    {
        $columns = implode(', ', array_keys($values));
        $sql = ($this->sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE').' INTO '.$table.' ('.$columns.') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')';
        $this->db->exec($sql, array_values($values));
    }

    private function sqlite(): bool
    {
        return str_contains(strtolower(get_class($this->db->db->getDatabasePlatform())), 'sqlite');
    }

    private function lock(): string { return $this->sqlite() ? '' : ' FOR UPDATE'; }
    private function now(): DateTimeImmutable { return ($this->clock)()->setTimezone(new DateTimeZone('UTC')); }
    private function stamp(): string { return $this->now()->format('Y-m-d H:i:s'); }
    private function today(): string { return $this->now()->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d'); }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function decode(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        if ($value === null || $value === '') { return []; }
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new DomainException('Настройки должны быть объектом или списком JSON.', 400); }
        return $decoded;
    }
}

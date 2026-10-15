<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Dated absence records overlay an employee's schedule without rewriting attendance facts. */
final class LeaveService
{
    private readonly Closure $clock;

    public function __construct(private readonly Database $db, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function listing(array $filter = []): array
    {
        $where = ['e.is_service = 0'];
        $params = [];
        if (!empty($filter['employee_id'])) {
            $where[] = 'l.employee_id = ?';
            $params[] = EmployeeService::id($filter['employee_id']);
        }
        if (in_array($filter['state'] ?? '', ['active', 'revoked'], true)) {
            $where[] = 'l.active = ?';
            $params[] = $filter['state'] === 'active' ? 1 : 0;
        }
        $rows = $this->db->all(
            'SELECT l.*, e.pass_code, e.surname, e.given_name, e.patronymic, e.legacy_full_name, e.active AS employee_active, a.surname AS actor_surname, a.given_name AS actor_given_name FROM employee_leaves l JOIN employees e ON e.id = l.employee_id LEFT JOIN employees a ON a.id = l.actor_employee_id WHERE '.implode(' AND ', $where).' ORDER BY l.start_date DESC, l.id DESC',
            $params
        );
        foreach ($rows as &$row) {
            $row['employee_name'] = trim($row['surname'].' '.$row['given_name'].' '.$row['patronymic']) ?: (string) ($row['legacy_full_name'] ?? '');
            $row['actor_name'] = trim(($row['actor_surname'] ?? '').' '.($row['actor_given_name'] ?? ''));
            $row['display_id'] = (string) ($row['pass_code'] ?? str_pad((string) $row['employee_id'], 3, '0', STR_PAD_LEFT));
            $row['label'] = 'Отпуск';
        }
        return $rows;
    }

    public function find(int $id): array
    {
        $leave = $this->db->one('SELECT * FROM employee_leaves WHERE id = ?', [$id]);
        if ($leave === null) {
            throw new DomainException('Отпуск не найден.', 404);
        }
        return $leave;
    }

    public function save(array $data, int $actorId): array
    {
        $id = empty($data['id']) ? 0 : EmployeeService::id($data['id']);
        $employeeId = EmployeeService::id($data['employee_id'] ?? '');
        $from = ScheduleService::validateDate((string) ($data['start_date'] ?? ''));
        $to = ScheduleService::validateDate((string) ($data['end_date'] ?? ''));
        if ($to < $from) {
            throw new DomainException('Окончание отпуска раньше начала.', 400);
        }
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 3660) {
            throw new DomainException('Период отпуска не должен превышать десять лет.', 400);
        }
        $note = trim((string) ($data['note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            throw new DomainException('Примечание должно быть не длиннее 1000 символов.', 400);
        }
        return $this->db->transaction(function () use ($data, $id, $employeeId, $from, $to, $note, $actorId): array {
            // The employee lock serializes concurrent inserts and prevents overlapping ranges.
            $employee = $this->db->one('SELECT * FROM employees WHERE id = ?'.$this->lock(), [$employeeId]);
            if ($employee === null || (int) $employee['is_service'] === 1) {
                throw new DomainException('Сотрудник не найден.', 404);
            }
            $before = $id > 0 ? $this->db->one('SELECT * FROM employee_leaves WHERE id = ?'.$this->lock(), [$id]) : null;
            if ($id > 0 && ($before === null || (int) $before['employee_id'] !== $employeeId)) {
                throw new DomainException('Отпуск не найден у сотрудника.', 404);
            }
            if ($before !== null) {
                $this->checkVersion($before, $data);
                if (!(bool) $before['active']) {
                    throw new DomainException('Отменённый отпуск нельзя изменить. Создайте новый период.', 409);
                }
            }
            $this->checkEmployment($employee, $from, $to);
            if ($this->db->one('SELECT id FROM employee_leaves WHERE employee_id = ? AND active = 1 AND id <> ? AND start_date <= ? AND end_date >= ?'.$this->lock(), [$employeeId, $id, $to, $from]) !== null) {
                throw new DomainException('Период пересекается с уже назначенным отпуском.', 409);
            }
            $stamp = $this->stamp();
            $fields = ['employee_id' => $employeeId, 'kind' => 'vacation', 'start_date' => $from, 'end_date' => $to, 'note' => $note, 'active' => 1, 'version' => (int) ($before['version'] ?? 0) + 1, 'actor_employee_id' => $actorId, 'updated_at' => $stamp];
            if ($before === null) {
                $fields['created_at'] = $stamp;
                $this->db->insert('employee_leaves', $fields);
                $id = (int) $this->db->db->lastInsertId();
            } else {
                $this->update($fields, $id);
                $this->invalidate($employeeId, (string) $before['start_date'], (string) $before['end_date']);
            }
            $this->invalidate($employeeId, $from, $to);
            $saved = $this->find($id);
            $this->audit($actorId, 'leave.save', $id, $before, $saved);
            return $saved;
        });
    }

    public function revoke(array $data, int $actorId): array
    {
        $id = EmployeeService::id($data['id'] ?? '');
        $candidate = $this->find($id);
        return $this->db->transaction(function () use ($data, $id, $candidate, $actorId): array {
            $this->db->one('SELECT id FROM employees WHERE id = ?'.$this->lock(), [(int) $candidate['employee_id']]);
            $before = $this->db->one('SELECT * FROM employee_leaves WHERE id = ?'.$this->lock(), [$id]);
            if ($before === null) {
                throw new DomainException('Отпуск не найден.', 404);
            }
            $this->checkVersion($before, $data);
            if (!(bool) $before['active']) {
                return $before;
            }
            $this->update(['active' => 0, 'version' => (int) $before['version'] + 1, 'actor_employee_id' => $actorId, 'revoked_at' => $this->stamp(), 'updated_at' => $this->stamp()], $id);
            $this->invalidate((int) $before['employee_id'], (string) $before['start_date'], (string) $before['end_date']);
            $saved = $this->find($id);
            $this->audit($actorId, 'leave.revoke', $id, $before, $saved);
            return $saved;
        });
    }

    private function checkEmployment(array $employee, string $from, string $to): void
    {
        if ($from < (string) $employee['started_on'] || ($employee['ended_on'] !== null && $to > (string) $employee['ended_on'])) {
            throw new DomainException('Отпуск должен находиться в периоде работы сотрудника.', 400);
        }
        if ($this->db->one('SELECT id FROM employee_profile_versions WHERE employee_id = ? AND active = 0 AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)', [(int) $employee['id'], $to, $from]) !== null) {
            throw new DomainException('Период отпуска пересекает время, когда сотрудник не работал.', 400);
        }
    }

    private function checkVersion(array $before, array $data): void
    {
        if (!isset($data['version']) || (int) $data['version'] !== (int) $before['version']) {
            throw new DomainException('Отпуск изменился в другой сессии. Обновите страницу.', 409);
        }
    }

    private function invalidate(int $employeeId, string $from, string $to): void
    {
        $this->db->exec('DELETE FROM schedule_plan_cache WHERE employee_id = ? AND work_date >= ? AND work_date <= ?', [$employeeId, $from, $to]);
        // attendance_days.plan_json remains the original schedule snapshot. applyLeave overlays it on read.
    }

    private function audit(int $actorId, string $action, int $id, ?array $before, array $after): void
    {
        $this->db->insert('audit_log', ['actor_employee_id' => $actorId, 'action' => $action, 'entity_type' => 'employee_leave', 'entity_id' => (string) $id, 'before_json' => $before === null ? null : $this->json($before), 'after_json' => $this->json($after), 'created_at' => $this->stamp(), 'private' => 0]);
    }

    private function update(array $fields, int $id): void
    {
        $this->db->exec('UPDATE employee_leaves SET '.implode(', ', array_map(static fn (string $key): string => $key.' = ?', array_keys($fields))).' WHERE id = ?', [...array_values($fields), $id]);
    }

    private function stamp(): string { return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    private function lock(): string { return str_contains(strtolower(get_class($this->db->db->getDatabasePlatform())), 'sqlite') ? '' : ' FOR UPDATE'; }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}

<?php
declare(strict_types=1);

/** Isolated SQLite tests for actual vacation and schedule services; never reads DATABASE_URL. */
namespace App\Infrastructure {
    final class LeaveSQLitePlatform {}
    final class LeaveSQLiteConnection
    {
        public function __construct(public readonly \PDO $pdo) {}
        public function getDatabasePlatform(): LeaveSQLitePlatform { return new LeaveSQLitePlatform(); }
        public function lastInsertId(): string { return $this->pdo->lastInsertId(); }
    }
    final class Database
    {
        public readonly LeaveSQLiteConnection $db;
        public function __construct() { $pdo = new \PDO('sqlite::memory:'); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); $pdo->exec('PRAGMA foreign_keys = ON'); $this->db = new LeaveSQLiteConnection($pdo); }
        public function all(string $sql, array $args = []): array { $query = $this->db->pdo->prepare($sql); $query->execute($args); return $query->fetchAll(\PDO::FETCH_ASSOC); }
        public function one(string $sql, array $args = []): ?array { return $this->all($sql, $args)[0] ?? null; }
        public function scalar(string $sql, array $args = []): mixed { $query = $this->db->pdo->prepare($sql); $query->execute($args); return $query->fetchColumn(); }
        public function exec(string $sql, array $args = []): int { $query = $this->db->pdo->prepare($sql); $query->execute($args); return $query->rowCount(); }
        public function insert(string $table, array $data): int { return $this->exec('INSERT INTO '.$table.' ('.implode(',', array_keys($data)).') VALUES ('.implode(',', array_fill(0, count($data), '?')).')', array_values($data)); }
        public function transaction(callable $operation): mixed { $this->db->pdo->beginTransaction(); try { $value = $operation(); $this->db->pdo->commit(); return $value; } catch (\Throwable $error) { $this->db->pdo->rollBack(); throw $error; } }
    }
}
namespace {
    require dirname(__DIR__).'/src/Service/Settings.php';
    require dirname(__DIR__).'/src/Service/EmployeeService.php';
    require dirname(__DIR__).'/src/Service/ScheduleService.php';
    require dirname(__DIR__).'/src/Service/LeaveService.php';
    $db = new \App\Infrastructure\Database();
    $schema = file_get_contents(dirname(__DIR__).'/migrations/schema.sql');
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    $schema = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $schema);
    $schema = preg_replace('/ENGINE=InnoDB[^;]*/', '', $schema);
    $schema = preg_replace('/\bENUM\([^)]*\)/', 'TEXT', $schema);
    $schema = preg_replace('/\b(?:BIGINT|INT|TINYINT)\b(?:\s+UNSIGNED)?/', 'INTEGER', $schema);
    $schema = str_replace(['AUTO_INCREMENT', 'INSERT IGNORE', 'JSON'], ['', 'INSERT OR IGNORE', 'TEXT'], $schema);
    $schema = preg_replace('/^\s*KEY\s+[^\n]+\n/m', '', $schema);
    $schema = preg_replace('/UNIQUE KEY\s+\w+\s*\(/', 'UNIQUE (', $schema);
    $schema = preg_replace('/,\s*\)/', ')', $schema);
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $schema))) as $sql) { $db->exec($sql); }
    $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-21T12:00:00Z');
    $db->insert('app_settings', ['setting_key' => 'history_start', 'value_json' => '"2026-10-01"']);
    foreach ([1, 2, 3] as $id) {
        $db->insert('employees', ['id' => $id, 'pass_code' => str_pad((string) $id, 3, '0', STR_PAD_LEFT), 'surname' => 'Тест', 'given_name' => 'Сотрудник', 'active' => $id === 2 ? 0 : 1, 'is_service' => $id === 3 ? 1 : 0, 'started_on' => '2026-09-01', 'ended_on' => $id === 2 ? '2026-10-15' : null]);
        $db->insert('employee_profile_versions', ['employee_id' => $id, 'surname' => 'Тест', 'given_name' => 'Сотрудник', 'active' => 1, 'valid_from' => '2026-09-01', 'valid_to' => $id === 2 ? '2026-10-15' : null]);
        if ($id === 2) { $db->insert('employee_profile_versions', ['employee_id' => 2, 'surname' => 'Тест', 'given_name' => 'Сотрудник', 'active' => 0, 'valid_from' => '2026-10-16']); }
    }
    $db->insert('schedule_groups', ['id' => 1, 'name' => 'Офис', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'weekdays' => '[1,2,3,4,5]', 'effective_from' => '2026-09-01']);
    foreach ([1, 2] as $id) { $db->insert('schedule_assignments', ['employee_id' => $id, 'group_id' => 1, 'valid_from' => '2026-09-01']); }
    $schedule = new \App\Service\ScheduleService($db, new \App\Service\Settings($db), $clock);
    $leaves = new \App\Service\LeaveService($db, $clock);
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void { ++$checks; if (!$condition) { throw new \RuntimeException($message); } };
    $fails = static function (callable $operation, int $code, string $message) use ($check): void { try { $operation(); $check(false, $message); } catch (\DomainException $error) { $check($error->getCode() === $code, $message.' (wrong status)'); } };
    $base = $schedule->plan(1, '2026-10-12');
    $check($base['is_working'] && !$base['on_leave'], 'Baseline plan must be a working day');
    foreach (['2026-10-11', '2026-10-13', '2026-10-14', '2026-10-15'] as $date) { $schedule->plan(1, $date); }
    $snapshot = json_encode($base, JSON_THROW_ON_ERROR);
    $db->insert('attendance_days', ['employee_id' => 1, 'work_date' => '2026-10-12', 'arrival_at' => '2026-10-11 22:35:00', 'plan_json' => $snapshot]);
    $db->insert('attendance_events', ['employee_id' => 1, 'work_date' => '2026-10-12', 'event_type' => 'arrival', 'event_at' => '2026-10-11 22:35:00', 'received_at' => '2026-10-11 22:35:00', 'source' => 'qr', 'request_id' => 'leave-fact']);
    $leave = $leaves->save(['employee_id' => 1, 'start_date' => '2026-10-12', 'end_date' => '2026-10-14'], 1);
    $check((int) $leave['version'] === 1 && $leave['note'] === '', 'New leave with optional note failed');
    $check((int) $db->scalar('SELECT COUNT(*) FROM schedule_plan_cache WHERE employee_id=1 AND work_date IN (?,?,?)', ['2026-10-12', '2026-10-13', '2026-10-14']) === 0, 'Covered plan cache was not invalidated');
    $check((int) $db->scalar('SELECT COUNT(*) FROM schedule_plan_cache WHERE employee_id=1 AND work_date=?', ['2026-10-15']) === 1, 'Cache outside vacation was invalidated');
    $check($db->scalar('SELECT plan_json FROM attendance_days WHERE employee_id=1') === $snapshot, 'Vacation rewrote frozen schedule snapshot');
    $covered = $schedule->plan(1, '2026-10-12');
    $check($covered['on_leave'] && !$covered['is_working'] && $covered['schedule_is_working'], 'Retroactive leave did not overlay an already observed plan');
    $check($covered['leave_id'] === (int) $leave['id'] && $covered['leave_label'] === 'Отпуск' && $covered['leave_from'] === '2026-10-12' && $covered['leave_to'] === '2026-10-14', 'Leave metadata mismatch');
    $check($covered['start_time'] === '09:00' && $covered['end_time'] === '18:00', 'Vacation changed underlying work hours');
    $check($schedule->plan(1, '2026-10-14')['on_leave'] && !$schedule->plan(1, '2026-10-15')['on_leave'], 'Vacation end date must be inclusive');
    $check($schedule->applyLeave($base)['on_leave'], 'Frozen attendance-day plan did not receive fresh vacation overlay');
    $check((int) $db->scalar('SELECT COUNT(*) FROM attendance_events') === 1, 'Vacation deleted an attendance fact');
    $fails(fn () => $leaves->save(['employee_id' => 1, 'start_date' => '2026-10-14', 'end_date' => '2026-10-16'], 1), 409, 'Inclusive overlapping periods were accepted');
    $adjacent = $leaves->save(['employee_id' => 1, 'start_date' => '2026-10-15', 'end_date' => '2026-10-15'], 1);
    $check((int) $adjacent['id'] !== (int) $leave['id'], 'Adjacent non-overlapping leave was rejected');
    $fails(fn () => $leaves->save(['id' => $leave['id'], 'version' => 99, 'employee_id' => 1, 'start_date' => '2026-10-12', 'end_date' => '2026-10-14'], 1), 409, 'Stale edit was accepted');
    $fails(fn () => $leaves->save(['employee_id' => 1, 'start_date' => '2026-02-30', 'end_date' => '2026-03-01'], 1), 400, 'Malformed date was accepted');
    $fails(fn () => $leaves->save(['employee_id' => 1, 'start_date' => '2026-10-18', 'end_date' => '2026-10-17'], 1), 400, 'Reversed period was accepted');
    $fails(fn () => $leaves->save(['employee_id' => 1, 'start_date' => '2026-08-31', 'end_date' => '2026-09-01'], 1), 400, 'Vacation before employment was accepted');
    $db->insert('employee_profile_versions', ['employee_id' => 1, 'surname' => 'Тест', 'given_name' => 'Сотрудник', 'active' => 0, 'valid_from' => '2026-09-15', 'valid_to' => '2026-09-17']);
    $fails(fn () => $leaves->save(['employee_id' => 1, 'start_date' => '2026-09-14', 'end_date' => '2026-09-16'], 1), 400, 'Vacation across a former dismissal gap was accepted');
    $fails(fn () => $leaves->save(['employee_id' => 3, 'start_date' => '2026-10-12', 'end_date' => '2026-10-13'], 1), 404, 'Service employee received vacation');
    $retired = $leaves->save(['employee_id' => 2, 'start_date' => '2026-10-12', 'end_date' => '2026-10-15', 'note' => 'Приказ № 1'], 1);
    $check($schedule->plan(2, '2026-10-15')['on_leave'], 'Retroactive vacation for retired employee was rejected');
    $fails(fn () => $leaves->save(['employee_id' => 2, 'start_date' => '2026-10-16', 'end_date' => '2026-10-17'], 1), 400, 'Vacation after dismissal was accepted');
    $edited = $leaves->save(['id' => $leave['id'], 'version' => $leave['version'], 'employee_id' => 1, 'start_date' => '2026-10-11', 'end_date' => '2026-10-12', 'note' => 'Исправленный период'], 1);
    $check((int) $edited['version'] === 2, 'Edit did not advance optimistic version');
    $check(!$schedule->plan(1, '2026-10-13')['on_leave'] && $schedule->plan(1, '2026-10-11')['on_leave'], 'Editing vacation did not invalidate old and new periods');
    $check((int) $db->scalar('SELECT COUNT(*) FROM attendance_events') === 1 && $db->scalar('SELECT arrival_at FROM attendance_days WHERE employee_id=1') === '2026-10-11 22:35:00', 'Vacation edit changed raw attendance');
    $fails(fn () => $leaves->revoke(['id' => $edited['id'], 'version' => 1], 1), 409, 'Stale revoke was accepted');
    $revoked = $leaves->revoke(['id' => $edited['id'], 'version' => $edited['version']], 1);
    $restored = $schedule->applyLeave($covered);
    $check(!$restored['on_leave'] && $restored['is_working'] && $restored['leave_id'] === null && $restored['leave_label'] === '', 'Revocation did not restore working plan from already overlaid snapshot');
    $check($schedule->plan(1, '2026-10-12')['is_working'], 'Revocation did not invalidate cached vacation plan');
    $check($db->scalar('SELECT plan_json FROM attendance_days WHERE employee_id=1') === $snapshot, 'Revocation rewrote frozen schedule');
    $fails(fn () => $leaves->save(['id' => $revoked['id'], 'version' => $revoked['version'], 'employee_id' => 1, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'], 1), 409, 'Revoked leave was edited');
    $beforeHistory = $schedule->plan(1, '2026-09-28');
    $check(!$beforeHistory['is_working'] && $beforeHistory['schedule_is_working'], 'History boundary was lost by leave overlay');
    $check(!$schedule->applyLeave($beforeHistory)['is_working'], 'Repeated overlay resurrected a day before history boundary');
    $check(count($leaves->listing(['state' => 'revoked'])) === 1 && count($leaves->listing(['employee_id' => 2])) === 1, 'Vacation listing/filter mismatch');
    $check((int) $db->scalar("SELECT COUNT(*) FROM audit_log WHERE action='leave.save'") === 4 && (int) $db->scalar("SELECT COUNT(*) FROM audit_log WHERE action='leave.revoke'") === 1, 'Vacation audit is missing or failed operations were audited');
    echo json_encode(['passed' => $checks, 'failed' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}

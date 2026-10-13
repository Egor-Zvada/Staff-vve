<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class AttendanceService
{
    private const FIELDS = ['arrival', 'departure', 'lunch_out', 'lunch_in', 'excused_out'];
    private const DERIVED = ['LATE_ARRIVAL', 'EARLY_DEPARTURE', 'MISSING_ARRIVAL', 'MISSING_DEPARTURE', 'NO_MARKS', 'INCOMPLETE_BREAK', 'INVALID_ORDER'];
    private readonly Closure $clock;
    private readonly IdentifierService $identifiers;

    public function __construct(private readonly Database $db, private readonly Settings $settings, private readonly ScheduleService $schedules, ?Closure $clock = null, ?IdentifierService $identifiers = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->identifiers = $identifiers ?? new IdentifierService($db);
    }

    public function mark(array $payload, string $source = 'qr', ?int $actorId = null): array
    {
        $code = IdentifierService::normalizeInput((string) ($payload['code'] ?? $payload['employee_id'] ?? ''));
        $requestedType = strtoupper(trim((string) ($payload['event_type'] ?? 'AUTO')));
        $now = $this->now();
        $date = $this->local($now)->format('Y-m-d');
        return $this->request($payload, ['action' => 'mark', 'code' => $code, 'event_type' => $requestedType, 'source' => $source, 'actor' => $actorId], function (string $requestId) use ($payload, $code, $requestedType, $source, $actorId, $now, $date): array {
            [$employeeId, $credentialId, $actualSource] = $this->resolveCode($code, $source);
            $employee = $this->employee($employeeId, true);
            $day = $this->lockDay($employeeId, $date);
            $plan = $this->dayPlan($day);
            $policy = $this->schedules->commandPolicy($plan);
            $type = $requestedType === 'AUTO' ? $this->autoType($now, $policy) : $this->field(strtolower($requestedType));
            $this->checkExtra($type, $policy);
            $window = (int) $policy['duplicate_seconds'];
            $previous = $window > 0 ? $this->db->one('SELECT * FROM attendance_events WHERE employee_id = ? AND work_date = ? AND event_type = ? AND private = 0 AND received_at >= ? ORDER BY received_at DESC, id DESC LIMIT 1', [$employeeId, $date, $type, $now->modify('-'.$window.' seconds')->format('Y-m-d H:i:s')]) : null;
            if ($previous !== null) {
                $this->evaluate($employeeId, $date, $day, $plan, $now);
                return $this->markResponse($employee, $date, $type, $this->moment((string) $previous['event_at']), true, (int) $previous['id']);
            }
            $this->ensureDay($employeeId, $date);
            $context = $this->context($payload) + ['credential_id' => $credentialId, 'actor_employee_id' => $actorId];
            $this->db->insert('attendance_events', ['employee_id' => $employeeId, 'work_date' => $date, 'event_type' => $type, 'event_at' => $this->stamp($now), 'received_at' => $this->stamp($now), 'source' => $actualSource, 'request_id' => $requestId, 'private' => 0, 'context_json' => $this->json($context)]);
            $eventId = (int) $this->db->db->lastInsertId();
            $day = $this->project($employeeId, $date, $this->decode($day['overrides_json']), (int) $day['version'] + 1, $plan);
            $this->evaluate($employeeId, $date, $day, $plan, $now);
            return $this->markResponse($employee, $date, $type, $now, false, $eventId);
        });
    }

    public function manual(array $payload, ?int $actorId = null): array
    {
        $employeeId = $this->positiveId($payload['employee_id'] ?? '');
        $employee = $this->employee($employeeId, true);
        $code = trim((string) ($payload['code'] ?? ''));
        if ($code !== '') {
            $resolved = $this->identifiers->resolveCode($code);
            if ((int) $resolved['id'] !== $employeeId) { throw new DomainException('Пропуск относится к другому сотруднику.', 400); }
        } elseif (!$this->manualActorMayBypass($actorId) && !ctype_digit(IdentifierService::codeOf($employee))) {
            throw new DomainException('Для ручной отметки укажите действующий код пропуска.', 400);
        }
        $date = ScheduleService::validateDate((string) ($payload['work_date'] ?? $payload['date'] ?? ''));
        if (!array_key_exists('version', $payload) || !is_numeric($payload['version']) || (int) $payload['version'] < 0) {
            throw new DomainException('Нужна актуальная версия выбранного дня.', 400);
        }
        $values = $this->normalizeValues($payload['values'] ?? null);
        $now = $this->now();
        if ($date > $this->local($now)->format('Y-m-d')) {
            throw new DomainException('Будущую посещаемость записать нельзя.', 400);
        }
        return $this->request($payload, ['action' => 'manual', 'employee_id' => $employeeId, 'code' => $code, 'date' => $date, 'version' => (int) $payload['version'], 'values' => $values, 'actor' => $actorId], function (string $requestId) use ($payload, $employeeId, $code, $date, $values, $actorId, $now): array {
            $employee = $this->employee($employeeId, true);
            if ($code !== '' && (int) $this->identifiers->resolveCode($code)['id'] !== $employeeId) { throw new DomainException('Пропуск относится к другому сотруднику.', 400); }
            if ($code === '' && !$this->manualActorMayBypass($actorId) && !ctype_digit(IdentifierService::codeOf($employee))) { throw new DomainException('Для ручной отметки укажите действующий код пропуска.', 400); }
            $day = $this->lockDay($employeeId, $date);
            if ((int) $payload['version'] !== (int) $day['version']) {
                throw new DomainException('Табель уже изменился. Загрузите актуальные значения и повторите правку.', 409);
            }
            $plan = $this->dayPlan($day);
            $policy = $this->schedules->commandPolicy($plan);
            $overrides = $this->decode($day['overrides_json']);
            $changed = [];
            foreach ($values as $field => $input) {
                $this->checkExtra($field, $policy);
                $before = $day[$field.'_at'];
                if ($this->sameValue($before, $input, $date)) {
                    continue;
                }
                $after = $input === null ? null : $this->inputMoment($date, $input)->format('Y-m-d H:i:s');
                if ($after !== null && $this->moment($after) > $now) {
                    throw new DomainException('Фактическое время не может быть в будущем.', 400);
                }
                $delay = $after === null ? null : $now->getTimestamp() - $this->moment($after)->getTimestamp();
                $onTimeFirst = $before === null && $after !== null && $date === $this->local($now)->format('Y-m-d') && $delay >= 0 && $delay <= (int) $policy['manual_lag_seconds'] && !$this->db->scalar('SELECT COUNT(*) FROM attendance_events WHERE employee_id = ? AND work_date = ? AND event_type = ?', [$employeeId, $date, $field]);
                $this->ensureDay($employeeId, $date);
                if ($onTimeFirst) {
                    // A timely declaration is normal; its original punctuality moment is still server receipt.
                    $context = $this->context($payload) + ['supplied_at' => $after, 'actor_employee_id' => $actorId, 'input_precision' => strlen((string) $input) === 5 ? 'minute' : 'second'];
                    $this->db->insert('attendance_events', ['employee_id' => $employeeId, 'work_date' => $date, 'event_type' => $field, 'event_at' => $this->stamp($now), 'received_at' => $this->stamp($now), 'source' => 'manual_on_time', 'request_id' => $requestId, 'private' => 0, 'context_json' => $this->json($context)]);
                    $overrides[$field] = ['value' => $after, 'event_id' => (int) $this->db->db->lastInsertId(), 'change_id' => null];
                } else {
                    $changeId = $this->change($employeeId, $date, $field, $before, $after, $now, $actorId === null ? 'public_manual' : 'admin_manual', $actorId, $payload, $requestId, false);
                    $overrides[$field] = ['value' => $after, 'change_id' => $changeId];
                    $detail = ['change_id' => $changeId, 'before_at' => $before, 'after_at' => $after, 'received_at' => $this->stamp($now), 'delay_seconds' => $delay, 'manual_lag_seconds' => (int) $policy['manual_lag_seconds'], 'actor_employee_id' => $actorId];
                    $this->flag($employeeId, $date, 'CORRECTION', $field, 'change:'.$changeId, $detail, $now);
                    if ($date < $this->local($now)->format('Y-m-d')) {
                        $this->flag($employeeId, $date, 'PRIOR_DAY_CHANGE', $field, 'prior:'.$changeId, $detail, $now);
                    }
                    if ($delay !== null && $delay > (int) $policy['manual_lag_seconds']) {
                        $this->flag($employeeId, $date, 'EARLIER_TIME_CLAIM', $field, 'earlier:'.$changeId, $detail, $now);
                    }
                }
                $changed[] = $field;
            }
            if ($changed !== []) {
                $day = $this->project($employeeId, $date, $overrides, (int) $day['version'] + 1, $plan);
                $this->evaluate($employeeId, $date, $day, $plan, $now);
            }
            $result = $this->serializeDay($employeeId, $date, $day);
            return ['success' => true, 'day' => $result, 'version' => $result['version'], 'flags' => $result['flags'], 'changed' => $changed, 'noop' => $changed === []];
        });
    }

    public function day(int $id, string $date): array
    {
        $this->employee($id, false);
        ScheduleService::validateDate($date);
        $this->recheckEmployee($id, $date);
        return $this->serializeDay($id, $date);
    }

    public function period(string $from, string $to, ?int $employeeId = null): array
    {
        $dates = $this->dates($from, $to);
        if ($employeeId !== null) { $this->employee($employeeId, false); }
        $rows = [];
        foreach ($dates as $date) {
            $people = $employeeId !== null ? [['id' => $employeeId]] : $this->employeesForDate($date);
            foreach ($people as $person) {
                $id = (int) $person['id'];
                $this->recheckEmployee($id, $date);
                $row = $this->serializeDay($id, $date);
                if ($row['is_working'] || $row['has_values'] || $row['events'] !== [] || $row['changes'] !== []) {
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    public function dashboard(string $date): array
    {
        $rows = $this->period($date, $date);
        $summary = ['employees' => count($rows), 'planned' => 0, 'present' => 0, 'arrivals' => 0, 'late' => 0, 'early' => 0, 'missing' => 0, 'corrected' => 0];
        $present = $missing = $flags = [];
        foreach ($rows as $row) {
            $summary['planned'] += $row['is_working'] ? 1 : 0;
            if ($row['present']) { $summary['present']++; $present[] = $row; }
            $kinds = array_column($row['flags'], 'kind');
            $arrivalChanges = array_filter($row['changes'], static fn (array $change): bool => $change['event_type'] === 'arrival');
            if ($row['is_working'] && ($row['raw_values']['arrival'] !== null || ($row['values']['arrival'] !== null && $arrivalChanges === [] && !in_array('MISSING_ARRIVAL', $kinds, true)))) { $summary['arrivals']++; }
            $summary['late'] += in_array('LATE_ARRIVAL', $kinds, true) ? 1 : 0;
            $summary['early'] += in_array('EARLY_DEPARTURE', $kinds, true) ? 1 : 0;
            if (count(array_intersect($kinds, ['MISSING_ARRIVAL', 'MISSING_DEPARTURE', 'NO_MARKS'])) > 0) { $summary['missing']++; $missing[] = $row; }
            $summary['corrected'] += in_array('CORRECTION', $kinds, true) ? 1 : 0;
            foreach ($row['flags'] as $flag) { $flags[] = $flag + ['employee_id' => $row['employee_id'], 'full_name' => $row['full_name'], 'work_date' => $date]; }
        }
        $summary['late_percent'] = $summary['arrivals'] > 0 ? round(100 * $summary['late'] / $summary['arrivals'], 1) : null;
        return ['date' => $date, 'rows' => $rows, 'summary' => $summary, 'present' => $present, 'missing' => $missing, 'flags' => $flags,
            'present_count' => $summary['present'], 'late_count' => $summary['late'], 'early_count' => $summary['early'], 'missing_count' => $summary['missing'], 'changed_count' => $summary['corrected']];
    }

    public function recheckDate(string $date): void
    {
        ScheduleService::validateDate($date);
        foreach ($this->employeesForDate($date) as $person) { $this->recheckEmployee((int) $person['id'], $date); }
    }

    /** Caller MUST authenticate an independent owner session before invoking this method. */
    public function ownerAdjust(array $payload): array
    {
        $employeeId = $this->positiveId($payload['employee_id'] ?? '');
        $date = ScheduleService::validateDate((string) ($payload['work_date'] ?? $payload['date'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 2000) { throw new DomainException('Нужна внутренняя причина длиной до 2000 символов.', 400); }
        $action = strtolower((string) ($payload['action'] ?? ''));
        if (!in_array($action, ['suppress', 'correct', 'restore'], true)) { throw new DomainException('Неизвестное служебное действие.', 400); }
        $payload['request_id'] ??= 'owner-'.bin2hex(random_bytes(16));
        $now = $this->now();
        return $this->request($payload, ['action' => 'owner_'.$action, 'payload' => $payload], function (string $requestId) use ($payload, $employeeId, $date, $reason, $action, $now): array {
            $this->employee($employeeId, false);
            $day = $this->lockDay($employeeId, $date);
            if (isset($payload['version']) && (int) $payload['version'] !== (int) $day['version']) { throw new DomainException('День изменился; обновите служебный экран.', 409); }
            $before = $this->privateSnapshot($employeeId, $date);
            $plan = $this->dayPlan($day);
            $overrides = $this->decode($day['overrides_json']);
            $affected = ['flags' => 0, 'changes' => 0, 'events' => 0];
            if ($action === 'correct') {
                $values = $this->normalizeValues($payload['values'] ?? null);
                if ($values === []) { throw new DomainException('Выберите официальные значения для изменения.', 400); }
                $this->ensureDay($employeeId, $date);
                foreach ($values as $field => $input) {
                    $after = $input === null ? null : $this->inputMoment($date, $input)->format('Y-m-d H:i:s');
                    if (!$this->sameValue($day[$field.'_at'], $input, $date)) {
                        $changeId = $this->change($employeeId, $date, $field, $day[$field.'_at'], $after, $now, 'owner_adjust', null, [], $requestId, true);
                        $overrides[$field] = ['value' => $after, 'change_id' => $changeId, 'private' => true];
                    }
                    $scope = $this->scopeForFields($employeeId, $date, [$field]);
                    $counts = $this->visibility($employeeId, $date, $scope, true, $reason, $now);
                    foreach ($counts as $key => $count) { $affected[$key] += $count; }
                }
            } elseif ($action === 'restore' && !empty($payload['fields'])) {
                $fields = array_map(fn (mixed $field): string => $this->field((string) $field), (array) $payload['fields']);
                $this->ensureDay($employeeId, $date);
                foreach ($fields as $field) {
                    unset($overrides[$field]);
                }
                $affected = $this->visibility($employeeId, $date, $this->scopeForFields($employeeId, $date, $fields), false, $reason, $now);
            } else {
                $scope = $this->ownerScope($payload, $employeeId, $date);
                $affected = $this->visibility($employeeId, $date, $scope, $action === 'suppress', $reason, $now);
            }
            $this->ensureDay($employeeId, $date);
            $day = $this->project($employeeId, $date, $overrides, (int) $day['version'] + 1, $plan);
            $this->evaluate($employeeId, $date, $day, $plan, $now);
            $after = $this->privateSnapshot($employeeId, $date);
            $this->db->insert('private_service_events', ['action' => $action, 'employee_id' => $employeeId, 'work_date' => $date, 'before_json' => $this->json($before), 'after_json' => $this->json($after), 'reason' => $reason, 'created_at' => $this->stamp($now)]);
            return ['success' => true, 'affected' => $affected, 'day' => $this->privateDay($employeeId, $date), 'version' => (int) $day['version']];
        });
    }

    /** Private response: never use it in an ordinary route/export. */
    public function privateDay(int $id, string $date): array
    {
        $ordinary = $this->serializeDay($id, ScheduleService::validateDate($date));
        $snapshot = $this->privateSnapshot($id, $date);
        $ordinary['events'] = array_map(fn (array $event): array => $this->serializeEvent($event, true), $snapshot['events']);
        $ordinary['changes'] = array_map(fn (array $change): array => $this->serializeChange($change, true), $snapshot['changes']);
        $ordinary['flags'] = array_map(fn (array $flag): array => $this->serializeFlag($flag, true), $snapshot['flags']);
        $ordinary['resolutions'] = $this->db->all('SELECT * FROM private_resolutions WHERE employee_id = ? AND work_date = ? ORDER BY id', [$id, $date]);
        $ordinary['service_events'] = $this->db->all('SELECT * FROM private_service_events WHERE employee_id = ? AND work_date = ? ORDER BY id', [$id, $date]);
        foreach (['resolutions', 'service_events'] as $key) {
            foreach ($ordinary[$key] as &$record) { $record['created_at'] = $this->iso($record['created_at']); }
            unset($record);
        }
        $ordinary['raw_values'] = $this->rawValues($snapshot['events']);
        return $ordinary;
    }

    private function request(array $payload, array $hashPayload, callable $work): array
    {
        $id = (string) ($payload['request_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $id)) { throw new DomainException('Нужен уникальный request_id длиной от 8 до 128 символов.', 400); }
        $hash = hash('sha256', $this->json($this->canonical($hashPayload)));
        return $this->db->transaction(function () use ($id, $hash, $work, $hashPayload): array {
            $this->insertIgnore('operation_requests', ['request_id' => $id, 'payload_hash' => $hash, 'response_json' => null, 'created_at' => $this->stamp($this->now())]);
            $request = $this->db->one('SELECT * FROM operation_requests WHERE request_id = ?'.$this->lock(), [$id]);
            if ($request === null || !hash_equals((string) $request['payload_hash'], $hash)) { throw new DomainException('request_id уже использован для другой операции.', 409); }
            if ($request['response_json'] !== null) {
                $saved = $this->decode($request['response_json']);
                return str_starts_with((string) ($hashPayload['action'] ?? ''), 'owner_') ? $saved : $this->visibleReplay($saved);
            }
            $result = $work($id);
            $this->db->exec('UPDATE operation_requests SET response_json = ? WHERE request_id = ?', [$this->json($result), $id]);
            return $result;
        });
    }

    private function resolveCode(string $code, string $source): array
    {
        if (str_starts_with(strtoupper($code), 'NFC:')) {
            $uid = strtoupper(preg_replace('/[\s:\-]/', '', substr($code, 4)) ?? '');
            if (!preg_match('/^[0-9A-F]{8,64}$/D', $uid) || strlen($uid) % 2 !== 0) { throw new DomainException('Некорректный NFC UID.', 400); }
            $card = $this->db->one('SELECT * FROM employee_credentials WHERE kind = ? AND value = ? AND active = 1 AND revoked_at IS NULL', ['NFC_UID', $uid]);
            if ($card === null) { throw new DomainException('NFC-карта не зарегистрирована или отозвана.', 404); }
            return [(int) $card['employee_id'], (int) $card['id'], 'nfc'];
        }
        return [(int) $this->identifiers->resolveCode($code)['id'], null, substr($source, 0, 48)];
    }

    private function employee(int $id, bool $active): array
    {
        $employee = $this->db->one('SELECT * FROM employees WHERE id = ?'.($active ? $this->lock() : ''), [$id]);
        if ($employee === null || (int) $employee['is_service'] === 1) { throw new DomainException('Сотрудник не найден.', 404); }
        if ($active && !(bool) $employee['active']) { throw new DomainException('Сотрудник неактивен; текущие отметки недоступны.', 409); }
        return $employee;
    }

    private function manualActorMayBypass(?int $actorId): bool
    {
        return $actorId !== null && (bool) $this->db->scalar('SELECT COUNT(*) FROM employees WHERE id = ? AND active = 1 AND admin_role = ?', [$actorId, 'ADMIN']);
    }

    private function actorCode(int $actorId): ?string
    {
        $employee = $this->db->one('SELECT id, pass_code FROM employees WHERE id = ?', [$actorId]);
        return $employee === null ? null : IdentifierService::codeOf($employee);
    }

    private function employeeAtDate(int $id, string $date): array
    {
        $employee = $this->employee($id, false);
        $version = $this->db->one('SELECT * FROM employee_profile_versions WHERE employee_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from DESC, id DESC LIMIT 1', [$id, $date, $date]);
        if ($version !== null) {
            foreach (['surname', 'given_name', 'patronymic', 'position', 'active'] as $key) { $employee[$key] = $version[$key]; }
        }
        return $employee;
    }

    private function employeesForDate(string $date): array
    {
        return $this->db->all('SELECT e.* FROM employees e WHERE e.is_service = 0 AND ((e.started_on <= ? AND (e.ended_on IS NULL OR e.ended_on >= ?) AND (e.active = 1 OR e.ended_on IS NOT NULL)) OR EXISTS (SELECT 1 FROM attendance_days d WHERE d.employee_id = e.id AND d.work_date = ?)) ORDER BY e.surname, e.given_name, e.id', [$date, $date, $date]);
    }

    private function lockDay(int $id, string $date): array
    {
        // Employee lock also serializes creation of a currently absent employee/day row.
        $this->db->one('SELECT id FROM employees WHERE id = ?'.$this->lock(), [$id]);
        return $this->db->one('SELECT * FROM attendance_days WHERE employee_id = ? AND work_date = ?'.$this->lock(), [$id, $date]) ?? $this->emptyDay($id, $date);
    }

    private function ensureDay(int $id, string $date): void
    {
        $this->insertIgnore('attendance_days', ['employee_id' => $id, 'work_date' => $date, 'version' => 0, 'overrides_json' => '{}']);
    }

    private function emptyDay(int $id, string $date): array
    {
        return ['id' => null, 'employee_id' => $id, 'work_date' => $date, 'version' => 0, 'overrides_json' => '{}', 'plan_json' => null] + array_fill_keys(array_map(static fn (string $field): string => $field.'_at', self::FIELDS), null);
    }

    private function dayPlan(array $day): array
    {
        return $this->schedules->applyHistoryBoundary($day['plan_json'] !== null ? $this->decode($day['plan_json']) : $this->schedules->plan((int) $day['employee_id'], (string) $day['work_date']));
    }

    private function project(int $id, string $date, array $overrides, int $version, array $plan): array
    {
        $events = $this->db->all('SELECT * FROM attendance_events WHERE employee_id = ? AND work_date = ? ORDER BY event_at, id', [$id, $date]);
        $raw = $this->rawSelection($events);
        $values = [];
        foreach (self::FIELDS as $field) { $values[$field.'_at'] = array_key_exists($field, $overrides) ? $overrides[$field]['value'] : ($raw[$field]['event_at'] ?? null); }
        $values += ['overrides_json' => $this->json($overrides === [] ? new \stdClass() : $overrides), 'version' => $version, 'plan_json' => $this->json($plan)];
        $this->update('attendance_days', $values, 'employee_id = ? AND work_date = ?', [$id, $date]);
        return $this->db->one('SELECT * FROM attendance_days WHERE employee_id = ? AND work_date = ?', [$id, $date]) ?? $this->emptyDay($id, $date);
    }

    private function recheckEmployee(int $id, string $date): void
    {
        $this->db->transaction(function () use ($id, $date): void {
            $day = $this->lockDay($id, $date);
            $plan = $this->dayPlan($day);
            $now = $this->now();
            if ($date > $this->local($now)->format('Y-m-d')) { return; }
            if ($day['id'] !== null || $plan['is_working']) {
                $this->ensureDay($id, $date);
                if ($day['plan_json'] === null) {
                    $this->db->exec('UPDATE attendance_days SET plan_json = ? WHERE employee_id = ? AND work_date = ?', [$this->json($plan), $id, $date]);
                    $day['plan_json'] = $this->json($plan);
                }
                $this->evaluate($id, $date, $day, $plan, $now);
            }
        });
    }

    private function evaluate(int $id, string $date, array $day, array $plan, DateTimeImmutable $now): void
    {
        $events = $this->db->all('SELECT * FROM attendance_events WHERE employee_id = ? AND work_date = ? ORDER BY event_at, id', [$id, $date]);
        $allRaw = $this->rawSelection($events);
        $raw = $this->rawSelection(array_values(array_filter($events, static fn (array $event): bool => !(bool) $event['private'])));
        $this->db->exec('UPDATE attendance_flags SET active = 0 WHERE employee_id = ? AND work_date = ? AND kind IN ('.implode(',', array_fill(0, count(self::DERIVED), '?')).')', [$id, $date, ...self::DERIVED]);
        $basis = array_intersect_key($plan, array_flip(['employee_id', 'work_date', 'is_working', 'start_utc', 'end_utc', 'late_grace_seconds', 'early_grace_seconds', 'group_id', 'group_version_id', 'assignment_id', 'exception_id', 'history_start']));
        $hash = substr(hash('sha256', $this->json($this->canonical($basis))), 0, 32);
        $arrival = $raw['arrival'] ?? null;
        $departure = $raw['departure'] ?? ($raw['excused_out'] ?? null);
        $overrides = $this->decode($day['overrides_json']);
        $hasArrival = isset($allRaw['arrival']) || (!empty($overrides['arrival']['private']) && ($overrides['arrival']['value'] ?? null) !== null);
        $hasDeparture = isset($allRaw['departure']) || isset($allRaw['excused_out']) || (!empty($overrides['departure']['private']) && ($overrides['departure']['value'] ?? null) !== null) || (!empty($overrides['excused_out']['private']) && ($overrides['excused_out']['value'] ?? null) !== null);
        $working = (bool) $plan['is_working'];
        if ($working && $plan['start_utc'] !== null && $plan['end_utc'] !== null) {
            $start = $this->moment((string) $plan['start_utc']);
            $end = $this->moment((string) $plan['end_utc']);
            if ($arrival !== null && $this->moment((string) $arrival['event_at'])->getTimestamp() > $start->getTimestamp() + (int) $plan['late_grace_seconds']) {
                $this->flag($id, $date, 'LATE_ARRIVAL', 'arrival', 'late:'.$arrival['id'].':'.$hash, ['event_id' => (int) $arrival['id'], 'actual_at' => $arrival['event_at'], 'expected_at' => $plan['start_utc'], 'seconds' => $this->moment((string) $arrival['event_at'])->getTimestamp() - $start->getTimestamp(), 'group_name' => $plan['group_name']], $now, (bool) $arrival['private']);
            }
            if ($departure !== null && $this->moment((string) $departure['event_at'])->getTimestamp() < $end->getTimestamp() - (int) $plan['early_grace_seconds']) {
                $this->flag($id, $date, 'EARLY_DEPARTURE', (string) $departure['event_type'], 'early:'.$departure['id'].':'.$hash, ['event_id' => (int) $departure['id'], 'actual_at' => $departure['event_at'], 'expected_at' => $plan['end_utc'], 'seconds' => $end->getTimestamp() - $this->moment((string) $departure['event_at'])->getTimestamp(), 'provisional' => $now < $end], $now, (bool) $departure['private']);
            }
            if (!$hasArrival && ($departure !== null || $now->getTimestamp() >= $start->getTimestamp() + (int) $plan['policy']['missing_after_minutes'] * 60)) {
                $this->flag($id, $date, 'MISSING_ARRIVAL', 'arrival', 'missing-arrival:'.$id.':'.$date.':'.$hash, ['expected_at' => $plan['start_utc'], 'ambiguous_departure' => $departure !== null, 'message' => $departure === null ? 'Нет отметки прихода; отсутствие не доказано.' : 'Уход без прихода: начало неизвестно.'], $now);
            }
            if ($now->getTimestamp() >= $end->getTimestamp() + (int) $plan['policy']['close_after_minutes'] * 60) {
                if ($hasArrival && !$hasDeparture) {
                    $arrivalEvidence = $allRaw['arrival'] ?? null;
                    $this->flag($id, $date, 'MISSING_DEPARTURE', 'departure', 'missing-departure:'.$id.':'.$date.':'.$hash, ['event_id' => $arrivalEvidence === null ? null : (int) $arrivalEvidence['id'], 'expected_at' => $plan['end_utc']], $now, $arrivalEvidence !== null && (bool) $arrivalEvidence['private']);
                }
                if ($events === [] && $day['arrival_at'] === null && $day['departure_at'] === null) { $this->flag($id, $date, 'NO_MARKS', null, 'no-marks:'.$id.':'.$date.':'.$hash, ['message' => 'Нет отметок в ожидаемый день; это не доказательство прогула.'], $now); }
                if ($this->extraEnabled('lunch_out', $plan['policy']) && (($day['lunch_out_at'] === null) !== ($day['lunch_in_at'] === null) || $this->openLunch($day))) {
                    $this->flag($id, $date, 'INCOMPLETE_BREAK', 'lunch_out', 'break:'.$id.':'.$date.':'.hash('sha256', $this->json([$day['lunch_out_at'], $day['lunch_in_at']])), ['message' => 'Не завершена обеденная пара.'], $now);
                }
            }
        }
        // A prior lunch return followed by another lunch exit is an open cycle, not invalid order.
        $lunchReturnWithoutPriorExit = $day['lunch_out_at'] !== null && $day['lunch_in_at'] !== null && $day['lunch_in_at'] < $day['lunch_out_at'] && !array_filter($events, static fn (array $event): bool => $event['event_type'] === 'lunch_out' && $event['event_at'] <= $day['lunch_in_at']);
        if (($day['arrival_at'] !== null && $day['departure_at'] !== null && $day['departure_at'] < $day['arrival_at']) || $lunchReturnWithoutPriorExit) {
            $key = 'order:'.$id.':'.$date.':'.hash('sha256', $this->json([$day['arrival_at'], $day['departure_at'], $day['lunch_out_at'], $day['lunch_in_at'], $day['overrides_json']]));
            $this->flag($id, $date, 'INVALID_ORDER', null, $key, ['message' => 'Порядок времён требует проверки.'], $now);
        }
    }

    private function flag(int $id, string $date, string $kind, ?string $type, string $key, array $details, DateTimeImmutable $now, bool $private = false): void
    {
        $this->insertIgnore('attendance_flags', ['employee_id' => $id, 'work_date' => $date, 'kind' => $kind, 'event_type' => $type, 'evidence_key' => $key, 'details_json' => $this->json($details), 'suppressed' => 0, 'created_at' => $this->stamp($now), 'active' => 1, 'private' => (int) $private]);
        // Do not erase a previous owner's suppression; only update current applicability.
        $this->db->exec('UPDATE attendance_flags SET active = 1, details_json = ? WHERE evidence_key = ?', [$this->json($details), $key]);
    }

    private function change(int $id, string $date, string $field, ?string $before, ?string $after, DateTimeImmutable $now, string $source, ?int $actor, array $payload, string $requestId, bool $private): int
    {
        $raw = $this->db->one('SELECT id FROM attendance_events WHERE employee_id = ? AND work_date = ? AND event_type = ? ORDER BY event_at '.($field === 'arrival' ? 'ASC' : 'DESC').', id LIMIT 1', [$id, $date, $field]);
        $context = $this->context($payload) + ['input_precision' => isset($payload['values'][$field]) && strlen((string) $payload['values'][$field]) === 5 ? 'minute' : 'second'];
        $this->db->insert('attendance_changes', ['employee_id' => $id, 'work_date' => $date, 'event_type' => $field, 'before_at' => $before, 'after_at' => $after, 'received_at' => $this->stamp($now), 'source' => $source, 'actor_employee_id' => $actor, 'session_fingerprint' => (string) ($context['session_fingerprint'] ?? ''), 'private' => (int) $private, 'event_id' => $raw === null ? null : (int) $raw['id'], 'request_id' => $requestId, 'context_json' => $this->json($context)]);
        return (int) $this->db->db->lastInsertId();
    }

    private function serializeDay(int $id, string $date, ?array $day = null): array
    {
        $employee = $this->employeeAtDate($id, $date);
        $day ??= $this->db->one('SELECT * FROM attendance_days WHERE employee_id = ? AND work_date = ?', [$id, $date]) ?? $this->emptyDay($id, $date);
        $plan = $this->dayPlan($day);
        $events = $this->db->all('SELECT * FROM attendance_events WHERE employee_id = ? AND work_date = ? AND private = 0 ORDER BY received_at, id', [$id, $date]);
        $changes = $this->db->all('SELECT * FROM attendance_changes WHERE employee_id = ? AND work_date = ? AND private = 0 AND source <> ? ORDER BY received_at, id', [$id, $date, 'owner_adjust']);
        $flags = $this->db->all('SELECT * FROM attendance_flags WHERE employee_id = ? AND work_date = ? AND active = 1 AND suppressed = 0 AND private = 0 ORDER BY id', [$id, $date]);
        $values = [];
        foreach (self::FIELDS as $field) { $values[$field] = $this->displayTime($day[$field.'_at']); }
        $now = $this->now();
        $arrivalMoment = $day['arrival_at'] === null ? null : $this->moment($day['arrival_at']);
        $departureValue = $day['departure_at'] ?? $day['excused_out_at'];
        $departureMoment = $departureValue === null ? null : $this->moment($departureValue);
        $present = $date === $this->local($now)->format('Y-m-d') && $arrivalMoment !== null && $arrivalMoment <= $now && ($departureMoment === null || $departureMoment > $now || $departureMoment < $arrivalMoment);
        if ($present && $this->extraEnabled('lunch_out', $plan['policy']) && $this->openLunch($day)) { $present = false; }
        $status = $values['arrival'] !== null && $values['departure'] !== null ? 'complete' : ($values['arrival'] !== null ? 'arrival_only' : ($values['departure'] !== null ? 'departure_only' : 'no_marks'));
        if ($present) { $status = 'present'; }
        $fullName = trim(implode(' ', array_filter([(string) $employee['surname'], (string) $employee['given_name'], (string) $employee['patronymic']], static fn (string $part): bool => $part !== '')));
        return ['success' => true, 'id' => $id, 'employee_id' => $id, 'pass_code' => IdentifierService::codeOf($employee), 'display_id' => IdentifierService::codeOf($employee), 'employee' => ['id' => $id, 'name' => $fullName, 'pass_code' => IdentifierService::codeOf($employee), 'display_id' => IdentifierService::codeOf($employee)], 'name' => $fullName, 'full_name' => $fullName, 'surname' => $employee['surname'], 'given_name' => $employee['given_name'], 'patronymic' => $employee['patronymic'], 'position' => $employee['position'], 'active' => (bool) $employee['active'], 'work_date' => $date, 'date' => $date, 'version' => (int) $day['version'], 'values' => $values,
            'arrival' => $values['arrival'], 'departure' => $values['departure'], 'lunch_out' => $values['lunch_out'], 'lunch_in' => $values['lunch_in'], 'excused_out' => $values['excused_out'],
            'raw_values' => $this->rawValues($events), 'events' => array_map(fn (array $event): array => $this->serializeEvent($event), $events), 'changes' => array_map(fn (array $change): array => $this->serializeChange($change), $changes), 'flags' => array_map(fn (array $flag): array => $this->serializeFlag($flag), $flags), 'status' => $status, 'present' => $present, 'has_values' => count(array_filter($values, static fn (mixed $value): bool => $value !== null)) > 0, 'is_working' => (bool) $plan['is_working'], 'group_name' => $plan['group_name'], 'plan' => $plan];
    }

    private function serializeEvent(array $event, bool $private = false): array
    {
        $result = ['id' => (int) $event['id'], 'event_type' => $event['event_type'], 'source' => $event['source'], 'event_at' => $this->iso($event['event_at']), 'received_at' => $this->iso($event['received_at']), 'event_at_text' => $this->textMoment($event['event_at']), 'received_at_text' => $this->textMoment($event['received_at'])];
        $actorId = $this->decode($event['context_json'])['actor_employee_id'] ?? null;
        $result['actor_display_id'] = $actorId === null ? null : $this->actorCode((int) $actorId);
        if ($private) { $result['private'] = (bool) $event['private']; $result['context'] = $this->decode($event['context_json']); }
        return $result;
    }

    private function serializeChange(array $change, bool $private = false): array
    {
        $result = ['id' => (int) $change['id'], 'event_type' => $change['event_type'], 'before_at' => $this->iso($change['before_at']), 'after_at' => $this->iso($change['after_at']), 'before_at_text' => $this->textMoment($change['before_at']), 'after_at_text' => $this->textMoment($change['after_at']), 'received_at' => $this->iso($change['received_at']), 'received_at_text' => $this->textMoment($change['received_at']), 'source' => $change['source'], 'actor_employee_id' => $change['actor_employee_id'] === null ? null : (int) $change['actor_employee_id'], 'session_fingerprint' => $change['session_fingerprint']];
        $result['actor_display_id'] = $change['actor_employee_id'] === null ? null : $this->actorCode((int) $change['actor_employee_id']);
        if ($private) { $result['private'] = (bool) $change['private']; $result['context'] = $this->decode($change['context_json']); }
        return $result;
    }

    private function serializeFlag(array $flag, bool $private = false): array
    {
        $details = $this->decode($flag['details_json']);
        if (isset($details['actor_employee_id'])) { $details['actor_display_id'] = $this->actorCode((int) $details['actor_employee_id']); }
        foreach (['actual_at', 'expected_at', 'before_at', 'after_at', 'received_at'] as $key) {
            if (array_key_exists($key, $details)) { $details[$key] = $this->iso($details[$key]); }
        }
        $labels = ['LATE_ARRIVAL' => 'Поздний приход', 'EARLY_DEPARTURE' => 'Ранний уход', 'MISSING_ARRIVAL' => 'Нет прихода', 'MISSING_DEPARTURE' => 'Нет ухода', 'NO_MARKS' => 'Нет отметок', 'CORRECTION' => 'Изменение времени', 'PRIOR_DAY_CHANGE' => 'Внесено за прошлый день', 'EARLIER_TIME_CLAIM' => 'Время внесено позже заявленного', 'INVALID_ORDER' => 'Порядок времён', 'INCOMPLETE_BREAK' => 'Неполный обед'];
        $result = ['id' => (int) $flag['id'], 'kind' => $flag['kind'], 'label' => $labels[$flag['kind']] ?? $flag['kind'], 'event_type' => $flag['event_type'], 'details' => $details, 'created_at' => $this->iso($flag['created_at'])];
        if ($private) { $result += ['suppressed' => (bool) $flag['suppressed'], 'private' => (bool) $flag['private'], 'active' => (bool) $flag['active'], 'evidence_key' => $flag['evidence_key']]; }
        return $result;
    }

    private function markResponse(array $employee, string $date, string $type, DateTimeImmutable $moment, bool $duplicate, int $eventId): array
    {
        $row = $this->serializeDay((int) $employee['id'], $date);
        return ['success' => true, 'employee' => $row['employee'], 'event_type' => $type, 'time' => $this->local($moment)->format('H:i:s'), 'date' => $date, 'server_received_at' => $this->local($moment)->format(DATE_ATOM), 'official_time' => $row['values'][$type], 'duplicate' => $duplicate, 'event_id' => $eventId, 'version' => $row['version'], 'flags' => $row['flags']];
    }

    private function visibleReplay(array $saved): array
    {
        $id = (int) ($saved['employee']['id'] ?? $saved['day']['employee_id'] ?? 0);
        $date = (string) ($saved['date'] ?? $saved['day']['work_date'] ?? '');
        if ($id <= 0 || $date === '' || !$this->db->scalar('SELECT COUNT(*) FROM private_service_events WHERE employee_id = ? AND work_date = ?', [$id, $date])) { return $saved; }
        $published = $this->serializeDay($id, $date);
        if (isset($saved['day'])) { $saved['day'] = $published; }
        $saved['flags'] = $published['flags'];
        $saved['version'] = $published['version'];
        if (isset($saved['event_id']) && $this->db->scalar('SELECT private FROM attendance_events WHERE id = ?', [(int) $saved['event_id']])) {
            $field = (string) $saved['event_type'];
            $saved['time'] = $published['values'][$field] === null ? '' : $published['values'][$field].':00';
            $saved['official_time'] = $published['values'][$field];
            unset($saved['event_id'], $saved['server_received_at']);
        }
        return $saved;
    }

    private function rawSelection(array $events): array
    {
        usort($events, static fn (array $a, array $b): int => [$a['event_at'], (int) $a['id']] <=> [$b['event_at'], (int) $b['id']]);
        $raw = [];
        foreach ($events as $event) {
            $type = (string) $event['event_type'];
            if (!in_array($type, self::FIELDS, true)) { continue; }
            if (!isset($raw[$type]) || $type !== 'arrival') { $raw[$type] = $event; }
        }
        return $raw;
    }

    private function rawValues(array $events): array
    {
        $raw = $this->rawSelection($events);
        $values = [];
        foreach (self::FIELDS as $field) { $values[$field] = $this->displayTime($raw[$field]['event_at'] ?? null); }
        return $values;
    }

    private function scopeForFields(int $id, string $date, array $fields): array
    {
        $scope = ['flags' => [], 'changes' => [], 'events' => []];
        foreach (['flags' => 'attendance_flags', 'changes' => 'attendance_changes', 'events' => 'attendance_events'] as $key => $table) {
            $sql = 'SELECT id FROM '.$table.' WHERE employee_id = ? AND work_date = ? AND (event_type IN ('.implode(',', array_fill(0, count($fields), '?')).')'.($key === 'flags' ? ' OR event_type IS NULL' : '').')';
            $scope[$key] = array_map(static fn (array $row): int => (int) $row['id'], $this->db->all($sql, [$id, $date, ...$fields]));
        }
        return $scope;
    }

    private function ownerScope(array $payload, int $id, string $date): array
    {
        $scope = ['flags' => $this->ids($payload['flag_ids'] ?? $payload['flag_id'] ?? []), 'changes' => $this->ids($payload['correction_ids'] ?? $payload['change_ids'] ?? $payload['correction_id'] ?? []), 'events' => $this->ids($payload['event_ids'] ?? $payload['event_id'] ?? [])];
        if (!empty($payload['all'])) {
            foreach (['flags' => 'attendance_flags', 'changes' => 'attendance_changes', 'events' => 'attendance_events'] as $key => $table) { $scope[$key] = array_map(static fn (array $row): int => (int) $row['id'], $this->db->all('SELECT id FROM '.$table.' WHERE employee_id = ? AND work_date = ?', [$id, $date])); }
        }
        if ($scope['flags'] === [] && $scope['changes'] === [] && $scope['events'] === []) { throw new DomainException('Выберите конкретные признаки/изменения или явно весь день.', 400); }
        foreach ($this->db->all('SELECT * FROM attendance_flags WHERE employee_id = ? AND work_date = ?', [$id, $date]) as $flag) {
            if (in_array((int) $flag['id'], $scope['flags'], true)) {
                $details = $this->decode($flag['details_json']);
                if (isset($details['change_id']) && ($payload['hide_history'] ?? true)) { $scope['changes'][] = (int) $details['change_id']; }
                if (isset($details['event_id']) && ($payload['hide_original'] ?? true)) { $scope['events'][] = (int) $details['event_id']; }
            }
        }
        if ($payload['hide_original'] ?? true) {
            foreach ($this->db->all('SELECT * FROM attendance_changes WHERE employee_id = ? AND work_date = ?', [$id, $date]) as $change) {
                if (in_array((int) $change['id'], $scope['changes'], true)) {
                    foreach ($this->db->all('SELECT id FROM attendance_events WHERE employee_id = ? AND work_date = ? AND event_type = ?', [$id, $date, $change['event_type']]) as $event) { $scope['events'][] = (int) $event['id']; }
                }
            }
        }
        foreach ($scope as &$ids) { $ids = array_values(array_unique($ids)); } unset($ids);
        return $scope;
    }

    private function visibility(int $id, string $date, array $scope, bool $suppress, string $reason, DateTimeImmutable $now): array
    {
        // Related flags are scoped to the selected immutable change/event IDs, not to all future edits.
        foreach ($this->db->all('SELECT * FROM attendance_flags WHERE employee_id = ? AND work_date = ?', [$id, $date]) as $flag) {
            $details = $this->decode($flag['details_json']);
            if ((isset($details['change_id']) && in_array((int) $details['change_id'], $scope['changes'], true)) || (isset($details['event_id']) && in_array((int) $details['event_id'], $scope['events'], true))) { $scope['flags'][] = (int) $flag['id']; }
        }
        $counts = ['flags' => 0, 'changes' => 0, 'events' => 0];
        foreach (['flags' => ['attendance_flags', 'finding_id'], 'changes' => ['attendance_changes', 'correction_id'], 'events' => ['attendance_events', 'event_id']] as $key => [$table, $reference]) {
            $ids = array_values(array_unique($scope[$key]));
            if ($ids === []) { continue; }
            $rows = $this->db->all('SELECT id FROM '.$table.' WHERE employee_id = ? AND work_date = ? AND id IN ('.implode(',', array_fill(0, count($ids), '?')).')', [$id, $date, ...$ids]);
            if (count($rows) !== count($ids)) { throw new DomainException('Выбранные основания не принадлежат этому сотруднику и дню.', 404); }
            $set = $key === 'flags' ? 'suppressed = ?, private = ?' : 'private = ?';
            $parameters = $key === 'flags' ? [(int) $suppress, (int) $suppress] : [(int) $suppress];
            $ownerGuard = !$suppress && $key === 'changes' ? ' AND source <> ?' : '';
            $this->db->exec('UPDATE '.$table.' SET '.$set.' WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')'.$ownerGuard, [...$parameters, ...$ids, ...($ownerGuard === '' ? [] : ['owner_adjust'])]);
            foreach ($ids as $target) {
                $this->db->insert('private_resolutions', ['employee_id' => $id, 'work_date' => $date, 'finding_id' => $reference === 'finding_id' ? $target : null, 'correction_id' => $reference === 'correction_id' ? $target : null, 'event_id' => $reference === 'event_id' ? $target : null, 'action' => $suppress ? 'suppress' : 'restore', 'reason' => $reason, 'created_at' => $this->stamp($now)]);
            }
            $counts[$key] = count($ids);
        }
        return $counts;
    }

    private function privateSnapshot(int $id, string $date): array
    {
        return ['day' => $this->db->one('SELECT * FROM attendance_days WHERE employee_id = ? AND work_date = ?', [$id, $date]), 'events' => $this->db->all('SELECT * FROM attendance_events WHERE employee_id = ? AND work_date = ? ORDER BY id', [$id, $date]), 'changes' => $this->db->all('SELECT * FROM attendance_changes WHERE employee_id = ? AND work_date = ? ORDER BY id', [$id, $date]), 'flags' => $this->db->all('SELECT * FROM attendance_flags WHERE employee_id = ? AND work_date = ? ORDER BY id', [$id, $date])];
    }

    private function normalizeValues(mixed $values): array
    {
        if (!is_array($values)) { throw new DomainException('Передайте объект изменённых значений.', 400); }
        $normalized = [];
        foreach ($values as $name => $value) {
            $field = $this->field((string) $name);
            if ($value !== null && !is_string($value)) { throw new DomainException('Время должно быть строкой или null.', 400); }
            $value = $value === null || trim($value) === '' ? null : trim($value);
            if ($value !== null) { ScheduleService::validateTime($value); }
            $normalized[$field] = $value;
        }
        ksort($normalized);
        return $normalized;
    }

    private function field(string $name): string
    {
        $name = ['lunch_return' => 'lunch_in', 'excused_leave' => 'excused_out'][$name] ?? $name;
        if (!in_array($name, self::FIELDS, true)) { throw new DomainException('Неизвестный вид отметки.', 400); }
        return $name;
    }

    private function autoType(DateTimeImmutable $now, array $policy): string
    {
        $time = $this->local($now)->format('H:i:s');
        return $time < $policy['auto_start'] ? $policy['night_event'] : ($time < $policy['auto_cutover'] ? 'arrival' : 'departure');
    }

    private function checkExtra(string $field, array $policy): void
    {
        if (!$this->extraEnabled($field, $policy)) { throw new DomainException('Этот дополнительный вид отметки отключён настройками.', 400); }
    }

    private function extraEnabled(string $field, array $policy): bool
    {
        if (in_array($field, ['arrival', 'departure'], true)) { return true; }
        $extras = $policy['extra_events'];
        if (is_array($extras)) { return array_is_list($extras) ? in_array($field, $extras, true) : (bool) ($extras[$field] ?? false); }
        return filter_var($extras, FILTER_VALIDATE_BOOLEAN);
    }

    private function openLunch(array $day): bool
    {
        return $day['lunch_out_at'] !== null && ($day['lunch_in_at'] === null || $day['lunch_in_at'] < $day['lunch_out_at']);
    }

    private function sameValue(?string $current, ?string $input, string $date): bool
    {
        if ($current === null || $input === null) { return $current === null && $input === null; }
        $local = $this->local($this->moment($current));
        return $local->format('Y-m-d') === $date && $local->format(strlen($input) === 5 ? 'H:i' : 'H:i:s') === $input;
    }

    private function positiveId(mixed $input): int
    {
        $number = trim((string) $input);
        if (!preg_match('/^\d{1,10}$/D', $number) || (int) $number <= 0 || (int) $number > 4294967295) { throw new DomainException('Введите положительный числовой ID сотрудника.', 400); }
        return (int) $number;
    }

    private function ids(mixed $value): array
    {
        if (is_string($value)) { $value = $value === '' ? [] : preg_split('/[,\s]+/', $value); }
        if (!is_array($value)) { $value = [$value]; }
        $ids = [];
        foreach ($value as $id) { $ids[] = $this->positiveId($id); }
        return array_values(array_unique($ids));
    }

    private function dates(string $from, string $to): array
    {
        ScheduleService::validateDate($from); ScheduleService::validateDate($to);
        if ($to < $from) { throw new DomainException('Конец периода раньше начала.', 400); }
        $start = new DateTimeImmutable($from, new DateTimeZone(ScheduleService::TIMEZONE));
        $end = new DateTimeImmutable($to, new DateTimeZone(ScheduleService::TIMEZONE));
        $count = (int) $start->diff($end)->days + 1;
        if ($count > (int) $this->schedules->policy()['report_max_days']) { throw new DomainException('Слишком большой период; разбейте выгрузку на части.', 400); }
        $dates = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) { $dates[] = $day->format('Y-m-d'); }
        return $dates;
    }

    private function context(array $payload): array
    {
        $input = is_array($payload['_context'] ?? null) ? $payload['_context'] : [];
        $result = [];
        foreach (['ip', 'ip_hash', 'session_fingerprint', 'user_agent', 'terminal', 'declared_employee_id'] as $key) {
            $value = $input[$key] ?? $payload[$key] ?? null;
            if ($value !== null && is_scalar($value)) { $result[$key] = substr((string) $value, 0, $key === 'user_agent' ? 500 : 128); }
        }
        return $result;
    }

    private function update(string $table, array $values, string $where, array $params): void
    {
        $sets = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($values)));
        $this->db->exec('UPDATE '.$table.' SET '.$sets.' WHERE '.$where, [...array_values($values), ...$params]);
    }
    private function insertIgnore(string $table, array $values): void
    {
        $sql = ($this->sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE').' INTO '.$table.' ('.implode(', ', array_keys($values)).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')';
        $this->db->exec($sql, array_values($values));
    }
    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as &$item) { $item = $this->canonical($item); } unset($item);
        return $value;
    }
    private function sqlite(): bool { return str_contains(strtolower(get_class($this->db->db->getDatabasePlatform())), 'sqlite'); }
    private function lock(): string { return $this->sqlite() ? '' : ' FOR UPDATE'; }
    private function now(): DateTimeImmutable { return ($this->clock)()->setTimezone(new DateTimeZone('UTC')); }
    private function stamp(DateTimeImmutable $moment): string { return $moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    private function moment(string $value): DateTimeImmutable { return new DateTimeImmutable($value, new DateTimeZone('UTC')); }
    private function local(DateTimeImmutable $moment): DateTimeImmutable { return $moment->setTimezone(new DateTimeZone(ScheduleService::TIMEZONE)); }
    private function inputMoment(string $date, string $time): DateTimeImmutable { return (new DateTimeImmutable($date.' '.ScheduleService::validateTime($time), new DateTimeZone(ScheduleService::TIMEZONE)))->setTimezone(new DateTimeZone('UTC')); }
    private function displayTime(?string $value): ?string { return $value === null ? null : $this->local($this->moment($value))->format('H:i'); }
    private function iso(?string $value): ?string { return $value === null ? null : $this->local($this->moment($value))->format(DATE_ATOM); }
    private function textMoment(?string $value): string { return $value === null ? '' : $this->local($this->moment($value))->format('d.m.Y H:i:s'); }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    private function decode(mixed $value): array { return is_array($value) ? $value : json_decode((string) ($value ?: '{}'), true, 512, JSON_THROW_ON_ERROR); }
}

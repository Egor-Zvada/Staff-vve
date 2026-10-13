<?php
declare(strict_types=1);

/**
 * Real SQL integration tests. Default: isolated in-memory SQLite, no Composer required.
 * Optional STAFF_TEST_DSN: Doctrine + an EMPTY MySQL database whose name ends in _test.
 * The tests never connect to the configured production DATABASE_URL.
 */
namespace App\Infrastructure {
    if (!getenv('STAFF_TEST_DSN')) {
        final class SQLiteTestPlatform {}
        final class SQLiteTestConnection
        {
            public function __construct(public readonly \PDO $pdo) {}
            public function getDatabasePlatform(): SQLiteTestPlatform { return new SQLiteTestPlatform(); }
            public function lastInsertId(): string { return $this->pdo->lastInsertId(); }
            public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
            public function rollBack(): bool { return $this->pdo->rollBack(); }
            public function isTransactionActive(): bool { return $this->pdo->inTransaction(); }
        }
        final class Database
        {
            public readonly SQLiteTestConnection $db;
            private int $savepoint = 0;
            public function __construct(string $url = '')
            {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $this->db = new SQLiteTestConnection($pdo);
            }
            public function all(string $sql, array $params = []): array { $q = $this->db->pdo->prepare($sql); $q->execute($params); return $q->fetchAll(\PDO::FETCH_ASSOC); }
            public function one(string $sql, array $params = []): ?array { return $this->all($sql, $params)[0] ?? null; }
            public function scalar(string $sql, array $params = []): mixed { $q = $this->db->pdo->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
            public function exec(string $sql, array $params = []): int { $q = $this->db->pdo->prepare($sql); $q->execute($params); return $q->rowCount(); }
            public function insert(string $table, array $data): int { return $this->exec('INSERT INTO '.$table.' ('.implode(',', array_map(static fn (string $key): string => '`'.$key.'`', array_keys($data))).') VALUES ('.implode(',', array_fill(0, count($data), '?')).')', array_values($data)); }
            public function transaction(callable $fn): mixed
            {
                $outer = !$this->db->pdo->inTransaction();
                $savepoint = 'domain_'.(++$this->savepoint);
                $outer ? $this->db->pdo->beginTransaction() : $this->db->pdo->exec('SAVEPOINT '.$savepoint);
                try {
                    $result = $fn();
                    $outer ? $this->db->pdo->commit() : $this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                    return $result;
                } catch (\Throwable $error) {
                    $outer ? $this->db->pdo->rollBack() : $this->db->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                    if (!$outer) { $this->db->pdo->exec('RELEASE SAVEPOINT '.$savepoint); }
                    throw $error;
                }
            }
        }
    }
}

namespace App\Service {
    if (!getenv('STAFF_TEST_DSN')) {
        final class Settings
        {
            private array $values = [];
            public function __construct(\App\Infrastructure\Database $db) {}
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
            public function set(string $key, mixed $value, mixed $actor = null): void { $this->values[$key] = $value; }
            public function all(): array { return $this->values; }
        }
    }
}

namespace {
    use App\Infrastructure\Database;
    use App\Service\AttendanceService;
    use App\Service\ScheduleService;
    use App\Service\Settings;

    $root = dirname(__DIR__);
    $dsn = (string) getenv('STAFF_TEST_DSN');
    $sharedDatabase = null;
    if ($dsn !== '') {
        require $root.'/vendor/autoload.php';
        $sharedDatabase = new Database($dsn);
        $databaseName = (string) $sharedDatabase->scalar('SELECT DATABASE()');
        if (!preg_match('/_test$/D', $databaseName) || $sharedDatabase->scalar('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()')) {
            throw new RuntimeException('STAFF_TEST_DSN must select an EMPTY disposable database ending in _test.');
        }
        foreach (schemaStatements(file_get_contents($root.'/migrations/schema.sql'), false) as $statement) { $sharedDatabase->exec($statement); }
    } else {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { throw new RuntimeException('Enable pdo_sqlite for isolated tests, or supply STAFF_TEST_DSN for an empty _test database.'); }
        require $root.'/src/Service/ScheduleService.php';
        require $root.'/src/Service/IdentifierService.php';
        require $root.'/src/Service/AttendanceService.php';
    }

    function schemaStatements(string $schema, bool $sqlite): array
    {
        $schema = preg_replace('/^\s*--.*$/m', '', $schema);
        if ($sqlite) {
            $schema = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $schema);
            $schema = preg_replace('/ENGINE=InnoDB[^;]*/', '', $schema);
            $schema = preg_replace('/\bENUM\([^)]*\)/', 'TEXT', $schema);
            $schema = preg_replace('/\b(?:BIGINT|INT|TINYINT)\b(?:\s+UNSIGNED)?/', 'INTEGER', $schema);
            $schema = str_replace(['AUTO_INCREMENT', 'INSERT IGNORE', 'JSON'], ['', 'INSERT OR IGNORE', 'TEXT'], $schema);
            $schema = preg_replace('/^\s*KEY\s+[^\n]+\n/m', '', $schema);
            $schema = preg_replace('/UNIQUE KEY\s+\w+\s*\(/', 'UNIQUE (', $schema);
            $schema = preg_replace('/,\s*\)/', ')', $schema);
        }
        return array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $schema)), static fn (string $sql): bool => $sql !== ''));
    }

    final class Fixture
    {
        public Database $db;
        public Settings $settings;
        public ScheduleService $schedule;
        public AttendanceService $attendance;
        public DateTimeImmutable $clock;
        public int $group;
        public int $otherGroup;
        private int $requests = 0;
        public function __construct()
        {
            global $root, $sharedDatabase;
            $this->db = $sharedDatabase ?? new Database();
            if ($sharedDatabase === null) {
                foreach (schemaStatements(file_get_contents($root.'/migrations/schema.sql'), true) as $sql) { $this->db->exec($sql); }
            }
            $this->db->db->beginTransaction();
            foreach ([7 => ['Иванов', 'Иван', 1, 'ADMIN'], 8 => ['Петров', 'Пётр', 1, 'NONE'], 9 => ['Бывший', 'Работник', 0, 'NONE']] as $id => [$surname, $given, $active, $role]) {
                $this->db->insert('employees', ['id' => $id, 'surname' => $surname, 'given_name' => $given, 'patronymic' => 'Иванович', 'active' => $active, 'is_service' => 0, 'admin_role' => $role, 'started_on' => '2026-01-01', 'ended_on' => $active ? null : '2026-10-12']);
            }
            $this->db->insert('schedule_groups', ['name' => 'Офис 09–18', 'start_time' => '09:00:00', 'end_time' => '18:00:00', 'weekdays' => '[1,2,3,4,5]']);
            $this->group = (int) $this->db->db->lastInsertId();
            $this->db->insert('schedule_groups', ['name' => 'Офис 09–17', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'weekdays' => '[1,2,3,4,5]']);
            $this->otherGroup = (int) $this->db->db->lastInsertId();
            foreach ([7, 8] as $id) { $this->db->insert('schedule_assignments', ['employee_id' => $id, 'group_id' => $this->group, 'valid_from' => '2026-01-01']); }
            $this->settings = new Settings($this->db);
            $this->at('08:55:00');
            $clock = fn (): DateTimeImmutable => $this->clock;
            $this->schedule = new ScheduleService($this->db, $this->settings, $clock);
            $this->attendance = new AttendanceService($this->db, $this->settings, $this->schedule, $clock);
        }
        public function at(string $local): void { $this->clock = new DateTimeImmutable(str_contains($local, ' ') ? $local : '2026-10-13 '.$local, new DateTimeZone('Asia/Sakhalin')); }
        public function request(): string { return 'domain-request-'.(++$this->requests); }
        public function mark(string $time, string $type = 'AUTO', string $code = '007', ?string $request = null, string $source = 'qr'): array
        {
            $this->at($time);
            return $this->attendance->mark(['request_id' => $request ?? $this->request(), 'code' => $code, 'event_type' => $type], $source);
        }
        public function manual(string $time, array $values, string $date = '2026-10-13', ?int $version = null, ?string $request = null): array
        {
            $this->at($time);
            $version ??= (int) $this->db->scalar('SELECT version FROM attendance_days WHERE employee_id = 7 AND work_date = ?', [$date]);
            return $this->attendance->manual(['request_id' => $request ?? $this->request(), 'employee_id' => 7, 'work_date' => $date, 'version' => $version, 'values' => $values, '_context' => ['session_fingerprint' => 'test-browser', 'ip' => '192.0.2.8']]);
        }
        public function row(string $date = '2026-10-13', int $id = 7): array { return $this->attendance->day($id, $date); }
        public function count(string $table): int { return (int) $this->db->scalar('SELECT COUNT(*) FROM '.$table); }
        public function close(): void { if ($this->db->db->isTransactionActive()) { $this->db->db->rollBack(); } }
    }

    function ok(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
    function eq(mixed $actual, mixed $expected, string $message): void { ok($actual === $expected, $message.'; got '.json_encode($actual, JSON_UNESCAPED_UNICODE).' expected '.json_encode($expected, JSON_UNESCAPED_UNICODE)); }
    function has(array $row, string $kind): bool { return in_array($kind, array_column($row['flags'], 'kind'), true); }
    function fails(callable $call, int $code): void
    {
        try { $call(); } catch (DomainException $error) { eq($error->getCode(), $code, 'Expected domain HTTP code'); return; }
        throw new RuntimeException('Expected DomainException '.$code);
    }
    $tests = [];
    function test(string $name, callable $body): void { global $tests; $tests[$name] = $body; }

    test('numeric QR preserves old ID and stores UTC, returns offset-aware local moments', function (Fixture $f): void {
        $result = $f->mark('08:59:07');
        eq($result['employee']['id'], 7, '007 resolves to ID7');
        eq($result['time'], '08:59:07', 'Local confirmation');
        eq($f->db->scalar('SELECT event_at FROM attendance_events'), '2026-10-12 21:59:07', 'UTC storage');
        eq($f->row()['events'][0]['event_at'], '2026-10-13T08:59:07+11:00', 'Explicit API offset');
        fails(fn () => $f->mark('09:00', 'AUTO', '7abc'), 400);
    });
    test('new public pass codes reject guessed numeric primary keys and normalize scanner letter case', function (Fixture $f): void {
        $code = \App\Service\IdentifierService::randomCode();
        ok((bool) preg_match('/^VVE-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/D', $code), 'Printable Crockford code');
        $f->db->exec('UPDATE employees SET pass_code = ? WHERE id = 8', [$code]);
        fails(fn () => $f->mark('08:55', 'AUTO', '8'), 404);
        fails(fn () => $f->mark('08:55', 'AUTO', '008'), 404);
        $marked = $f->mark('08:56', 'AUTO', strtolower($code));
        eq($marked['employee']['id'], 8, 'Internal linkage retained'); eq($marked['employee']['display_id'], $code, 'Only canonical code displayed');
        eq($f->row('2026-10-13', 8)['display_id'], $code, 'Day DTO code');
    });
    test('Russian keyboard scanner input resolves VVE codes without accepting unrelated Cyrillic', function (Fixture $f): void {
        $code = 'VVE-ABCD-EF12-XYZ9'; $f->db->exec('UPDATE employees SET pass_code = ? WHERE id = 8', [$code]);
        $input = '  мму-фисв-уа12-чня9  ';
        eq(\App\Service\IdentifierService::normalizeInput($input), $code, 'Physical RU keys normalized narrowly');
        $result = $f->mark('08:56', 'AUTO', $input, 'russian-vve-request');
        eq($result['employee']['display_id'], $code, 'Russian keyboard scan accepted');
        eq($f->mark('09:01', 'AUTO', $code, 'russian-vve-request'), $result, 'Normalized request identity survives English replay');
        fails(fn () => $f->mark('09:02', 'AUTO', 'ИВАНОВ'), 400);
        fails(fn () => $f->mark('09:02', 'AUTO', 'ММУ-ЩЩЩЩ-1234-1234'), 400); // O is excluded from Crockford codes.
        fails(fn () => $f->mark('09:02', 'AUTO', 'ММУ-ФИСВ-УА12-ЧНЯ!'), 400);
    });
    test('Russian keyboard NFC prefix and hex letters resolve the same UID without weakening validation', function (Fixture $f): void {
        $f->db->insert('employee_credentials', ['employee_id'=>7,'kind'=>'NFC_UID','value'=>'04ABCDEF12','active'=>1]);
        $input = 'тасж04фисвуа12';
        eq(\App\Service\IdentifierService::normalizeInput($input), 'NFC:04ABCDEF12', 'Russian colon key normalized');
        $result = $f->mark('08:55', 'AUTO', $input, 'russian-nfc-request');
        eq($result['employee']['id'],7,'NFC mapped to employee'); eq($f->row()['events'][0]['source'],'nfc','NFC source retained');
        eq($f->mark('09:00', 'AUTO', 'NFC:04ABCDEF12', 'russian-nfc-request'),$result,'NFC normalized replay');
        eq(\App\Service\IdentifierService::normalizeInput('ТАС:04ФИСВУА12'),'NFC:04ABCDEF12','Literal colon also supported');
        fails(fn ()=>$f->mark('09:01','AUTO','ТАСЖ04П0000012'),400); // G is not hexadecimal.
        fails(fn ()=>$f->mark('09:01','AUTO','ТАСЖ04ФИСВУА1'),400); // Odd UID length remains invalid.
    });
    test('pass rotation rejects old numeric code while preserving old events and independent NFC', function (Fixture $f): void {
        $f->mark('08:55', 'AUTO', '007');
        $code = \App\Service\IdentifierService::randomCode(); $f->db->exec('UPDATE employees SET pass_code = ? WHERE id = 7', [$code]);
        $f->db->insert('employee_credentials', ['employee_id'=>7,'kind'=>'LEGACY_NUMERIC_QR','value'=>'7','active'=>1]);
        fails(fn () => $f->mark('09:00', 'AUTO', '007'), 404);
        $f->mark('09:01', 'arrival', $code);
        $f->db->insert('employee_credentials', ['employee_id'=>7,'kind'=>'NFC_UID','value'=>'04AA000012','active'=>1]);
        eq($f->mark('09:02', 'arrival', 'NFC:04AA000012')['employee']['display_id'], $code, 'NFC maps same employee without revealing old pass');
        eq($f->row()['values']['arrival'], '08:55', 'Old accepted arrival survives'); eq(count($f->row()['events']), 3, 'Historical and new facts retained');
    });
    test('public manual alpha employee requires current code, moderator cannot bypass, administrator may edit by row ID', function (Fixture $f): void {
        $code = \App\Service\IdentifierService::randomCode(); $f->db->exec('UPDATE employees SET pass_code = ? WHERE id = 7', [$code]); $f->db->exec("UPDATE employees SET admin_role='MODERATOR' WHERE id=8");
        fails(fn () => $f->manual('08:55', ['arrival'=>'08:55']), 400);
        $payload = ['request_id'=>'alpha-manual-request','employee_id'=>7,'date'=>'2026-10-13','version'=>0,'values'=>['arrival'=>'08:55']];
        fails(fn () => $f->attendance->manual($payload,8), 400);
        $accepted = $f->attendance->manual($payload+['code'=>$code]); eq($accepted['day']['display_id'],$code,'Current public code accepted');
        $f->at('09:10'); $edited = $f->attendance->manual(['request_id'=>'alpha-admin-request','employee_id'=>7,'date'=>'2026-10-13','version'=>$accepted['version'],'values'=>['arrival'=>'08:54']],7);
        eq($edited['day']['changes'][0]['actor_display_id'],$code,'Administrative author shown with canonical code');
    });
    test('service bootstrap is resolvable only for explicitly authorized authentication callers', function (Fixture $f): void {
        $f->db->insert('employees',['id'=>0,'pass_code'=>'000','surname'=>'Система','given_name'=>'QA','is_service'=>1,'active'=>1,'admin_role'=>'ADMIN','password_hash'=>'backend-only-test-hash']);
        $identifiers = new \App\Service\IdentifierService($f->db);
        fails(fn () => $identifiers->resolveCode('000'),400);
        $service = $identifiers->resolveCode('0',true,true); eq($service['id'],0,'Explicit bootstrap authentication'); eq($service['password_hash'],'backend-only-test-hash','Backend Auth receives hash');
        fails(fn () => $f->mark('08:55','AUTO','0'),400);
    });
    test('AUTO noon boundary does not use day-state toggling', function (Fixture $f): void {
        eq($f->mark('11:59:59')['event_type'], 'arrival', 'Before noon arrival');
        eq($f->mark('12:00:00')['event_type'], 'departure', 'Noon departure');
        $f->settings->set('night_event', 'departure', 7);
        eq($f->mark('2026-10-14 04:59:59', 'AUTO', '8')['event_type'], 'departure', 'Configured night rule');
    });
    test('live technical settings change commands without changing historical normative plans', function (Fixture $f): void {
        $f->mark('08:55');
        $f->settings->set('auto_cutover', '13:00', 7);
        eq($f->mark('12:30')['event_type'], 'arrival', 'Live cutover despite frozen day');
        eq($f->row()['plan']['end_time'], '18:00', 'Normative end retained');
        $f->settings->set('extra_events', false, 7);
        fails(fn () => $f->mark('12:31', 'lunch_out'), 400);
    });
    test('first arrival and last departure preserve all accepted facts', function (Fixture $f): void {
        $f->mark('08:58'); $f->mark('09:30'); $f->mark('17:00');
        ok(has($f->row(), 'EARLY_DEPARTURE'), 'Early exit observed');
        $f->mark('18:03');
        $row = $f->row();
        eq($row['values']['arrival'], '08:58', 'First arrival retained');
        eq($row['values']['departure'], '18:03', 'Last departure chosen');
        eq(count($row['events']), 4, 'All facts retained');
        ok(!has($row, 'LATE_ARRIVAL') && !has($row, 'EARLY_DEPARTURE'), 'Current first/last evidence');
    });
    test('stable request replay across noon, conflicting payload rejected', function (Fixture $f): void {
        $first = $f->mark('08:55', 'AUTO', '007', 'stable-request-001');
        eq($f->mark('12:30', 'AUTO', '007', 'stable-request-001'), $first, 'Replay original result');
        eq($f->count('attendance_events'), 1, 'One event only');
        fails(fn () => $f->mark('12:31', 'AUTO', '8', 'stable-request-001'), 409);
    });
    test('technical duplicates do not move times or extend accepted window', function (Fixture $f): void {
        $first = $f->mark('08:55:00');
        $second = $f->mark('08:55:02');
        ok($second['duplicate'], 'Within3sec duplicate');
        eq($second['event_id'], $first['event_id'], 'Same accepted event');
        eq($f->count('attendance_events'), 1, 'No duplicate fact');
        ok(!$f->mark('08:55:04')['duplicate'], 'New accepted fact after3sec');
        eq($f->count('attendance_events'), 2, 'Second real fact');
    });
    test('inactive employee rejected transactionally', function (Fixture $f): void {
        fails(fn () => $f->mark('08:55', 'AUTO', '9'), 409);
        eq($f->count('attendance_events'), 0, 'No inactive event');
        eq($f->count('operation_requests'), 0, 'Failed request rolled back');
    });
    test('NFC canonical UID maps to employee, revocation does not remove history', function (Fixture $f): void {
        $f->db->insert('employee_credentials', ['employee_id' => 7, 'kind' => 'NFC_UID', 'value' => '04AA000012', 'active' => 1]);
        eq($f->mark('08:55', 'AUTO', 'NFC:04-aa-00-00-12')['employee']['id'], 7, 'UID mapping');
        eq($f->row()['events'][0]['source'], 'nfc', 'NFC source');
        $f->db->exec('UPDATE employee_credentials SET active=0, revoked_at=?', ['2026-10-12 22:00:00']);
        fails(fn () => $f->mark('09:00', 'AUTO', 'NFC:04AA000012'), 404);
        eq($f->count('attendance_events'), 1, 'Original NFC fact preserved');
        eq($f->mark('09:01', 'arrival', '007')['employee']['id'], 7, 'Ordinary ID still allowed');
    });
    test('manual ID now has no source-based correction flag', function (Fixture $f): void {
        $f->mark('08:55', 'AUTO', '7', null, 'manual_now');
        $row = $f->row();
        ok(!has($row, 'CORRECTION') && !has($row, 'EARLIER_TIME_CLAIM'), 'Keyboard now is normal');
        eq(count($row['changes']), 0, 'No false correction');
    });
    test('timely manual declaration uses received time for punctuality', function (Fixture $f): void {
        $result = $f->manual('09:00:40', ['arrival' => '09:00']);
        eq($result['day']['values']['arrival'], '09:00', 'Official declared value');
        eq($result['day']['events'][0]['event_at'], '2026-10-13T09:00:40+11:00', 'Original received time');
        ok(has($result['day'], 'LATE_ARRIVAL'), 'No implicit120sec punctuality grace');
        ok(!has($result['day'], 'CORRECTION') && !has($result['day'], 'EARLIER_TIME_CLAIM'), 'No manual violation by source');
    });
    test('same-day substantially earlier claim is flagged without fabricated original arrival', function (Fixture $f): void {
        $row = $f->manual('09:25', ['arrival' => '09:00'])['day'];
        ok(has($row, 'EARLIER_TIME_CLAIM') && has($row, 'CORRECTION'), 'Earlier same-day claim');
        ok(!has($row, 'LATE_ARRIVAL'), 'No assertion of physical9:25 arrival');
        eq($f->count('attendance_events'), 0, 'No fabricated original scan');
    });
    test('manual override preserves original violation and seconds', function (Fixture $f): void {
        $f->mark('09:23:47');
        $version = $f->row()['version'];
        $same = $f->manual('09:24', ['arrival' => '09:23']);
        ok($same['noop'], 'Minute display no-op');
        eq($same['version'], $version, 'Version unchanged');
        eq($f->db->scalar('SELECT arrival_at FROM attendance_days'), '2026-10-12 22:23:47', 'Seconds retained');
        $row = $f->manual('09:30', ['arrival' => '09:00'])['day'];
        eq($row['values']['arrival'], '09:00', 'Override effective');
        eq($row['raw_values']['arrival'], '09:23', 'Original remains');
        ok(has($row, 'LATE_ARRIVAL') && has($row, 'CORRECTION') && has($row, 'EARLIER_TIME_CLAIM'), 'Separate evidence');
    });
    test('empty and repeated unchanged save do not manufacture day rows or corrections', function (Fixture $f): void {
        ok($f->manual('08:55', ['arrival' => null])['noop'], 'Empty no-op');
        eq($f->count('attendance_days'), 0, 'No empty row from save');
        eq($f->count('attendance_changes'), 0, 'No false change');
        $f->mark('08:59');
        $version = $f->row()['version'];
        ok($f->manual('09:05', ['arrival' => '08:59'])['noop'], 'Repeated value no-op');
        eq($f->row()['version'], $version, 'Version stays');
    });
    test('optimistic version detects competing scan, no partial edit', function (Fixture $f): void {
        $f->mark('08:55'); $version = $f->row()['version']; $f->mark('17:00');
        fails(fn () => $f->manual('17:01', ['arrival' => '09:00'], '2026-10-13', $version), 409);
        eq($f->row()['values']['arrival'], '08:55', 'Fresh row not overwritten');
        eq($f->count('attendance_changes'), 0, 'No partial correction');
    });
    test('multi-field validation failure rolls back request, history and projection', function (Fixture $f): void {
        fails(fn () => $f->manual('09:10', ['arrival' => '09:00', 'departure' => '19:00']), 400);
        eq($f->count('attendance_changes'), 0, 'Earlier applied field rolled back');
        eq($f->count('attendance_days'), 0, 'Projection rollback');
        eq($f->count('attendance_flags'), 0, 'Evidence rollback');
        eq($f->count('operation_requests'), 0, 'Request rollback');
    });
    test('history_start stops invented Staff1 expectations but allows retrospective trap', function (Fixture $f): void {
        $f->settings->set('history_start', '2026-10-13', 7);
        ok(!$f->schedule->plan(7, '2026-10-12')['is_working'], 'Before launch not normative');
        $row = $f->manual('09:30', ['arrival' => '09:00'], '2026-10-12')['day'];
        ok(has($row, 'PRIOR_DAY_CHANGE') && has($row, 'EARLIER_TIME_CLAIM'), 'Old date accepted with evidence');
        ok(!has($row, 'MISSING_ARRIVAL') && !has($row, 'NO_MARKS') && !has($row, 'LATE_ARRIVAL'), 'No invented prelaunch schedule');
    });
    test('weekend marks excluded until explicit working-day override', function (Fixture $f): void {
        $f->mark('2026-10-17 10:00');
        $summary = $f->attendance->dashboard('2026-10-17')['summary'];
        eq($summary['planned'], 0, 'Weekend excluded'); eq($summary['arrivals'], 0, 'Denominator excluded');
        ok(!has($f->row('2026-10-17'), 'LATE_ARRIVAL'), 'Weekend not late');
        $f->schedule->saveException(['employee_id' => 7, 'work_date' => '2026-10-17', 'is_working' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'reason' => 'Рабочая суббота'], 7);
        ok(has($f->row('2026-10-17'), 'LATE_ARRIVAL'), 'Explicit weekend plan controls punctuality');
        eq($f->attendance->dashboard('2026-10-17')['summary']['arrivals'], 1, 'Working override enters denominator');
    });
    test('calendar employee exception wins over group/global', function (Fixture $f): void {
        $f->schedule->saveException(['work_date' => '2026-10-13', 'is_working' => 0, 'reason' => 'Праздник'], 7);
        $f->schedule->saveException(['group_id' => $f->group, 'work_date' => '2026-10-13', 'is_working' => 1, 'start_time' => '10:00', 'end_time' => '15:00'], 7);
        $f->schedule->saveException(['employee_id' => 7, 'work_date' => '2026-10-13', 'is_working' => 0, 'reason' => 'Отпуск'], 7);
        ok(!$f->schedule->plan(7, '2026-10-13')['is_working'], 'Employee leave wins');
        eq($f->schedule->plan(8, '2026-10-13')['start_time'], '10:00', 'Other person gets group override');
    });
    test('moving a calendar exception on the same date invalidates both employee scopes', function (Fixture $f): void {
        $exception = $f->schedule->saveException(['employee_id' => 7, 'work_date' => '2026-10-13', 'is_working' => 0, 'reason' => 'Отпуск'], 7);
        ok(!$f->row()['is_working'], 'Original employee receives exception');
        ok($f->row('2026-10-13', 8)['is_working'], 'Other employee initially scheduled');
        $f->schedule->saveException(['id' => $exception['id'], 'employee_id' => 8, 'work_date' => '2026-10-13', 'is_working' => 0, 'reason' => 'Исправлен сотрудник'], 7);
        ok($f->row()['is_working'], 'Old employee cache and day plan are invalidated');
        ok(!$f->row('2026-10-13', 8)['is_working'], 'New employee cache and day plan are invalidated');
    });
    test('a nonexistent calendar exception ID is rejected without creating a replacement', function (Fixture $f): void {
        fails(fn () => $f->schedule->saveException(['id' => 9999, 'employee_id' => 7, 'work_date' => '2026-10-13', 'is_working' => 0], 7), 404);
        eq($f->count('calendar_exceptions'), 0, 'No accidental insertion');
        ok($f->schedule->plan(7, '2026-10-13')['is_working'], 'No accidental schedule change');
    });
    test('future group version and assignment preserve old plans', function (Fixture $f): void {
        eq($f->schedule->plan(7, '2026-10-13')['start_time'], '09:00', 'Initial plan');
        $f->schedule->saveGroupVersion(['id' => $f->group, 'name' => 'Новый график', 'start_time' => '10:00', 'end_time' => '17:00', 'valid_from' => '2026-11-01', 'weekdays' => [1,2,3,4,5]], 7);
        eq($f->schedule->plan(7, '2026-10-15')['start_time'], '09:00', 'Unobserved old date uses prior version');
        eq($f->schedule->plan(7, '2026-11-03')['start_time'], '10:00', 'Future version applied');
        $f->schedule->saveAssignment(['employee_id' => 7, 'group_id' => $f->otherGroup, 'valid_from' => '2026-11-04'], 7);
        eq($f->schedule->plan(7, '2026-11-03')['start_time'], '10:00', 'Previous assignment retained');
        eq($f->schedule->plan(7, '2026-11-04')['end_time'], '17:00', 'New assignment applied');
        fails(fn () => $f->schedule->saveAssignment(['employee_id' => 7, 'group_id' => $f->group, 'valid_from' => '2026-11-02', 'valid_to' => '2026-11-06'], 7), 409);
    });
    test('historical name and inactive history stay available', function (Fixture $f): void {
        $f->mark('2026-10-12 08:55');
        $f->db->insert('employee_profile_versions', ['employee_id' => 7, 'surname' => 'Старая', 'given_name' => 'Фамилия', 'position' => 'Тренер', 'valid_from' => '2026-01-01', 'valid_to' => '2026-10-12', 'active' => 1]);
        $f->db->exec('UPDATE employees SET surname=?, active=0, ended_on=? WHERE id=7', ['Новая', '2026-10-12']);
        $f->at('2026-10-13 09:00');
        $rows = $f->attendance->period('2026-10-12', '2026-10-12', 7);
        eq(count($rows), 1, 'Inactive historical row present');
        eq($rows[0]['full_name'], 'Старая Фамилия', 'Historical name');
        fails(fn () => $f->mark('09:00', 'arrival'), 409);
    });
    test('dated employment profiles preserve the dismissal gap after rehire', function (Fixture $f): void {
        $f->at('2026-10-20 09:00');
        $f->db->insert('employee_profile_versions', ['employee_id' => 7, 'surname' => 'Иванов', 'given_name' => 'Иван', 'valid_from' => '2026-01-01', 'valid_to' => '2026-10-13', 'active' => 1]);
        $f->db->insert('employee_profile_versions', ['employee_id' => 7, 'surname' => 'Иванов', 'given_name' => 'Иван', 'valid_from' => '2026-10-14', 'valid_to' => '2026-10-18', 'active' => 0]);
        $f->db->exec('UPDATE employees SET active = 0, ended_on = ? WHERE id = 7', ['2026-10-13']);
        ok($f->row('2026-10-13')['is_working'], 'Inclusive final workday remains expected');
        ok(!$f->row('2026-10-14')['is_working'], 'First dismissed day is not expected');
        $f->db->insert('employee_profile_versions', ['employee_id' => 7, 'surname' => 'Иванов', 'given_name' => 'Иван', 'valid_from' => '2026-10-19', 'active' => 1]);
        $f->db->exec('UPDATE employees SET active = 1, ended_on = NULL WHERE id = 7');
        // EmployeeService clears plans from the affected employment boundary during rehire.
        $f->db->exec('DELETE FROM schedule_plan_cache WHERE employee_id = 7 AND work_date >= ?', ['2026-10-14']);
        $f->db->exec('UPDATE attendance_days SET plan_json = NULL WHERE employee_id = 7 AND work_date >= ?', ['2026-10-14']);
        ok($f->row('2026-10-13')['is_working'], 'Prior employment day stays expected after rehire');
        $gap = $f->row('2026-10-15');
        ok(!$gap['is_working'] && !has($gap, 'NO_MARKS') && !has($gap, 'MISSING_ARRIVAL'), 'Unobserved dismissal-gap day is not made a missing workday');
        ok($f->row('2026-10-19')['is_working'], 'New employment starts on rehire date');
        eq($f->attendance->dashboard('2026-10-15')['summary']['planned'], 1, 'Only the continuously employed colleague enters the gap denominator');
    });
    test('an initially inactive imported profile is not an expected employee on its recorded boundary day', function (Fixture $f): void {
        $f->db->exec('UPDATE employees SET active = 0, started_on = ?, ended_on = ? WHERE id = 8', ['2026-10-13', '2026-10-13']);
        $f->db->insert('employee_profile_versions', ['employee_id' => 8, 'surname' => 'Петров', 'given_name' => 'Пётр', 'valid_from' => '2026-10-13', 'active' => 0]);
        $f->at('18:31');
        $row = $f->row('2026-10-13', 8);
        ok(!$row['is_working'] && !has($row, 'NO_MARKS'), 'Inactive source profile prevents invented expected attendance');
        eq($f->attendance->dashboard('2026-10-13')['summary']['planned'], 1, 'Inactive import is excluded from today denominator');
    });
    test('missing markers respect timing, never invent late arrival for departure-only', function (Fixture $f): void {
        $f->at('09:14:59'); ok(!has($f->row(), 'MISSING_ARRIVAL'), 'Before marker threshold');
        $f->at('09:15:00'); ok(has($f->row(), 'MISSING_ARRIVAL'), 'Marker at threshold');
        $f->mark('13:00'); $row = $f->row();
        eq($row['values']['arrival'], null, 'No fabricated arrival');
        ok(has($row, 'MISSING_ARRIVAL') && !has($row, 'LATE_ARRIVAL'), 'Ambiguous start, not proven late');
    });
    test('missing departure finalizes only after grace and yesterday is not present today', function (Fixture $f): void {
        $f->mark('08:55'); $f->at('18:29:59'); ok(!has($f->row(), 'MISSING_DEPARTURE'), 'Before closure');
        $f->at('18:30'); ok(has($f->row(), 'MISSING_DEPARTURE'), 'Missing exit after closure');
        $f->at('2026-10-14 08:00'); ok(!$f->row()['present'], 'Yesterday not current presence');
    });
    test('disabled extras reject writes, not fake required-lunch violations', function (Fixture $f): void {
        $f->settings->set('extra_events', false, 7);
        fails(fn () => $f->mark('12:00', 'lunch_out'), 400);
        $f->mark('08:55'); $f->mark('18:05');
        ok(!has($f->row(), 'INCOMPLETE_BREAK'), 'Disabled lunch not required');
    });
    test('repeated lunch cycles use latest pair without losing facts or inventing reversed order', function (Fixture $f): void {
        $f->mark('08:55');
        $f->mark('12:00', 'lunch_out'); ok(!$f->row()['present'], 'First lunch exit changes presence');
        $f->mark('12:40', 'lunch_in'); ok($f->row()['present'], 'First return restores presence');
        $f->mark('14:00', 'lunch_out');
        $open = $f->row();
        eq($open['values']['lunch_out'], '14:00', 'Latest lunch exit projected');
        eq($open['values']['lunch_in'], '12:40', 'Earlier return retained until next return');
        ok(!$open['present'], 'Second lunch exit changes presence');
        ok(!has($open, 'INVALID_ORDER'), 'An open second cycle is valid');
        $f->at('18:31'); ok(has($f->row(), 'INCOMPLETE_BREAK'), 'Unclosed second cycle is flagged at closure');
        $f->mark('18:32', 'lunch_in');
        $closed = $f->row();
        ok($closed['present'], 'Second return restores presence');
        ok(!has($closed, 'INCOMPLETE_BREAK') && !has($closed, 'INVALID_ORDER'), 'Closed cycles have no break anomaly');
        eq(count($closed['events']), 5, 'Both cycles retain all facts');
    });
    test('owner hides original/manual evidence in every ordinary view and cached replay', function (Fixture $f): void {
        $first = $f->mark('09:23', 'AUTO', '007', 'private-mark-request');
        $changed = $f->manual('09:30', ['arrival' => '09:00'], '2026-10-13', null, 'private-change-request');
        $changeId = $changed['day']['changes'][0]['id'];
        $f->attendance->ownerAdjust(['action' => 'suppress', 'employee_id' => 7, 'work_date' => '2026-10-13', 'correction_ids' => [$changeId], 'reason' => 'Подтверждённый технический сбой']);
        $row = $f->row();
        eq($row['events'], [], 'Original hidden'); eq($row['changes'], [], 'Correction hidden'); eq($row['flags'], [], 'Related findings hidden');
        eq($row['values']['arrival'], '09:00', 'Published official retained'); ok($row['present'], 'Official presence does not hint missing secret raw');
        $private = $f->attendance->privateDay(7, '2026-10-13');
        eq(count($private['events']), 1, 'Original retained privately'); eq(count($private['changes']), 1, 'Change retained privately');
        $replayed = $f->mark('09:35', 'AUTO', '007', 'private-mark-request');
        eq($replayed['flags'], [], 'Cached flags filtered'); ok(!isset($replayed['event_id']), 'Hidden original ID not returned');
        eq($replayed['time'], '09:00:00', 'Cached hidden original time replaced by official');
        $replayedChange = $f->manual('09:35', ['arrival' => '09:00'], '2026-10-13', 1, 'private-change-request');
        eq($replayedChange['day']['changes'], [], 'Cached manual history not exposed');
        $fresh = $f->manual('09:40', ['arrival' => '08:59'])['day'];
        ok(has($fresh, 'CORRECTION') && has($fresh, 'EARLIER_TIME_CLAIM'), 'New edit independently visible');
        eq(count($fresh['changes']), 1, 'Only new public change');
        eq($f->attendance->period('2026-10-13', '2026-10-13', 7)[0]['events'], [], 'Ordinary period also filtered');
    });
    test('new scan after hidden original is not deduplicated against private fact', function (Fixture $f): void {
        $f->mark('09:23:00');
        $f->attendance->ownerAdjust(['action' => 'correct', 'employee_id' => 7, 'work_date' => '2026-10-13', 'values' => ['arrival' => '09:00'], 'reason' => 'Официальное исправление']);
        $result = $f->mark('09:23:01');
        ok(!$result['duplicate'], 'Not matched to hidden fact');
        eq(count($f->row()['events']), 1, 'New ordinary-visible fact');
        ok(has($f->row(), 'LATE_ARRIVAL'), 'New visible evidence has own finding');
    });
    test('owner official correction and restore never disclose owner change source', function (Fixture $f): void {
        $f->mark('09:23');
        $f->attendance->ownerAdjust(['action' => 'correct', 'employee_id' => 7, 'date' => '2026-10-13', 'values' => ['arrival' => '09:00'], 'reason' => 'Проверено руководителем']);
        eq($f->row()['changes'], [], 'Owner change never ordinary');
        $f->attendance->ownerAdjust(['action' => 'restore', 'employee_id' => 7, 'work_date' => '2026-10-13', 'fields' => ['arrival'], 'reason' => 'Вернуть исходное']);
        eq($f->row()['values']['arrival'], '09:23', 'Original restored');
        eq($f->row()['changes'], [], 'Owner source stays private even after restore');
        ok(has($f->row(), 'LATE_ARRIVAL'), 'Original applicable finding restored');
    });
    test('owner scope validates selected IDs and requires reason', function (Fixture $f): void {
        $f->mark('09:23');
        fails(fn () => $f->attendance->ownerAdjust(['action' => 'suppress', 'employee_id' => 7, 'date' => '2026-10-13', 'all' => true, 'reason' => '']), 400);
        fails(fn () => $f->attendance->ownerAdjust(['action' => 'suppress', 'employee_id' => 7, 'date' => '2026-10-13', 'flag_ids' => [99999], 'reason' => 'Проверка']), 404);
        ok(has($f->row(), 'LATE_ARRIVAL'), 'Invalid scope did not hide valid flag');
    });
    test('expected days included, no data ratio is null and date limits are strict', function (Fixture $f): void {
        $f->at('18:31');
        $rows = $f->attendance->period('2026-10-13', '2026-10-13');
        eq(count($rows), 2, 'Expected active employees included without scans');
        ok(has($rows[0], 'NO_MARKS') && has($rows[1], 'NO_MARKS'), 'No-marks is observational');
        eq($f->attendance->dashboard('2026-10-13')['summary']['late_percent'], null, 'No fake zero percent');
        fails(fn () => $f->attendance->period('2026-02-30', '2026-03-01'), 400);
        fails(fn () => $f->attendance->period('2026-01-01', '2027-01-02'), 400);
    });

    $passed = $failed = 0;
    foreach ($tests as $name => $body) {
        $fixture = null;
        try {
            $fixture = new Fixture();
            $body($fixture);
            fwrite(STDOUT, "PASS ".$name."\n"); $passed++;
        } catch (Throwable $error) {
            fwrite(STDERR, "FAIL ".$name.": ".$error->getMessage()."\n".$error->getTraceAsString()."\n"); $failed++;
        } finally { $fixture?->close(); }
    }
    fwrite(STDOUT, "Domain integration: ".$passed." passed, ".$failed." failed\n");
    exit($failed === 0 ? 0 : 1);
}

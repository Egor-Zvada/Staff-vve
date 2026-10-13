<?php
declare(strict_types=1);

/** Isolated SQL + fake HTTP tests. No production database, API key or network is used. */
namespace App\Infrastructure {
    if (!getenv('STAFF_WALLET_TEST_DSN')) {
        final class WalletSQLitePlatform {}
        final class WalletSQLiteConnection
        {
            public function __construct(public readonly \PDO $pdo) {}
            public function getDatabasePlatform(): WalletSQLitePlatform { return new WalletSQLitePlatform(); }
            public function lastInsertId(): string { return $this->pdo->lastInsertId(); }
            public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
            public function rollBack(): bool { return $this->pdo->rollBack(); }
            public function isTransactionActive(): bool { return $this->pdo->inTransaction(); }
        }
        final class Database
        {
            public readonly WalletSQLiteConnection $db;
            private int $savepoint = 0;
            public function __construct(string $url = '') { $pdo = new \PDO('sqlite::memory:'); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); $pdo->exec('PRAGMA foreign_keys = ON'); $this->db = new WalletSQLiteConnection($pdo); }
            public function all(string $sql, array $params = []): array { $q = $this->db->pdo->prepare($sql); $q->execute($params); return $q->fetchAll(\PDO::FETCH_ASSOC); }
            public function one(string $sql, array $params = []): ?array { return $this->all($sql, $params)[0] ?? null; }
            public function scalar(string $sql, array $params = []): mixed { $q = $this->db->pdo->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
            public function exec(string $sql, array $params = []): int { $q = $this->db->pdo->prepare($sql); $q->execute($params); return $q->rowCount(); }
            public function insert(string $table, array $data): int { return $this->exec('INSERT INTO '.$table.' ('.implode(',', array_keys($data)).') VALUES ('.implode(',', array_fill(0, count($data), '?')).')', array_values($data)); }
            public function transaction(callable $fn): mixed {
                $outer = !$this->db->pdo->inTransaction(); $point = 'wallet_'.(++$this->savepoint);
                $outer ? $this->db->pdo->beginTransaction() : $this->db->pdo->exec('SAVEPOINT '.$point);
                try { $result = $fn(); $outer ? $this->db->pdo->commit() : $this->db->pdo->exec('RELEASE SAVEPOINT '.$point); return $result; }
                catch (\Throwable $error) { $outer ? $this->db->pdo->rollBack() : $this->db->pdo->exec('ROLLBACK TO SAVEPOINT '.$point); if (!$outer) { $this->db->pdo->exec('RELEASE SAVEPOINT '.$point); } throw $error; }
            }
        }
    }
}

namespace {
    use App\Infrastructure\Database;
    use App\Service\WalletProvider;
    use App\Service\WalletPassValidator;
    use App\Service\WalletService;

    $root = dirname(__DIR__); $dsn = (string) getenv('STAFF_WALLET_TEST_DSN'); $shared = null;
    if ($dsn !== '') {
        require $root.'/vendor/autoload.php'; $shared = new Database($dsn);
        if (!preg_match('/_test$/D', (string) $shared->scalar('SELECT DATABASE()')) || $shared->scalar('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()')) { throw new RuntimeException('Wallet tests require an EMPTY disposable _test database.'); }
        foreach (statements(file_get_contents($root.'/migrations/schema.sql'), false) as $sql) { $shared->exec($sql); }
    } else {
        foreach (['ScheduleService', 'IdentifierService', 'WalletProvider', 'WalletPassValidator', 'WalletService'] as $class) { require $root.'/src/Service/'.$class.'.php'; }
    }
    if (PHP_OS_FAMILY === 'Windows' && is_file('C:/Program Files/Git/usr/bin/openssl.exe')) { $_SERVER['STAFF_WALLET_OPENSSL'] = 'C:/Program Files/Git/usr/bin/openssl.exe'; }
    function statements(string $schema, bool $sqlite): array {
        $schema = preg_replace('/^\s*--.*$/m', '', $schema);
        if ($sqlite) {
            $schema = str_replace(' CHARACTER SET ascii COLLATE ascii_bin', '', $schema);
            $schema = preg_replace('/ENGINE=InnoDB[^;]*/', '', $schema); $schema = preg_replace('/\bENUM\([^)]*\)/', 'TEXT', $schema); $schema = preg_replace('/\b(?:BIGINT|INT|TINYINT)\b(?:\s+UNSIGNED)?/', 'INTEGER', $schema);
            $schema = str_replace(['AUTO_INCREMENT', 'INSERT IGNORE', 'JSON'], ['', 'INSERT OR IGNORE', 'TEXT'], $schema); $schema = preg_replace('/^\s*KEY\s+[^\n]+\n/m', '', $schema); $schema = preg_replace('/UNIQUE KEY\s+\w+\s*\(/', 'UNIQUE (', $schema); $schema = preg_replace('/,\s*\)/', ')', $schema);
        }
        return array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\n|$)/', $schema)), static fn (string $sql): bool => $sql !== ''));
    }
    function ok(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
    function eq(mixed $actual, mixed $expected, string $message): void { ok($actual === $expected, $message.' got '.json_encode($actual).' expected '.json_encode($expected)); }
    function rejected(callable $call, int $status): void { try { $call(); } catch (DomainException $error) { eq($error->getCode(), $status, 'Domain status'); return; } throw new RuntimeException('Expected rejection'); }
    function command(array $arguments): void {
        $process = proc_open($arguments, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes); if (!is_resource($process)) { throw new RuntimeException('No openssl CLI'); }
        fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]); if (proc_close($process) !== 0) { throw new RuntimeException('Test signing failed: '.$error); }
    }
    final class SigningFixture {
        public string $directory;
        public function __construct() {
            $this->directory = sys_get_temp_dir().'/staff-wallet-fixture-'.bin2hex(random_bytes(12)); mkdir($this->directory, 0700);
            $openssl = $_SERVER['STAFF_WALLET_OPENSSL'] ?? 'openssl';
            command([$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $this->directory.'/key.pem', '-out', $this->directory.'/cert.pem', '-days', '1', '-subj', '/CN=Staff Wallet SQL Test']);
        }
        public function response(int $id, ?string $barcode = null, bool $tampered = false, string $serialSuffix = ''): array {
            $serial = 'sql-wallet-serial-'.$id.$serialSuffix;
            $pass = json_encode(['serialNumber' => $serial, 'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => $barcode ?? WalletService::displayId($id)]]], JSON_THROW_ON_ERROR);
            $manifest = json_encode(['pass.json' => sha1($pass)], JSON_THROW_ON_ERROR);
            file_put_contents($this->directory.'/manifest.json', $manifest);
            command([$_SERVER['STAFF_WALLET_OPENSSL'] ?? 'openssl', 'cms', '-sign', '-binary', '-in', $this->directory.'/manifest.json', '-signer', $this->directory.'/cert.pem', '-inkey', $this->directory.'/key.pem', '-outform', 'DER', '-out', $this->directory.'/signature']);
            $zip = new ZipArchive(); $file = $this->directory.'/card.pkpass'; $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zip->addFromString('pass.json', $tampered ? str_replace('007', '008', $pass) : $pass); $zip->addFromString('manifest.json', $manifest); $zip->addFromString('signature', file_get_contents($this->directory.'/signature')); $zip->close();
            return ['serialNumber' => $serial, 'shareUrl' => 'https://pass.walletwallet.dev/p/'.$serial, 'googleSaveUrl' => 'https://pay.google.com/gp/v/save/sql-test-'.$id, 'applePass' => base64_encode(file_get_contents($file)), 'credentials' => 'platform'];
        }
        public function close(): void { foreach (['key.pem','cert.pem','manifest.json','signature','card.pkpass'] as $name) { if (is_file($this->directory.'/'.$name)) { unlink($this->directory.'/'.$name); } } rmdir($this->directory); }
    }
    final class Fixture {
        public Database $db; public WalletService $wallet; public array $calls = []; public mixed $post = null; public mixed $delete = null; public int $used = 0; public int $limit = 1000; public bool $offline = false; public DateTimeImmutable $clock;
        public function __construct() {
            global $shared, $root; $this->db = $shared ?? new Database();
            if ($shared === null) { foreach (statements(file_get_contents($root.'/migrations/schema.sql'), true) as $sql) { $this->db->exec($sql); } }
            else { $this->db->db->beginTransaction(); }
            foreach ([7,8,9] as $id) { $this->db->insert('employees', ['id'=>$id,'surname'=>'Тестов','given_name'=>'Иван','patronymic'=>'Тестович','position'=>'FORBIDDEN_POSITION','active'=>$id === 9 ? 0 : 1]); }
            $this->clock = new DateTimeImmutable('2026-10-13 09:00:00', new DateTimeZone('UTC'));
            $provider = new WalletProvider(function (string $method, string $path, ?array $payload): array {
                $this->calls[] = [$method, $path, $payload];
                if ($this->offline) { throw new RuntimeException('Simulated offline'); }
                if ($path === '/api/auth/usage') { return ['status'=>200,'body'=>json_encode(['count'=>$this->used,'limit'=>$this->limit,'remaining'=>max(0,$this->limit-$this->used),'resetDate'=>'2026-11-01','plan'=>'free'])]; }
                if ($method === 'POST') { return ($this->post)($payload); }
                if ($method === 'DELETE') { return $this->delete !== null ? ($this->delete)($path) : ['status'=>200,'body'=>json_encode(['serialNumber'=>substr($path,strlen('/api/passes/')),'deleted'=>true,'googleRevoked'=>true])]; }
                if (str_starts_with($path, '/api/passes/')) { $serial = substr($path, strlen('/api/passes/')); $id = (int) substr($serial, strlen('sql-wallet-serial-')); return ['status'=>200,'body'=>json_encode(['serial'=>$serial,'credentials'=>'platform','googleSaveUrl'=>'https://pay.google.com/gp/v/save/sql-test-'.$id])]; }
                throw new RuntimeException('Unexpected fake HTTP route');
            }, 'fake-test-key');
            $this->wallet = new WalletService($this->db, $provider, new WalletPassValidator(), fn () => $this->clock);
        }
        public function posts(): int { return count(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'POST')); }
        public function close(): void { if ($this->db->db->isTransactionActive()) { $this->db->db->rollBack(); } }
    }
    $signing = new SigningFixture(); $tests = [];
    $tests['authorized full name plus padded ID, signed pass cached privately, offline reuse'] = function (Fixture $f) use ($signing): void {
        $response = $signing->response(7); $f->post = static fn (array $payload): array => ['status'=>200,'body'=>json_encode($response)];
        eq($f->wallet->cached(7)['state'], 'not_issued', 'Read-only status'); eq(count($f->calls), 0, 'GET cache makes no provider request');
        $issued = $f->wallet->issue(7,7); eq($issued['state'],'ready','Issued'); eq($issued['display_id'],'007','Leading zero display');
        eq($f->calls[1][2], WalletService::passPayload(7,['surname'=>'Тестов','given_name'=>'Иван','patronymic'=>'Тестович']), 'Authorized card payload'); ok(!str_contains(json_encode($f->calls[1][2]),'FORBIDDEN'), 'No position or account role sent');
        eq($f->calls[1][2]['logoText'],'ПРОПУСК','Pass title'); eq($f->calls[1][2]['primaryFields'],[['label'=>'ФИО','value'=>'Тестов Иван Тестович']],'Full name as primary field'); eq($f->calls[1][2]['secondaryFields'],[['label'=>'ID','value'=>'007']],'ID as separate field');
        eq($f->wallet->pkpass(7), base64_decode($response['applePass']), 'Exact signed binary retained');
        $f->offline = true; $before = count($f->calls); $cached = $f->wallet->issue(7,7); ok($cached['cached'], 'Reuse offline'); eq(count($f->calls),$before,'No network on reuse'); eq($f->posts(),1,'One creation');
    };
    $tests['reentrant concurrent issue sees committed reservation and creates once'] = function (Fixture $f) use ($signing): void {
        $response = $signing->response(7); $competing = null;
        $f->post = function () use ($f, $response, &$competing): array { $competing = $f->wallet->issue(7,8); return ['status'=>200,'body'=>json_encode($response)]; };
        eq($f->wallet->issue(7,7)['state'],'ready','First completion'); eq($competing['state'],'issuing','Competitor observes pending'); eq($f->posts(),1,'No competing POST');
        eq((int)$f->db->scalar('SELECT COUNT(*) FROM wallet_attempts'),1,'One durable attempt');
    };
    $tests['timeout stays ambiguous, keeps reserved quota, cannot silently retry'] = function (Fixture $f): void {
        $f->post = static fn (): array => ['status'=>0,'body'=>''];
        $first = $f->wallet->issue(7,7); eq($first['state'],'ambiguous','Timeout state'); ok($first['recovery_required'],'Recovery demanded');
        eq($f->wallet->issue(7,7)['state'],'ambiguous','Second click remains blocked'); eq($f->posts(),1,'No timeout retry POST'); eq($f->wallet->usage()['reserved'],1,'Quota reserved conservatively');
    };
    $tests['a final quota slot cannot be consumed by a concurrent different employee'] = function (Fixture $f) use ($signing): void {
        $f->used=999; $response=$signing->response(7); $secondBlocked=false;
        $f->post=function () use ($f,$response,&$secondBlocked): array { try { $f->wallet->issue(8,7); } catch (DomainException $error) { $secondBlocked=$error->getCode()===429; } return ['status'=>200,'body'=>json_encode($response)]; };
        $f->wallet->issue(7,7); ok($secondBlocked,'Other employee denied while final slot reserved'); eq($f->posts(),1,'Hard quota blocks extra POST'); eq($f->wallet->usage()['remaining'],0,'No quota overrun');
    };
    $tests['definite provider rejection may retry but never manufactures a ready file'] = function (Fixture $f) use ($signing): void {
        $f->post=static fn (): array=>['status'=>400,'body'=>'{"error":"reject"}']; eq($f->wallet->issue(7,7)['state'],'failed','Definite failure'); eq($f->wallet->usage()['reserved'],0,'Reservation released');
        $response=$signing->response(7); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($response)]; eq($f->wallet->issue(7,7)['state'],'ready','Explicit retry after rejection'); eq($f->posts(),2,'One rejected, one completed');
    };
    $tests['HTTP500 and malformed success require recovery'] = function (Fixture $f): void {
        $f->post=static fn (): array=>['status'=>500,'body'=>'{"error":"unknown"}']; eq($f->wallet->issue(7,7)['state'],'ambiguous','500 outcome ambiguous');
        $f->post=static fn (): array=>['status'=>200,'body'=>'{}']; eq($f->wallet->issue(8,7)['state'],'ambiguous','Malformed success ambiguous');
    };
    $tests['wrong barcode or tampered signed manifest cannot become ready'] = function (Fixture $f) use ($signing): void {
        $wrong=$signing->response(7,'008'); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($wrong)]; eq($f->wallet->issue(7,7)['state'],'ambiguous','Wrong employee barcode rejected');
        $tampered=$signing->response(8,'007',true); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($tampered)]; eq($f->wallet->issue(8,7)['state'],'ambiguous','Invalid signature/manifest rejected');
    };
    $tests['batch reports explicit inactive failure and reuses a duplicate selected ID'] = function (Fixture $f) use ($signing): void {
        $response=$signing->response(7); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($response)]; $result=$f->wallet->issueBatch([7,'007',9],7);
        eq(count($result['results']),2,'Duplicate IDs normalized'); ok(!$result['success'],'Partial batch not reported as complete'); eq($result['results'][1]['code'],409,'Inactive per-card reason'); eq($f->posts(),1,'Only active new person created');
    };
    $tests['explicit signed response recovery validates ownership and makes no creation'] = function (Fixture $f) use ($signing): void {
        $f->post=static fn (): array=>['status'=>0,'body'=>'']; $f->wallet->issue(7,7);
        $restored=$f->wallet->recover(7,7,$signing->response(7)); eq($restored['state'],'ready','Recovered'); eq($f->posts(),1,'Recovery is read-only on provider'); eq($f->wallet->usage()['reserved'],0,'Ambiguous reservation resolved');
        eq($f->wallet->issue(7,7)['state'],'ready','Recovered pass reused'); eq($f->posts(),1,'No second issuance');
    };
    $tests['crashed issuing worker is reported as ambiguous without another POST'] = function (Fixture $f): void {
        $f->db->insert('wallet_passes', ['employee_id'=>7,'display_id'=>'007','state'=>'issuing','request_id'=>'crashed-request','created_at'=>'2026-10-13 08:58:00','updated_at'=>'2026-10-13 08:58:00']);
        eq($f->wallet->cached(7)['state'],'ambiguous','Stale worker needs recovery');
        eq($f->wallet->issue(7,7)['state'],'ambiguous','Create remains blocked'); eq($f->posts(),0,'No automatic POST on crash recovery');
        eq($f->wallet->usage()['reserved'],1,'Reservation remains counted');
    };
    $tests['confirmed revoke allows explicit replacement and preserves old signed binary history'] = function (Fixture $f) use ($signing): void {
        $old=$signing->response(7); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($old)]; $f->wallet->issue(7,7);
        eq($f->wallet->revoke(7,7)['state'],'revoked','Confirmed provider revoke'); eq($f->wallet->cachedShareUrl(7),null,'Revoked share is not published');
        $fresh=$signing->response(7,null,false,'-replacement'); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($fresh)];
        eq($f->wallet->issue(7,7)['serial_number'],$fresh['serialNumber'],'New explicit serial issued'); eq($f->posts(),2,'Two distinct completed issuances');
        eq($f->db->scalar('SELECT serial_number FROM wallet_pass_history'),$old['serialNumber'],'Old serial archived'); eq($f->db->scalar('SELECT apple_pass FROM wallet_pass_history'),base64_decode($old['applePass']),'Old signed file retained');
        ok(!isset($f->wallet->listing()[0]['apple_pass']),'Admin list never contains binary');
        $audit=implode('|',array_column($f->db->all('SELECT action FROM audit_log'),'action')); ok(str_contains($audit,'wallet.issue.ready')&&str_contains($audit,'wallet.revoke.revoked'),'Issue and revoke audited');
    };
    $tests['unknown revoke blocks new creation and permits safe DELETE retry'] = function (Fixture $f) use ($signing): void {
        $pass=$signing->response(7); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($pass)]; $f->wallet->issue(7,7);
        $f->delete=static fn (): array=>['status'=>0,'body'=>'']; eq($f->wallet->revoke(7,7)['state'],'revoke_unknown','Revocation timeout');
        eq($f->wallet->issue(7,7)['state'],'revoke_unknown','Replacement blocked'); eq($f->posts(),1,'No replacement POST');
        rejected(fn ()=>$f->wallet->recover(7,7,$pass),409);
        $f->delete=null; eq($f->wallet->revoke(7,7)['state'],'revoked','Safe retry DELETE confirms revoke'); eq($f->posts(),1,'Retry did not create');
    };
    $tests['partial Google revoke cannot be cleared by an ambiguous already-deleted response'] = function (Fixture $f) use ($signing): void {
        $pass=$signing->response(7); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($pass)]; $f->wallet->issue(7,7);
        $f->delete=static fn (): array=>['status'=>200,'body'=>json_encode(['serialNumber'=>$pass['serialNumber'],'deleted'=>true,'googleRevoked'=>false])]; eq($f->wallet->revoke(7,7)['state'],'revoke_unknown','Google partial failure');
        $f->delete=static fn (): array=>['status'=>200,'body'=>json_encode(['serialNumber'=>$pass['serialNumber'],'deleted'=>true,'alreadyDeleted'=>true])]; eq($f->wallet->revoke(7,7)['state'],'revoke_unknown','Missing Google confirmation remains unresolved');
        eq($f->wallet->issue(7,7)['state'],'revoke_unknown','No replacement after partial failure');
    };
    $tests['employee name edits do not update or reissue an existing provider pass'] = function (Fixture $f) use ($signing): void {
        $pass=$signing->response(7); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($pass)]; $f->wallet->issue(7,7);
        $f->db->exec('UPDATE employees SET given_name = ? WHERE id = 7',['Новое']); $before=count($f->calls);
        eq($f->wallet->issue(7,7)['display_name'],'Тестов Иван Тестович','Original issuance name remains snapshotted'); eq(count($f->calls),$before,'No PUT or POST on profile edit reuse');
        eq(json_decode($f->db->scalar('SELECT payload_json FROM wallet_attempts'),true)['primaryFields'][0]['value'],'Тестов Иван Тестович','Authorized payload snapshot retained');
    };
    $tests['new alpha employee sends canonical pass code rather than numeric internal row ID'] = function (Fixture $f) use ($signing): void {
        $code=\App\Service\IdentifierService::randomCode(); $f->db->exec('UPDATE employees SET pass_code = ? WHERE id = 7',[$code]);
        $pass=$signing->response(7,$code); $f->post=static fn (): array=>['status'=>200,'body'=>json_encode($pass)];
        eq($f->wallet->cached(7)['display_id'],$code,'Not-issued status shows canonical code');
        $issued=$f->wallet->issue(7,7); eq($issued['state'],'ready','Alpha signed QR verified'); eq($issued['display_id'],$code,'Issued snapshot code');
        eq($f->calls[1][2]['barcodeValue'],$code,'No internal ID in barcode'); eq($f->calls[1][2]['secondaryFields'][0]['value'],$code,'No internal ID displayed'); eq($f->calls[1][2]['barcodeAltText'],$code,'Alt text canonical');
    };
    $passed=$failed=0;
    try { foreach ($tests as $name=>$body) { $fixture=null; try { $fixture=new Fixture(); $body($fixture); fwrite(STDOUT,'PASS '.$name."\n"); $passed++; } catch (Throwable $error) { fwrite(STDERR,'FAIL '.$name.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n"); $failed++; } finally { $fixture?->close(); } } }
    finally { $signing->close(); }
    fwrite(STDOUT,"Wallet SQL integration: $passed passed, $failed failed\n"); exit($failed===0?0:1);
}

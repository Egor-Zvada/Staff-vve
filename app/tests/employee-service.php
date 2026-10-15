<?php
declare(strict_types=1);

/** Isolated SQLite regression test for the actual EmployeeService; never reads DATABASE_URL. */
namespace Symfony\Component\HttpKernel\Exception {
    class ConflictHttpException extends \RuntimeException {}
    class NotFoundHttpException extends \RuntimeException {}
}
namespace Symfony\Component\HttpFoundation\File {
    class UploadedFile {
        public function __construct(private string $path,private string $name) {}
        public function isValid(): bool { return is_file($this->path); }
        public function getSize(): int { return filesize($this->path); }
        public function getClientOriginalExtension(): string { return pathinfo($this->name,PATHINFO_EXTENSION); }
        public function getPathname(): string { return $this->path; }
        public function getClientOriginalName(): string { return $this->name; }
    }
}
namespace App\Service { class Settings {} }
namespace App\Infrastructure {
    final class EmployeeSQLiteTestPlatform {}
    final class TestConnection
    {
        public function __construct(public readonly \PDO $pdo) {}
        public function isTransactionActive(): bool { return $this->pdo->inTransaction(); }
        public function getDatabasePlatform(): EmployeeSQLiteTestPlatform { return new EmployeeSQLiteTestPlatform(); }
        public function lastInsertId(): string { return $this->pdo->lastInsertId(); }
        public function executeStatement(string $sql,array $args=[]): int { $query=$this->pdo->prepare(str_replace(' FOR UPDATE','',$sql)); $query->execute($args); return $query->rowCount(); }
        public function update(string $table,array $data,array $where): int { return $this->executeStatement('UPDATE '.$table.' SET '.implode(',',array_map(static fn(string $key):string=>$key.'=?',array_keys($data))).' WHERE '.implode(' AND ',array_map(static fn(string $key):string=>$key.'=?',array_keys($where))),[...array_values($data),...array_values($where)]); }
    }
    final class Database
    {
        public readonly TestConnection $db;
        private int $savepoint=0;
        public function __construct()
        {
            $pdo=new \PDO('sqlite::memory:'); $pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
            $pdo->sqliteCreateFunction('CONCAT_WS',static fn(string $separator,...$values):string=>implode($separator,array_filter($values,static fn($v):bool=>$v!==null)));
            $this->db=new TestConnection($pdo);
        }
        public function all(string $sql,array $args=[]): array { $query=$this->db->pdo->prepare(str_replace(' FOR UPDATE','',$sql)); $query->execute($args); return $query->fetchAll(\PDO::FETCH_ASSOC); }
        public function one(string $sql,array $args=[]): ?array { return $this->all($sql,$args)[0]??null; }
        public function scalar(string $sql,array $args=[]): mixed { $row=$this->one($sql,$args); return $row ? array_values($row)[0] : false; }
        public function exec(string $sql,array $args=[]): int { return $this->db->executeStatement($sql,$args); }
        public function insert(string $table,array $data): int { return $this->exec('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data)); }
        public function transaction(callable $operation): mixed { $outer=!$this->db->pdo->inTransaction(); $name='employee_'.(++$this->savepoint); $outer?$this->db->pdo->beginTransaction():$this->db->pdo->exec('SAVEPOINT '.$name); try { $value=$operation(); $outer?$this->db->pdo->commit():$this->db->pdo->exec('RELEASE SAVEPOINT '.$name); return $value; } catch(\Throwable $error) { $outer?$this->db->pdo->rollBack():$this->db->pdo->exec('ROLLBACK TO SAVEPOINT '.$name); if(!$outer) { $this->db->pdo->exec('RELEASE SAVEPOINT '.$name); } throw $error; } }
    }
}
namespace {
    require dirname(__DIR__).'/src/Service/IdentifierService.php';
    require dirname(__DIR__).'/src/Service/EmployeeService.php';
    require dirname(__DIR__).'/src/Service/ImportService.php';
    require dirname(__DIR__).'/src/Service/ScheduleService.php';
    $db=new \App\Infrastructure\Database();
    $schema=[
        'CREATE TABLE employee_id_sequence (id INTEGER PRIMARY KEY,next_id INTEGER NOT NULL)',
        "CREATE TABLE employees (id INTEGER PRIMARY KEY,pass_code TEXT UNIQUE,surname TEXT NOT NULL,given_name TEXT NOT NULL,patronymic TEXT DEFAULT '',position TEXT DEFAULT '',is_guard INTEGER DEFAULT 0,legacy_full_name TEXT,active INTEGER DEFAULT 1,is_service INTEGER DEFAULT 0,admin_role TEXT DEFAULT 'NONE',password_hash TEXT,enrollment_pin_hash TEXT,auth_version INTEGER DEFAULT 0,started_on TEXT,ended_on TEXT,version INTEGER DEFAULT 1,created_at TEXT,updated_at TEXT)",
        'CREATE TABLE employee_profile_versions (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_id INTEGER,surname TEXT,given_name TEXT,patronymic TEXT,position TEXT,active INTEGER,valid_from TEXT,valid_to TEXT,actor_employee_id INTEGER,created_at TEXT)',
        'CREATE TABLE schedule_plan_cache (employee_id INTEGER,work_date TEXT,plan_json TEXT)',
        'CREATE TABLE attendance_days (employee_id INTEGER,work_date TEXT,plan_json TEXT,arrival_at TEXT)',
        'CREATE TABLE schedule_assignments (id INTEGER PRIMARY KEY,employee_id INTEGER,group_id INTEGER,valid_from TEXT,valid_to TEXT)',
        'CREATE TABLE schedule_groups (id INTEGER PRIMARY KEY,name TEXT,active INTEGER DEFAULT 1)',
        'CREATE TABLE employee_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT,employee_id INTEGER,kind TEXT,value TEXT,active INTEGER,revoked_at TEXT,created_at TEXT,UNIQUE(kind,value))',
        'CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_employee_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT NOT NULL,before_json TEXT,after_json TEXT,created_at TEXT,private INTEGER)',
        'CREATE TABLE wallet_passes (employee_id INTEGER PRIMARY KEY,state TEXT)',
        'CREATE TABLE import_batches (id INTEGER PRIMARY KEY AUTOINCREMENT,file_hash TEXT,mode TEXT,summary_json TEXT,actor_employee_id INTEGER,created_at TEXT)',
    ];
    foreach($schema as $statement) { $db->exec($statement); }
    $today=\App\Service\EmployeeService::today();
    $date=static fn(string $offset):string=>(new \DateTimeImmutable($today))->modify($offset)->format('Y-m-d');
    $db->insert('employee_id_sequence',['id'=>1,'next_id'=>101]);
    $db->insert('employees',['id'=>100,'pass_code'=>'100','surname'=>'Администратор','given_name'=>'Тест','active'=>1,'admin_role'=>'ADMIN','password_hash'=>password_hash('test-password',PASSWORD_DEFAULT),'started_on'=>$date('-30 days')]);
    $service=new \App\Service\EmployeeService($db,new \App\Service\Settings());
    $identifiers=new \App\Service\IdentifierService($db);
    $checks=0;
    $check=static function(bool $condition,string $message)use(&$checks):void { ++$checks; if(!$condition) { throw new \RuntimeException($message); } };
    $activeAt=static fn(int $id,string $day):int=>(int)$db->scalar('SELECT active FROM employee_profile_versions WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC LIMIT 1',[$id,$day,$day]);
    $employee=$service->save(['surname'=>'Сотрудник','given_name'=>'Иван','started_on'=>$date('-30 days')],100);
    $check($employee['id']===101,'Automatic ID did not use the monotonic sequence');
    $check(preg_match('/^VVE-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/D',$employee['pass_code'])===1 && $employee['display_id']===$employee['pass_code'],'New employee does not receive canonical random pass code');
    $check((int)$identifiers->resolveCode($employee['pass_code'])['id']===101,'Current alpha code does not resolve its permanent employee');
    $check($employee['is_guard']===0,'A new office employee is unexpectedly exempt from attendance control');
    $adminBefore=$service->find(100); $adminHash=$db->scalar('SELECT password_hash FROM employees WHERE id=100');
    $guardAdmin=$service->save(['id'=>100,'version'=>$adminBefore['version'],'is_guard'=>1],100);
    $check($guardAdmin['is_guard']===1 && $guardAdmin['admin_role']==='ADMIN' && $guardAdmin['auth_version']===$adminBefore['auth_version'] && $db->scalar('SELECT password_hash FROM employees WHERE id=100')===$adminHash,'Guard classification is incorrectly tied to administrative permissions');
    $service->save(['id'=>100,'version'=>$guardAdmin['version'],'is_guard'=>0],100);
    try { $identifiers->resolveCode('101'); $check(false,'Internal employee ID became a scanner backdoor'); } catch(\DomainException) { $check(true,'Internal alpha employee ID rejected'); }
    $db->insert('schedule_groups',['id'=>1,'name'=>'Офис']);
    $db->insert('schedule_assignments',['id'=>1,'employee_id'=>101,'group_id'=>1,'valid_from'=>$date('-30 days')]);
    $check((int)$service->list(['q'=>$employee['pass_code']])[0]['group_id']===1,'Effective group ID missing');
    $db->insert('schedule_plan_cache',['employee_id'=>101,'work_date'=>$today,'plan_json'=>'{}']);
    $db->insert('attendance_days',['employee_id'=>101,'work_date'=>$today,'plan_json'=>'{}','arrival_at'=>'2026-01-01 01:00:00']);
    $employee=$service->deactivate(['id'=>101,'version'=>$employee['version'],'ended_on'=>$today,'active'=>0],100);
    $check($activeAt(101,$today)===1 && $activeAt(101,$date('+1 day'))===0,'Retirement excluded the inclusive last working day');
    $check($db->scalar('SELECT COUNT(*) FROM schedule_plan_cache WHERE employee_id=101')===0,'Employment change did not invalidate plans');
    $check($db->scalar('SELECT arrival_at FROM attendance_days WHERE employee_id=101')==='2026-01-01 01:00:00','Retirement changed a raw recorded fact');
    $employee=$service->save($employee+['password'=>''],100); // no-op remains idempotent
    $employee=$service->save(array_replace($employee,['surname'=>'Новое имя']),100);
    $check($activeAt(101,$today)===1 && $activeAt(101,$date('+1 day'))===0,'Rename destroyed pending retirement boundary');
    $employee=$service->deactivate(['id'=>101,'version'=>$employee['version'],'active'=>1],100);
    $check($activeAt(101,$today)===1 && $activeAt(101,$date('+1 day'))===1,'Restoration failed to cancel a pending retirement');
    $employee=$service->deactivate(['id'=>101,'version'=>$employee['version'],'ended_on'=>$date('-5 days'),'active'=>0],100);
    $check($activeAt(101,$date('-5 days'))===1 && $activeAt(101,$date('-4 days'))===0,'Backdated retirement boundary is wrong');
    $employee=$service->save(array_replace($employee,['ended_on'=>$date('-2 days')]),100);
    $check($activeAt(101,$date('-3 days'))===1 && $activeAt(101,$date('-1 day'))===0,'Edited last working day did not move its activity boundary');
    $employee=$service->deactivate(['id'=>101,'version'=>$employee['version'],'active'=>1],100);
    $check($activeAt(101,$date('-1 day'))===0 && $activeAt(101,$today)===1,'Rehire lost the historical gap');
    $credential=$service->bindCredential(['employee_id'=>101,'kind'=>'NFC','value'=>'NFC:00:04:AB:01'],100);
    $check($credential['kind']==='NFC_UID' && $credential['value']==='0004AB01','NFC lost leading zero bytes');
    $guardProfileCount=(int)$db->scalar('SELECT COUNT(*) FROM employee_profile_versions WHERE employee_id=101');
    $guardAuthVersion=$employee['auth_version']; $guardCode=$employee['pass_code'];
    $db->exec("UPDATE attendance_days SET plan_json=? WHERE employee_id=101",['{"is_workday":true,"end":"18:00"}']);
    $employee=$service->save(array_replace($employee,['is_guard'=>1]),100);
    $check($employee['is_guard']===1 && $service->find(101)['is_guard']===1 && $service->list(['q'=>$guardCode])[0]['is_guard']===1,'Guard classification is not persisted or available to the administration UI');
    $check($employee['auth_version']===$guardAuthVersion && $employee['admin_role']==='NONE' && $employee['pass_code']===$guardCode,'Guard classification changed credentials or administrative access');
    $check((int)$db->scalar('SELECT COUNT(*) FROM employee_profile_versions WHERE employee_id=101')===$guardProfileCount && $db->scalar('SELECT plan_json FROM attendance_days WHERE employee_id=101')==='{"is_workday":true,"end":"18:00"}','Current guard classification rewrote the underlying frozen schedule');
    $check($db->scalar('SELECT arrival_at FROM attendance_days WHERE employee_id=101')==='2026-01-01 01:00:00' && (int)$db->scalar('SELECT active FROM employee_credentials WHERE id=?',[$credential['id']])===1,'Guard classification erased attendance facts or disabled the NFC card');
    $employee=$service->save(['id'=>101,'version'=>$employee['version'],'position'=>'Охрана'],100);
    $check($employee['is_guard']===1,'A partial profile edit silently reset guard classification');
    $employee=$db->transaction(fn()=>$service->applyImportRow(['id'=>101,'version'=>$employee['version'],'surname'=>'Новое имя','given_name'=>'Иван','position'=>'Охрана','is_guard'=>0],100));
    $check($employee['is_guard']===1,'A directory import silently reset attendance-control exemption');
    $guardVersion=$employee['version'];
    $employee=$service->save(array_replace($employee,['is_guard'=>0]),100);
    $check($employee['is_guard']===0 && $db->scalar('SELECT plan_json FROM attendance_days WHERE employee_id=101')==='{"is_workday":true,"end":"18:00"}','Removing guard classification lost the schedule needed to restore ordinary control');
    try { $service->save(['id'=>101,'version'=>$guardVersion,'is_guard'=>1],100); $check(false,'A stale guard form overwrote a newer classification'); } catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { $check($service->find(101)['is_guard']===0,'Stale guard update changed the employee'); }
    $check(!str_contains((string)$db->scalar('SELECT before_json FROM audit_log WHERE action="employee.update" LIMIT 1'),'password_hash'),'Audit exposes a password hash');
    $service->audit(100,'badges.export','print',null,null,['ids'=>[101]]);
    $check($db->scalar('SELECT entity_id FROM audit_log WHERE action="badges.export"')==='','Null PDF audit ID is not normalized');
    $legacy=$db->transaction(fn()=>$service->applyImportRow(['id'=>'007','surname'=>'Уволен','given_name'=>'Старый','active'=>0,'is_guard'=>1],100));
    $check($legacy['id']===7 && $activeAt(7,$today)===0,'Legacy inactive import became expected today');
    $check($legacy['is_guard']===0,'A directory import enabled an unreviewed guard exemption');
    $check($legacy['pass_code']==='007','Legacy import changed an existing numeric card code');
    $check((int)$db->scalar('SELECT next_id FROM employee_id_sequence')===102,'Legacy ID import reduced the sequence');
    $check(count($service->list(['q'=>'000']))<count($service->list()),'Searching zeros matches everybody');
    $legacy=$service->deactivate(['id'=>7,'version'=>$legacy['version'],'active'=>1],100);
    $check((int)$identifiers->resolveCode('7')['id']===7 && (int)$identifiers->resolveCode('007')['id']===7,'Legacy card variants were not preserved');
    $service->bindCredential(['employee_id'=>7,'kind'=>'LEGACY_NUMERIC_QR','value'=>'007'],100);
    $db->insert('wallet_passes',['employee_id'=>7,'state'=>'ready']);
    foreach(['ready','issuing','ambiguous','revoking','revoke_unknown'] as $state) {
        $db->db->update('wallet_passes',['state'=>$state],['employee_id'=>7]);
        try { $service->rotatePassCode(['id'=>7,'version'=>$legacy['version']],100); $check(false,'Pending Wallet must block pass rotation: '.$state); } catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { $check($service->find(7)['pass_code']==='007','Blocked Wallet rotation changed the pass code'); }
    }
    $db->db->update('wallet_passes',['state'=>'revoked'],['employee_id'=>7]);
    $oldProfiles=(int)$db->scalar('SELECT COUNT(*) FROM employee_profile_versions WHERE employee_id=7');
    $legacy=$service->rotatePassCode(['id'=>7,'version'=>$legacy['version']],100);
    $check($legacy['id']===7 && str_starts_with($legacy['pass_code'],'VVE-'),'Reissue replaced the employee record');
    $check((int)$db->scalar('SELECT COUNT(*) FROM employee_profile_versions WHERE employee_id=7')===$oldProfiles,'Reissue rewrote employment history');
    $check((int)$db->scalar("SELECT active FROM employee_credentials WHERE kind='LEGACY_NUMERIC_QR' AND value='7'")===0,'Reissue did not revoke the legacy alias');
    try { $identifiers->resolveCode('007'); $check(false,'Lost numeric card still works after reissue'); } catch(\DomainException) { $check(true,'Lost numeric card rejected'); }
    try { $service->bindCredential(['employee_id'=>7,'kind'=>'LEGACY_NUMERIC_QR','value'=>'007'],100); $check(false,'Old numeric QR could be rebound after reissue'); } catch(\InvalidArgumentException) { $check(true,'Legacy alias cannot bypass reissue'); }
    $oldCode=$employee['pass_code']; $oldVersion=$employee['version'];
    $employee=$service->rotatePassCode(['id'=>101,'version'=>$employee['version']],100);
    $check($employee['pass_code']!==$oldCode && $employee['version']===$oldVersion+1,'Alpha pass did not rotate with version increment');
    $check($db->scalar('SELECT arrival_at FROM attendance_days WHERE employee_id=101')==='2026-01-01 01:00:00','Reissue erased the old attendance fact');
    $check((int)$db->scalar('SELECT active FROM employee_credentials WHERE id=?',[$credential['id']])===1,'QR rotation revoked the independent NFC card');
    try { $identifiers->resolveCode($oldCode); $check(false,'Previous alpha pass remains active'); } catch(\DomainException) { $check(true,'Previous alpha pass rejected'); }
    try { $service->rotatePassCode(['id'=>101,'version'=>$oldVersion],100); $check(false,'A stale form rotated a newly changed card'); } catch(\Symfony\Component\HttpKernel\Exception\ConflictHttpException) { $check(true,'Stale rotation rejected'); }
    $import=new \App\Service\ImportService($db,$service,$identifiers,new \App\Service\ScheduleService($db,new \App\Service\Settings()));
    $csv=tempnam(sys_get_temp_dir(),'staff_employee_');
    $tokens=[];
    try {
        file_put_contents($csv,"ID;Фамилия;Имя;Активен\n".$employee['pass_code'].";Новое имя;Иван;1\n;Новый;Человек;1\n");
        $preview=$import->preview(new \Symfony\Component\HttpFoundation\File\UploadedFile($csv,'employees.csv'),'merge',100); $tokens[]=$preview['token'];
        $check($preview['summary']['errors']===0 && $preview['rows'][0]['display_id']===$employee['pass_code'] && $preview['rows'][0]['data']['id']===101,'CSV cannot match an existing alpha ID');
        $committed=$import->commit($preview['token'],100);
        $check($committed['summary']['created']===1 && str_starts_with($committed['summary']['pass_codes'][0],'VVE-') && (int)$db->scalar('SELECT group_id FROM schedule_assignments WHERE employee_id=102')===1,'Blank CSV ID did not receive a new random pass and active default schedule');
        $again=$import->commit($preview['token'],100);
        $check($again['duplicate']===true,'CSV response retry duplicated an alpha employee');
        file_put_contents($csv,"ID;Фамилия;Имя;Активен\n007;Уволен;Старый;1\n");
        $preview=$import->preview(new \Symfony\Component\HttpFoundation\File\UploadedFile($csv,'employees.csv'),'merge',100); $tokens[]=$preview['token'];
        $check($preview['summary']['errors']===1,'CSV used a revoked numeric code as an internal ID fallback');
    } finally { unlink($csv); foreach($tokens as $token) { $path=dirname(__DIR__).'/var/import/'.$token.'.json'; if(is_file($path)) { unlink($path); } } }
    $db->transaction(fn()=>$service->applyImportRow(['id'=>'4294967295','surname'=>'Максимум','given_name'=>'Номер'],100));
    $check((int)$db->scalar('SELECT next_id FROM employee_id_sequence')===4294967296,'Maximum imported ID overflows the sequence');
    try { $service->save(['surname'=>'Следующий','given_name'=>'Не помещается'],100); $check(false,'Exhausted ID range must fail'); } catch(\InvalidArgumentException) { $check(true,'ID range exhaustion rejected'); }
    echo $checks." EmployeeService regression checks passed\n";
}

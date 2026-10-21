<?php
declare(strict_types=1);

/** Real MariaDB integration. Uses only an explicitly supplied EMPTY disposable *_test DB. */
use App\Infrastructure\Database;
use App\Service\{Settings,ScheduleService,AttendanceService,PrivateAutomationService};

$dsn=(string)getenv('STAFF_TEST_DSN');
if($dsn==='')throw new RuntimeException('STAFF_TEST_DSN must identify an EMPTY disposable MariaDB database ending in _test. DATABASE_URL is never read.');
require dirname(__DIR__).'/vendor/autoload.php';
$db=new Database($dsn);$database=(string)$db->scalar('SELECT DATABASE()');
if(!preg_match('/_test$/D',$database)||(int)$db->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')!==0)throw new RuntimeException('An EMPTY disposable *_test database is required.');
if(!str_contains((string)$db->scalar('SELECT VERSION()'),'MariaDB'))throw new RuntimeException('MariaDB is required for the scheduler transaction tests.');
$schema=preg_replace('/^\s*--.*$/m','',file_get_contents(dirname(__DIR__).'/migrations/schema.sql'));
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$schema)))as$sql)$db->exec($sql);
$now=new DateTimeImmutable('2026-10-21T09:59:00+11:00');$clock=static function()use(&$now):DateTimeImmutable{return $now;};
$settings=new Settings($db);$settings->set('history_start','2026-10-01');$baselineAudit=(int)$db->scalar('SELECT COUNT(*) FROM audit_log');
$schedule=new ScheduleService($db,$settings,$clock);$attendance=new AttendanceService($db,$settings,$schedule,$clock);$automation=new PrivateAutomationService($db,$settings,$schedule,$attendance,$clock);
$db->insert('employees',['id'=>0,'pass_code'=>'000','surname'=>'Технический','given_name'=>'Администратор','is_service'=>1,'admin_role'=>'ADMIN']);
$db->insert('schedule_groups',['id'=>1,'name'=>'Тестовый офис','start_time'=>'09:00:00','end_time'=>'18:00:00','weekdays'=>'[1,2,3,4,5]','effective_from'=>'2026-01-01']);
foreach(range(1,18)as$id){$db->insert('employees',['id'=>$id,'pass_code'=>str_pad((string)$id,3,'0',STR_PAD_LEFT),'surname'=>'Тестовый','given_name'=>'Сотрудник '.$id,'started_on'=>'2026-01-01']);$db->insert('schedule_assignments',['employee_id'=>$id,'group_id'=>1,'valid_from'=>'2026-01-01']);}
$db->exec('UPDATE employees SET is_service=1 WHERE id=18');
$passed=0;$assert=static function(bool $condition,string $message)use(&$passed):void{if(!$condition)throw new RuntimeException($message);++$passed;};
$fails=static function(callable $operation,int $status,string $message)use($assert):void{try{$operation();}catch(DomainException $e){$assert($e->getCode()===$status,$message.' (status)');return;}$assert(false,$message);};
$uuid=static function():string{$h=bin2hex(random_bytes(16));return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20);};
$base=static fn(int $id,string $event='arrival'):array=>['employee_id'=>$id,'name'=>'Правило '.$id.' '.$event,'event_type'=>$event,'trigger_time'=>'10:00','mark_time'=>'09:00','trigger_day_offset'=>0,'weekdays'=>[1,2,3,4,5],'working_days_only'=>1,'valid_from'=>'2026-10-21'];
$rule=static fn(int $id,string $event='arrival',array $extra=[]):array=>$automation->save(array_replace($base($id,$event),['enabled'=>1],$extra));
$findRun=static fn(int $id,string $date='2026-10-21'):?array=>$db->one('SELECT * FROM private_automation_runs WHERE rule_id=? AND work_date=?',[$id,$date]);
$counts=static function()use($db):array{$out=[];foreach($db->all('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name')as$row){$table=$row['table_name'];$out[$table]=(int)$db->scalar('SELECT COUNT(*) FROM `'.$table.'`');}return $out;};

$empty=$automation->runDue();$assert($empty['processed']===0&&$empty['errors']===0,'No enabled rules must not create attendance or run records');
$assert((int)$db->scalar('SELECT COUNT(*) FROM private_automation_runs')===0&&(int)$db->scalar('SELECT COUNT(*) FROM attendance_days')===0,'An idle scheduler does not create days');
$disabled=$automation->save($base(1));$assert(!$disabled['enabled']&&$disabled['weekdays']===[1,2,3,4,5],'New rules default to disabled and normalize weekdays');
$assert($automation->runDue()['processed']===0,'Disabled rule is inert');
$enabled=$automation->toggle(['id'=>$disabled['id'],'version'=>$disabled['version'],'enabled'=>1]);$assert($enabled['enabled']&&$enabled['enabled_from']==='2026-10-21','Explicit enable starts today');
$assert($automation->runDue()['processed']===0,'A minute before trigger is not due');
$now=new DateTimeImmutable('2026-10-21T10:00:00+11:00');$before=$counts();$state=$automation->status();$dry=$automation->runDue(null,true);$assert($dry['would_apply']===1&&$dry['applied']===0,'Dry run predicts the due missing mark');
$assert($before===$counts()&&$state===$automation->status(),'Dry run must not write to any table, including plan cache and heartbeat');
$applied=$automation->runDue();$assert($applied['applied']===1&&$applied['errors']===0,'At exact local trigger the mark is applied once');
$public=$attendance->day(1,'2026-10-21');$assert($public['values']['arrival']==='09:00','Configured time, not trigger time, is stored');
$assert($db->scalar('SELECT arrival_at FROM attendance_days WHERE employee_id=1 AND work_date=?',['2026-10-21'])==='2026-10-20 22:00:00','Sakhalin mark is stored in UTC');
$assert($public['events']===[]&&$public['changes']===[]&&$public['flags']===[],'Automation details do not appear in ordinary day facts or findings');
$assert($findRun((int)$enabled['id'])['status']==='applied','Private run stores the applied outcome');
$beforeChanges=(int)$db->scalar('SELECT COUNT(*) FROM attendance_changes');$assert($automation->runDue()['processed']===0&&(int)$db->scalar('SELECT COUNT(*) FROM attendance_changes')===$beforeChanges,'Repeated tick is idempotent');
$now=new DateTimeImmutable('2026-10-21T10:05:00+11:00');$attendance->mark(['request_id'=>$uuid(),'code'=>'001','event_type'=>'arrival']);$public=$attendance->day(1,'2026-10-21');
$assert($public['values']['arrival']==='10:05'&&count($public['events'])===1,'A later real scan replaces its automatic provisional value');
$assert(in_array('LATE_ARRIVAL',array_column($public['flags'],'kind'),true),'Real late arrival after automation is evaluated normally');

$now=new DateTimeImmutable('2026-10-21T10:10:00+11:00');$attendance->mark(['request_id'=>$uuid(),'code'=>'002','event_type'=>'arrival']);$existing=$rule(2);$automation->runDue();
$assert($findRun((int)$existing['id'])['status']==='already_present'&&$attendance->day(2,'2026-10-21')['values']['arrival']==='10:10','Existing QR mark is preserved');
$manual=$attendance->manual(['request_id'=>$uuid(),'employee_id'=>3,'code'=>'003','work_date'=>'2026-10-21','version'=>0,'values'=>['arrival'=>'09:45']]);$manualRule=$rule(3);$automation->runDue();
$assert($findRun((int)$manualRule['id'])['status']==='already_present'&&$attendance->day(3,'2026-10-21')['values']['arrival']==='09:45','Existing manual value is preserved');
$departure=$rule(3,'departure',['trigger_time'=>'23:30','mark_time'=>'23:00']);$now=new DateTimeImmutable('2026-10-21T23:29:00+11:00');$automation->runDue();$assert($findRun((int)$departure['id'])===null,'Late departure waits for its own trigger');
$now=new DateTimeImmutable('2026-10-21T23:30:00+11:00');$automation->runDue();$public=$attendance->day(3,'2026-10-21');$assert($public['values']['arrival']==='09:45'&&$public['values']['departure']==='23:00','Late departure fills only its field without changing arrival');
$off=$automation->toggle(['id'=>$departure['id'],'version'=>$departure['version'],'enabled'=>0]);$assert(!$off['enabled'],'Rule can be disabled');
$fails(static fn()=>$automation->toggle(['id'=>$departure['id'],'version'=>$departure['version'],'enabled'=>1]),409,'Stale toggle version is rejected');
$fails(static fn()=>$automation->save(array_replace($base(4),['enabled'=>1,'valid_from'=>'2026-10-20'])),400,'New enabled rule cannot silently fill older days');
$fails(static fn()=>$automation->save(array_replace($base(4),['mark_time'=>'11:00','enabled'=>1])),400,'Future mark relative to same-day trigger is rejected');
$fails(static fn()=>$automation->save(array_replace($base(4),['trigger_time'=>'25:00'])),400,'Invalid time is rejected');
$fails(static fn()=>$automation->save(array_replace($base(4),['weekdays'=>[8]])),400,'Invalid weekday is rejected');
$fails(static fn()=>$automation->save(array_diff_key($base(4),['weekdays'=>true])),400,'Omitted weekdays cannot silently restore Monday-Friday');
$fails(static fn()=>$automation->save(array_replace($base(4),['weekdays'=>[]])),400,'Empty weekdays are rejected');
$fails(static fn()=>$automation->save(array_replace($base(4),['event_type'=>'anything'])),400,'Unknown event is rejected');
$fails(static fn()=>$automation->save(array_replace($base(18),['enabled'=>1])),404,'Service account cannot receive a rule');
$fails(static fn()=>$automation->save(array_replace($base(999),['enabled'=>1])),404,'Unknown employee cannot receive a rule');
$fails(static fn()=>$automation->save(array_replace($base(1),['name'=>'Дубликат'])),409,'Duplicate employee/event/trigger slot is rejected');

$now=new DateTimeImmutable('2026-10-22T10:00:00+11:00');
$db->exec('UPDATE employees SET active=0 WHERE id=4');$inactive=$rule(4,'arrival',['valid_from'=>'2026-10-22']);
$db->exec('UPDATE employees SET is_guard=1 WHERE id=5');$guard=$rule(5,'arrival',['valid_from'=>'2026-10-22']);
$db->insert('employee_leaves',['employee_id'=>6,'start_date'=>'2026-10-22','end_date'=>'2026-10-22','actor_employee_id'=>0,'created_at'=>'2026-10-21 23:00:00','updated_at'=>'2026-10-21 23:00:00']);$leave=$rule(6,'arrival',['valid_from'=>'2026-10-22']);
$db->exec('UPDATE employees SET started_on=? WHERE id=7',['2026-10-23']);$notStarted=$rule(7,'arrival',['valid_from'=>'2026-10-22']);
$db->insert('employee_profile_versions',['employee_id'=>8,'surname'=>'Тестовый','given_name'=>'Бывший','active'=>0,'valid_from'=>'2026-10-22']);$gap=$rule(8,'arrival',['valid_from'=>'2026-10-22']);
$automation->runDue();foreach([$inactive,$guard,$leave,$notStarted,$gap]as$r){$run=$findRun((int)$r['id'],'2026-10-22');$assert($run!==null&&$run['status']==='skipped','Inactive/guard/leave/not-started/employment-gap day is skipped');}
$assert((int)$db->scalar('SELECT COUNT(*) FROM attendance_days WHERE employee_id BETWEEN 4 AND 8')===0,'Exempt employees receive no fabricated day');

$now=new DateTimeImmutable('2026-10-23T09:00:00+11:00');$offset=$rule(9,'departure',['trigger_time'=>'08:00','mark_time'=>'23:00','trigger_day_offset'=>1,'valid_from'=>'2026-10-23']);
$assert($automation->runDue()['errors']===0&&$findRun((int)$offset['id'],'2026-10-23')===null,'Next-morning rule does not fire on its workday');
$now=new DateTimeImmutable('2026-10-24T07:59:00+11:00');$automation->runDue();$assert($findRun((int)$offset['id'],'2026-10-23')===null,'Next-morning rule waits until next-day trigger');
$now=new DateTimeImmutable('2026-10-24T08:00:00+11:00');$automation->runDue();$assert($findRun((int)$offset['id'],'2026-10-23')['status']==='applied'&&$attendance->day(9,'2026-10-23')['values']['departure']==='23:00','Next-day trigger fills the previous work date, including late23:00');
$weekend=$rule(10,'arrival',['weekdays'=>[1,2,3,4,5,6,7],'valid_from'=>'2026-10-24']);$workingWeekend=$rule(11,'arrival',['weekdays'=>[1,2,3,4,5,6,7],'working_days_only'=>0,'valid_from'=>'2026-10-24']);
$now=new DateTimeImmutable('2026-10-24T10:00:00+11:00');$automation->runDue();$assert($findRun((int)$weekend['id'],'2026-10-24')['status']==='skipped','Working-only rule honors weekends');
$assert($findRun((int)$workingWeekend['id'],'2026-10-24')['status']==='applied','Explicit all-days rule can fill a weekend');
$daysRule=$rule(12,'arrival',['valid_from'=>'2026-10-24']);$automation->runDue();$assert($findRun((int)$daysRule['id'],'2026-10-24')===null,'Rule weekdays apply to the work date');
$db->insert('calendar_exceptions',['employee_id'=>13,'work_date'=>'2026-10-24','is_working'=>1,'start_time'=>'09:00','end_time'=>'18:00','scope_key'=>'employee:13']);$holiday=$rule(13,'arrival',['weekdays'=>[6],'valid_from'=>'2026-10-24']);$automation->runDue();$assert($findRun((int)$holiday['id'],'2026-10-24')['status']==='applied','Explicit working Saturday follows calendar exception');
$paused=$automation->save(array_replace($base(14),['valid_from'=>'2026-10-21']));$resumed=$automation->toggle(['id'=>$paused['id'],'version'=>$paused['version'],'enabled'=>1]);$assert($resumed['valid_from']==='2026-10-24'&&$resumed['enabled_from']==='2026-10-24','Re-enabling old disabled rule starts today');
$now=new DateTimeImmutable('2026-10-27T10:00:00+11:00');$automation->runDue();$assert($findRun((int)$daysRule['id'],'2026-10-26')!==null&&$findRun((int)$daysRule['id'],'2026-10-27')!==null&&$findRun((int)$daysRule['id'],'2026-10-25')===null,'Catch-up covers yesterday and today only');
$assert(count($automation->recentRuns(999))<=50&&count($automation->listRules(1))===1,'Private list filters and recent run limit are bounded');
$assert($automation->status()['last_success_at']!==null&&$automation->status()['last_error']==='','Scheduler heartbeat reports success');
$assert((int)$db->scalar('SELECT COUNT(*) FROM audit_log')===$baselineAudit,'Rule changes never enter ordinary admin audit');
echo "Private automation: {$passed} MariaDB checks passed.\n";

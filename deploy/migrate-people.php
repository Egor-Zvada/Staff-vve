<?php
declare(strict_types=1);

/** Root CLI only. Default is a read-only preview; commit needs the hash of the reviewed file. */
if (PHP_SAPI !== 'cli' || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) { fwrite(STDERR,"Run migration as root CLI.\n"); exit(1); }
$options=getopt('',['app:','preview:','actor:','group:','trainer-group:','schedule-map:','schedule-map-sha256:','commit','sha256:']);
$app=rtrim((string)($options['app']??'/opt/staff-v2/app'),'/');
$previewPath=(string)($options['preview']??'');
if ($previewPath==='' || !is_readable($previewPath) || filesize($previewPath)>4*1024*1024) { fwrite(STDERR,"Supply a readable employee preview (maximum 4 MiB).\n"); exit(1); }
try {
    $preview=json_decode((string)file_get_contents($previewPath),true,32,JSON_THROW_ON_ERROR);
    if (($preview['schema']??'')!=='staff-v2-employees-migration-v1' || ($preview['source']??'')!=='staff1.employees' || !is_array($preview['rows']??null) || !$preview['rows'] || count($preview['rows'])>1000 || ($preview['summary']['errors']??1)!==0 || ($preview['summary']['review']??1)!==0) { throw new RuntimeException('Unreviewed/invalid employee preview'); }
    require $app.'/vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv($app.'/.env');
    date_default_timezone_set('Asia/Sakhalin');
    $hash=hash_file('sha256',$previewPath);
    if (isset($options['commit']) && (!isset($options['sha256']) || !hash_equals($hash,strtolower((string)$options['sha256'])))) { fwrite(STDERR,"Commit requires --sha256 matching the reviewed preview file.\n"); exit(1); }
    $database=new App\Infrastructure\Database($_SERVER['DATABASE_URL']);
    $settings=new App\Service\Settings($database);
    $employees=new App\Service\EmployeeService($database,$settings);
    $schedules=new App\Service\ScheduleService($database,$settings);
    $actor=filter_var($options['actor']??0,FILTER_VALIDATE_INT);
    if ($actor===false || $actor<0) { throw new RuntimeException('Invalid actor'); }
    $account=$database->one('SELECT id,active,admin_role FROM employees WHERE id=?',[$actor]);
    if (!$account || (int)$account['active']!==1 || $account['admin_role']!=='ADMIN') { throw new RuntimeException('Actor must be an existing active administrator'); }
    $group=isset($options['group']) ? App\Service\EmployeeService::id($options['group']) : null;
    if ($group!==null && !$database->one('SELECT id FROM schedule_groups WHERE id=? AND active=1',[$group])) { throw new RuntimeException('Invalid active schedule group'); }
    $trainerGroup=isset($options['trainer-group']) ? App\Service\EmployeeService::id($options['trainer-group']) : null;
    if ($trainerGroup!==null && !$database->one('SELECT id FROM schedule_groups WHERE id=? AND active=1',[$trainerGroup])) { throw new RuntimeException('Invalid trainer schedule group'); }
    $rows=[]; $ids=[];
    foreach ($preview['rows'] as $row) {
        if (!is_array($row)) { throw new RuntimeException('Invalid row'); }
        $id=App\Service\EmployeeService::id($row['id']??null);
        if (isset($ids[$id])) { throw new RuntimeException('Duplicate employee number'); }
        $ids[$id]=true; $data=['id'=>$id];
        foreach (['surname','given_name','patronymic','legacy_full_name','position'] as $key) {
            $value=$row[$key]??'';
            if (!is_string($value) || mb_strlen($value)>($key==='position' || $key==='legacy_full_name' ? 255 : 160) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$value)) { throw new RuntimeException('Invalid employee field'); }
            $data[$key]=$value;
        }
        if ($data['surname']==='' || $data['given_name']==='' || !in_array($row['active']??null,[0,1],true)) { throw new RuntimeException('Invalid employee name/status'); }
        $data['active']=$row['active'];
        // No passwords, roles or guessed historical dates are taken from the legacy application.
        $rows[]=$data;
    }
    $scheduleMap=null; $scheduleMapHash=null;
    if (isset($options['schedule-map'])) {
        $mapPath=(string)$options['schedule-map'];
        if (!is_readable($mapPath) || filesize($mapPath)>2*1024*1024) { throw new RuntimeException('Invalid schedule mapping file'); }
        $map=json_decode((string)file_get_contents($mapPath),true,32,JSON_THROW_ON_ERROR);
        if (!hash_equals($hash,(string)($map['source_sha256']??'')) || !is_array($map['rows']??null) || count($map['rows'])!==count($rows)) { throw new RuntimeException('Schedule map does not match the reviewed employee preview'); }
        $scheduleMapHash=hash_file('sha256',$mapPath);
        if (isset($options['commit']) && (!isset($options['schedule-map-sha256']) || !hash_equals($scheduleMapHash,strtolower((string)$options['schedule-map-sha256'])))) { fwrite(STDERR,"Mapped commit requires --schedule-map-sha256 of the reviewed mapping.\n"); exit(1); }
        $scheduleMap=[];
        foreach ($map['rows'] as $entry) {
            $id=App\Service\EmployeeService::id($entry['id']??null);
            if (!isset($ids[$id]) || array_key_exists($id,$scheduleMap) || !is_bool($entry['assign']??null)) { throw new RuntimeException('Invalid schedule map row'); }
            $target=$entry['assign'] ? App\Service\EmployeeService::id($entry['group_id']??null) : null;
            if ($target!==null && !$database->one('SELECT id FROM schedule_groups WHERE id=? AND active=1',[$target])) { throw new RuntimeException('Map references an invalid active group'); }
            $scheduleMap[$id]=$target;
        }
        foreach($rows as $data) { if ($data['active']===0 && $scheduleMap[$data['id']]!==null) { throw new RuntimeException('Retired employees cannot receive new schedule assignments'); } }
    }
    $migrationHash=hash('sha256',$hash.'|'.($scheduleMapHash??'').'|'.($group??'').'|'.($trainerGroup??''));
    $inspect=static function()use($database,$rows):array {
        $summary=['create'=>0,'unchanged'=>0,'active'=>0,'inactive'=>0,'conflicting_ids'=>[]];
        foreach ($rows as $data) {
            ++$summary[$data['active']===1?'active':'inactive'];
            $existing=$database->one('SELECT id,surname,given_name,patronymic,legacy_full_name,position,active,is_service FROM employees WHERE id=?',[$data['id']]);
            if (!$existing) { ++$summary['create']; continue; }
            $equal=(int)$existing['is_service']===0;
            foreach($data as $key=>$value) { if ((string)($existing[$key]??'')!==(string)$value) { $equal=false; } }
            if ($equal) { ++$summary['unchanged']; } else { $summary['conflicting_ids'][]=$data['id']; }
        }
        return $summary;
    };
    $summary=$inspect();
    $trainerRows=count(array_filter($rows,static fn(array $row):bool=>$row['active']===1 && preg_match('/(?:^|[^\p{L}])тренер(?:[^\p{L}]|$)/iu',$row['position'])===1));
    echo json_encode(['dry_run'=>!isset($options['commit']),'preview_sha256'=>$hash,'schedule_map_sha256'=>$scheduleMapHash,'migration_hash'=>$migrationHash,'source_rows'=>count($rows),'next_id'=>$database->scalar('SELECT next_id FROM employee_id_sequence WHERE id=1'),'default_group_id'=>$group,'trainer_group_id'=>$trainerGroup,'active_explicit_trainers'=>$trainerRows,'summary'=>$summary],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    if ($summary['conflicting_ids']) { fwrite(STDERR,"Existing employee conflicts: no rows were changed.\n"); exit(2); }
    if (!isset($options['commit'])) { exit(0); }
    $result=$database->transaction(static function()use($database,$employees,$schedules,$rows,$actor,$group,$trainerGroup,$scheduleMap,$migrationHash,$inspect):array {
        $sequence=$database->one('SELECT next_id FROM employee_id_sequence WHERE id=1 FOR UPDATE')??throw new RuntimeException('Missing employee sequence');
        $batch=$database->one('SELECT id,summary_json FROM import_batches WHERE file_hash=? AND mode=? LIMIT 1',[$migrationHash,'legacy_people']);
        if ($batch) { return ['success'=>true,'duplicate'=>true,'batch_id'=>(int)$batch['id'],'summary'=>json_decode($batch['summary_json'],true,32,JSON_THROW_ON_ERROR)]; }
        $summary=$inspect();
        if ($summary['conflicting_ids']) { throw new RuntimeException('An employee changed after preview'); }
        $facts=[];
        foreach (['attendance_events','attendance_days','attendance_changes','attendance_flags'] as $table) { $facts[$table]=(int)$database->scalar('SELECT COUNT(*) FROM '.$table); }
        $next=max((int)$sequence['next_id'],max(array_column($rows,'id'))+1);
        $database->exec('UPDATE employee_id_sequence SET next_id=? WHERE id=1',[$next]);
        $created=0; $assigned=0;
        foreach ($rows as $data) {
            $existing=$database->one('SELECT id FROM employees WHERE id=?',[$data['id']]);
            if (!$existing) { $employees->applyImportRow($data,$actor); ++$created; }
            $assignedGroup=$scheduleMap!==null ? $scheduleMap[$data['id']] : ($trainerGroup!==null && preg_match('/(?:^|[^\p{L}])тренер(?:[^\p{L}]|$)/iu',$data['position'])===1 ? $trainerGroup : $group);
            if ($assignedGroup!==null && $data['active']===1 && (!$existing || $scheduleMap!==null)) {
                $today=App\Service\EmployeeService::today();
                $current=$database->one('SELECT id,group_id,valid_from FROM schedule_assignments WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',[$data['id'],$today,$today]);
                if ($current && (int)$current['group_id']===$assignedGroup) { continue; }
                $assignment=['employee_id'=>$data['id'],'group_id'=>$assignedGroup,'valid_from'=>$today];
                if ($current && $current['valid_from']===$today) { $assignment['id']=(int)$current['id']; }
                $schedules->saveAssignment($assignment,$actor); ++$assigned;
            }
        }
        foreach ($facts as $table=>$count) { if ((int)$database->scalar('SELECT COUNT(*) FROM '.$table)!==$count) { throw new RuntimeException('Attendance facts unexpectedly changed'); } }
        $summary+=['created'=>$created,'schedule_assignments_created'=>$assigned,'next_id'=>$next,'attendance_unchanged'=>true];
        $database->insert('import_batches',['file_hash'=>$migrationHash,'mode'=>'legacy_people','summary_json'=>json_encode($summary,JSON_THROW_ON_ERROR),'actor_employee_id'=>$actor,'created_at'=>App\Service\EmployeeService::utc()]);
        $batch=(int)$database->db->lastInsertId();
        $employees->audit($actor,'employees.legacy_import','import',$batch,null,['file_hash'=>$migrationHash,'summary'=>$summary]);
        return ['success'=>true,'duplicate'=>false,'batch_id'=>$batch,'summary'=>$summary];
    });
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Do not print rows, SQL parameters, names or credentials if validation/database code fails.
    fwrite(STDERR,'Employee migration failed ('.get_class($error)."); no partial commit.\n");
    exit(1);
}

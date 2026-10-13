<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Root CLI required');
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/staff-v2/app/.env');
$db=new App\Infrastructure\Database($_SERVER['DATABASE_URL']);$settings=new App\Service\Settings($db);
$start=$argv[1]??'';App\Service\ScheduleService::validateDate($start);
$db->transaction(function()use($db,$settings,$start){
 if((int)$db->scalar('SELECT COUNT(*) FROM attendance_events')!==0||(int)$db->scalar('SELECT COUNT(*) FROM attendance_changes')!==0)throw new RuntimeException('Use the administration settings after real attendance begins');
 $settings->set('history_start',$start,7);
 // Remove only derived pre-launch findings and empty projections. No captured facts exist.
 $db->exec("DELETE FROM attendance_flags WHERE work_date<? AND kind IN ('LATE_ARRIVAL','EARLY_DEPARTURE','MISSING_ARRIVAL','MISSING_DEPARTURE','NO_MARKS','INCOMPLETE_BREAK','INVALID_ORDER')",[$start]);
 $db->exec("DELETE FROM attendance_days WHERE work_date<? AND version=0 AND overrides_json='{}'",[$start]);
 $db->exec('DELETE FROM schedule_plan_cache WHERE work_date<?',[$start]);
});
echo json_encode(['statistics_start'=>$start,'recorded_attendance_unchanged'=>true],JSON_THROW_ON_ERROR).PHP_EOL;

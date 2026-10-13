<?php
declare(strict_types=1);
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/staff-v2/app/.env');
date_default_timezone_set('Asia/Sakhalin');
$database=new App\Infrastructure\Database($_SERVER['DATABASE_URL']);
$settings=new App\Service\Settings($database);
$schedules=new App\Service\ScheduleService($database,$settings);
if(!(int)$database->scalar('SELECT COUNT(*) FROM schedule_groups'))foreach([['Офис · 09:00–18:00','18:00'],['Офис · 09:00–17:00','17:00'],['Тренеры · 09:00–20:00','20:00']] as [$name,$end])$schedules->saveGroupVersion(['name'=>$name,'start_time'=>'09:00','end_time'=>$end,'weekdays'=>[1,2,3,4,5],'valid_from'=>date('Y-m-d'),'active'=>1],0);
echo "Initial schedule groups available.\n";

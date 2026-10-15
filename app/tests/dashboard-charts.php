<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Service/DashboardChartService.php';

use App\Service\DashboardChartService;

$passed=0;
$assert=static function (bool $condition,string $message) use (&$passed): void { if (!$condition) { throw new RuntimeException($message); } ++$passed; };
$row=static fn (int $id,string $date,?string $arrival,array $flags=[],array $extra=[]): array => array_replace_recursive(['employee_id'=>$id,'full_name'=>'Сотрудник '.$id,'work_date'=>$date,'group_name'=>'Офис','is_working'=>true,'raw_values'=>['arrival'=>$arrival],'values'=>['arrival'=>$arrival],'flags'=>array_map(static fn(string $kind):array=>['kind'=>$kind],$flags),'changes'=>[]],$extra);
$rows=[
    $row(1,'2026-10-15','09:00:00',['MISSING_DEPARTURE']),
    $row(2,'2026-10-15','09:12:00',['LATE_ARRIVAL']),
    $row(3,'2026-10-15',null,['MISSING_ARRIVAL','NO_MARKS']),
    $row(4,'2026-10-15','11:00:00',['LATE_ARRIVAL'],['is_guard'=>true]),
    $row(5,'2026-10-15','11:00:00',['LATE_ARRIVAL'],['on_leave'=>true]),
    $row(6,'2026-10-15','11:00:00',['LATE_ARRIVAL'],['is_working'=>false]),
    $row(7,'2026-10-15',null),
    $row(8,'2026-10-15',null,[],['values'=>['arrival'=>'09:00:00'],'changes'=>[['event_type'=>'arrival']]]),
    $row(9,'2026-10-15','09:25:00',['LATE_ARRIVAL'],['values'=>['arrival'=>'09:00:00']]),
    $row(10,'2026-10-15','08:55:00',[],['flags'=>[['kind'=>'LATE_ARRIVAL','suppressed'=>1]]]),
    $row(1,'2026-10-14','08:50:00'),
    $row(2,'2026-10-14','09:03:00',['LATE_ARRIVAL'],['group_name'=>'Тренеры']),
    $row(11,'2026-10-01',null,['NO_MARKS']),
];
$result=DashboardChartService::build($rows,['2026-10-14','2026-10-15'],'2026-10-15');
$day=$result['chart'][1];
$assert($day['expected']===7,'Working cohorts exclude guards, leave and off-plan rows even if they contain marks');
$assert($day['missing']===1,'Missing departure never becomes missing arrival and combined missing flags count once');
$assert(array_column($day['cohorts']['missing'],'employee_id')===[3] && $day['cohorts']['missing'][0]['arrival']===null,'Missing list has exactly the expected person and no arrival time');
$assert($day['arrived']===4 && $day['late']===2,'Only recorded arrival facts enter punctuality totals');
$assert(array_column($day['cohorts']['late'],'employee_id')===[2,9],'Late drill-down matches the late bar exactly');
$assert($day['cohorts']['late'][1]['arrival']==='09:25:00','Late employee shows the actual recorded arrival, rather than a claimed correction');
$assert(array_column($day['cohorts']['on_time'],'employee_id')===[1,10],'Missing departure and suppressed late flag do not contaminate arrival cohorts');
$assert($day['pending']===2,'Before the missing deadline and correction-only times stay outside arrived and missing categories');
$assert($day['arrived']+$day['missing']+$day['pending']===$day['expected'],'Stacked categories are disjoint and never exceed expected people');
$groups=array_column($result['group_chart'],null,'name');
$assert($groups['Офис']['arrived']===5 && $groups['Офис']['late']===2 && $groups['Офис']['late_percent']===40.0,'Group percentage uses the same eligible arrival facts across the whole period');
$assert($groups['Тренеры']['cohort'][0]['date']==='2026-10-14','Group drill-down retains the work date for each late arrival');
$assert(array_column($result['hour_chart'],'hour')===[8,9] && array_column($result['hour_chart'],'count')===[1,3],'Hour chart is chronological and contains only the selected day');
$assert(array_sum(array_column($result['hour_chart'],'count'))===$day['arrived'],'Hourly chart and selected-day arrival totals agree');
$empty=DashboardChartService::build([$row(1,'2026-10-15',null,['MISSING_DEPARTURE'])],['2026-10-15'],'2026-10-15');
$assert($empty['chart'][0]['missing']===0 && $empty['group_chart'][0]['late_percent']===null && $empty['hour_chart']===[],'No recorded arrivals yields no percentage or phantom hour');
$private=DashboardChartService::build([$row(1,'2026-10-15','09:01:00',[],['flags'=>[['kind'=>'LATE_ARRIVAL','private'=>1]]])],['2026-10-15'],'2026-10-15');
$assert($private['chart'][0]['late']===0 && count($private['chart'][0]['cohorts']['on_time'])===1,'Private findings do not leak through counts or lists');
echo "Dashboard charts: {$passed} checks passed.\n";

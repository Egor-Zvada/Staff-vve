<?php
declare(strict_types=1);
namespace App\Service;
final class AttendanceFilter
{
    public static function apply(array $rows,string $finding):array
    {
        if($finding==='')return $rows;
        if(!in_array($finding,['flags','changes','late','early','retro'],true))throw new \InvalidArgumentException('Неизвестный фильтр табеля');
        return array_values(array_filter($rows,static function(array $row)use($finding):bool{
            $kinds=array_column($row['flags']??[],'kind');
            return match($finding){'flags'=>count($kinds)>0,'changes'=>count($row['changes']??[])>0,'late'=>in_array('LATE_ARRIVAL',$kinds,true),'early'=>in_array('EARLY_DEPARTURE',$kinds,true),'retro'=>count(array_intersect($kinds,['PRIOR_DAY_CHANGE','EARLIER_TIME_CLAIM']))>0};
        }));
    }
}

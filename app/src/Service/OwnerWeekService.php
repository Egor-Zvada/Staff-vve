<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Only the independently authenticated private controller may call this service. */
final class OwnerWeekService
{
    private const FIELDS = ['arrival','departure','lunch_out','lunch_in','excused_out'];
    private readonly Closure $clock;

    public function __construct(private readonly Database $db, private readonly AttendanceService $attendance, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone(ScheduleService::TIMEZONE));
    }

    public function today(): string { return ($this->clock)()->setTimezone(new DateTimeZone(ScheduleService::TIMEZONE))->format('Y-m-d'); }

    public static function monday(string $date): string
    {
        ScheduleService::validateDate($date);
        $day = new DateTimeImmutable($date, new DateTimeZone(ScheduleService::TIMEZONE));
        return $day->modify('-'.((int)$day->format('N')-1).' days')->format('Y-m-d');
    }

    public function week(int $employeeId, string $date): array
    {
        $start = self::monday($date); $first = new DateTimeImmutable($start, new DateTimeZone(ScheduleService::TIMEZONE));
        $names = [1=>'Понедельник',2=>'Вторник',3=>'Среда',4=>'Четверг',5=>'Пятница',6=>'Суббота',7=>'Воскресенье'];
        $days = [];
        for ($i=0;$i<7;$i++) {
            $day = $first->modify('+'.$i.' days'); $workDate = $day->format('Y-m-d');
            if ($workDate <= $this->today()) { $this->attendance->day($employeeId,$workDate); }
            $row = $this->attendance->privateDay($employeeId,$workDate);
            $row['date_label']=$day->format('d.m.Y'); $row['weekday']=$names[(int)$day->format('N')];
            $row['is_future']=$workDate>$this->today();
            $row['active_flags']=array_values(array_filter($row['flags'],static fn(array $flag):bool=>!empty($flag['active'])&&empty($flag['private'])&&empty($flag['suppressed'])));
            $row['public_events_count']=count(array_filter($row['events'],static fn(array $event):bool=>empty($event['private'])));
            $row['changes_count']=count(array_filter($row['changes'],static fn(array $change):bool=>empty($change['private'])&&($change['source']??'')!=='owner_adjust'));
            $days[]=$row;
        }
        return ['start'=>$start,'end'=>$first->modify('+6 days')->format('Y-m-d'),'previous'=>$first->modify('-7 days')->format('Y-m-d'),'next'=>$first->modify('+7 days')->format('Y-m-d'),'days'=>$days];
    }

    public function save(array $data): array
    {
        $employeeId=EmployeeService::id($data['employee_id']??'');
        if ($employeeId<1) { throw new DomainException('Выберите сотрудника.',400); }
        $start=ScheduleService::validateDate((string)($data['week_start']??''));
        if (self::monday($start)!==$start) { throw new DomainException('Неделя должна начинаться с понедельника.',400); }
        $end=(new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
        $input=$data['rows']??null;
        if (!is_array($input)||count($input)>7) { throw new DomainException('Передайте не больше семи дней одной недели.',400); }
        $rows=[];
        foreach ($input as $date=>$row) {
            if (!is_array($row)) { throw new DomainException('Неверный формат строки табеля.',400); }
            if (!$this->boolean($row['selected']??false)) { continue; }
            $date=ScheduleService::validateDate((string)$date);
            if ($date<$start||$date>$end||$date>$this->today()) { throw new DomainException('Можно изменять только прошедшие и текущий дни выбранной недели.',400); }
            if (!isset($row['version'])||!is_scalar($row['version'])||!preg_match('/^\d+$/D',(string)$row['version'])) { throw new DomainException('Перечитайте актуальную версию дней.',400); }
            $values=$row['values']??null;
            if (!is_array($values)||count($values)!==5||array_diff(self::FIELDS,array_keys($values))!==[]) { throw new DomainException('Для каждого дня нужны все пять полей времени.',400); }
            foreach ($values as &$value) {
                if ($value===null||$value==='') { $value=null; }
                elseif (!is_string($value)) { throw new DomainException('Некорректное время отметки.',400); }
                else { $value=substr(ScheduleService::validateTime($value),0,5); }
            } unset($value);
            $rows[$date]=['version'=>(int)$row['version'],'values'=>$values,'clean'=>$this->boolean($row['clean']??false)];
        }
        if (!$rows) { throw new DomainException('Выберите хотя бы один день для сохранения.',400); }
        ksort($rows);
        $request=(string)($data['request_id']??'');
        if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D',$request)) { throw new DomainException('Нужен уникальный номер сохранения.',400); }
        $request='owner-week-'.hash('sha256',$request);
        $hash=hash('sha256',json_encode($this->canonical(['employee_id'=>$employeeId,'week_start'=>$start,'rows'=>$rows]),JSON_THROW_ON_ERROR));
        return $this->db->transaction(function()use($employeeId,$start,$rows,$request,$hash):array {
            $insert=$this->sqlite()?'INSERT OR IGNORE':'INSERT IGNORE';
            $this->db->exec($insert.' INTO operation_requests(request_id,payload_hash,response_json,created_at) VALUES (?,?,NULL,?)',[$request,$hash,($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
            $operation=$this->db->one('SELECT * FROM operation_requests WHERE request_id=?'.$this->lock(),[$request]);
            if (!$operation||!hash_equals($hash,(string)$operation['payload_hash'])) { throw new DomainException('Этот номер сохранения уже использован для других изменений.',409); }
            if ($operation['response_json']!==null) { return json_decode($operation['response_json'],true,512,JSON_THROW_ON_ERROR)+['replayed'=>true]; }
            $person=$this->db->one('SELECT id,is_service FROM employees WHERE id=?'.$this->lock(),[$employeeId]);
            if (!$person||!empty($person['is_service'])) { throw new DomainException('Сотрудник не найден.',404); }
            // Validate every version before touching any selected day; the transaction also
            // rolls all days back if a lower-level operation fails afterwards.
            $current=[];
            foreach ($rows as $date=>$row) {
                $current[$date]=$this->attendance->privateDay($employeeId,$date);
                if ((int)$current[$date]['version']!==$row['version']) { throw new DomainException('День '.(new DateTimeImmutable($date))->format('d.m.Y').' уже изменился. Перечитайте неделю; введённые значения пока сохранены на экране.',409); }
            }
            $saved=[];$unchanged=[];
            foreach ($rows as $date=>$row) {
                $values=$row['values'];
                if (!$row['clean']) {
                    $values=array_filter($values,static fn(mixed $value,string $field):bool=>$value!==($current[$date]['values'][$field]??null),ARRAY_FILTER_USE_BOTH);
                    if (!$values) { $unchanged[]=$date;continue; }
                }
                $this->attendance->ownerAdjust(['request_id'=>$request.':'.$date,'employee_id'=>$employeeId,'work_date'=>$date,'version'=>$row['version'],'action'=>$row['clean']?'clear_and_correct':'correct','values'=>$values,'reason'=>'']);
                $saved[]=$date;
            }
            $result=['success'=>true,'employee_id'=>$employeeId,'week_start'=>$start,'saved_dates'=>$saved,'unchanged_dates'=>$unchanged];
            $this->db->exec('UPDATE operation_requests SET response_json=? WHERE request_id=?',[json_encode($result,JSON_THROW_ON_ERROR),$request]);
            return $result;
        });
    }

    private function boolean(mixed $value): bool
    {
        if (!in_array($value,[true,false,1,0,'1','0','on','',null],true)) { throw new DomainException('Некорректная галочка в строке табеля.',400); }
        return in_array($value,[true,1,'1','on'],true);
    }
    private function sqlite():bool { return str_contains(strtolower(get_class($this->db->db->getDatabasePlatform())),'sqlite'); }
    private function lock():string { return $this->sqlite()?'':' FOR UPDATE'; }
    private function canonical(array $value):array { ksort($value);foreach($value as &$item){if(is_array($item))$item=$this->canonical($item);}unset($item);return $value; }
}

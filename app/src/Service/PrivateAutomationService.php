<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Private configuration and execution. Never expose this service through ordinary staff routes. */
final class PrivateAutomationService
{
    private const FIELDS = ['arrival','departure','lunch_out','lunch_in','excused_out'];
    private readonly Closure $clock;

    public function __construct(private readonly Database $db, private readonly Settings $settings, private readonly ScheduleService $schedules, private readonly AttendanceService $attendance, ?Closure $clock = null, private readonly string $randomSecret = 'isolated-automation-test-seed')
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function listRules(?int $employeeId = null): array
    {
        $where = $employeeId === null ? '' : ' WHERE r.employee_id = ?';
        $rows = $this->db->all("SELECT r.*,e.pass_code,e.surname,e.given_name,e.patronymic,e.active AS employee_active FROM private_automation_rules r JOIN employees e ON e.id=r.employee_id".$where.' ORDER BY r.employee_id,r.trigger_day_offset,r.trigger_time,r.id', $employeeId === null ? [] : [$employeeId]);
        return array_map($this->decorate(...), $rows);
    }

    public function save(array $data): array
    {
        $id = empty($data['id']) ? 0 : EmployeeService::id($data['id']);
        $employeeId = EmployeeService::id($data['employee_id'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) { throw new DomainException('Введите название правила длиной до 100 символов.', 400); }
        $event = (string) ($data['event_type'] ?? '');
        if (!in_array($event, self::FIELDS, true)) { throw new DomainException('Неизвестная метка автоматизации.', 400); }
        $trigger = ScheduleService::validateTime((string) ($data['trigger_time'] ?? ''));
        $mark = ScheduleService::validateTime((string) ($data['mark_time'] ?? ''));
        if (substr($trigger, -2) !== '00' || substr($mark, -2) !== '00') { throw new DomainException('Время правила задаётся с точностью до минуты.', 400); }
        $offset = $data['trigger_day_offset'] ?? 0;
        if (!in_array($offset, [0,1,'0','1'], true)) { throw new DomainException('День срабатывания: в тот же или на следующий день.', 400); }
        $offset = (int) $offset;
        if ($offset === 0 && $mark > $trigger) { throw new DomainException('Время отметки не может быть позже времени срабатывания. Для позднего ухода перенесите срабатывание.', 400); }
        $weekdays = $data['weekdays'] ?? null;
        if (!is_array($weekdays) || $weekdays === []) { throw new DomainException('Выберите хотя бы один день недели.', 400); }
        foreach ($weekdays as $weekday) { if (!is_scalar($weekday) || !preg_match('/^[1-7]$/D', (string) $weekday)) { throw new DomainException('Дни недели должны быть числами от 1 до 7.', 400); } }
        $weekdays = array_values(array_unique(array_map('intval', $weekdays))); sort($weekdays);
        $from = ScheduleService::validateDate((string) (($data['valid_from'] ?? '') ?: $this->today()));
        $to = empty($data['valid_to']) ? null : ScheduleService::validateDate((string) $data['valid_to']);
        if ($to !== null && $to < $from) { throw new DomainException('Окончание действия раньше начала.', 400); }
        $enabled = EmployeeService::bool($data['enabled'] ?? false);
        $workingOnly = EmployeeService::bool($data['working_days_only'] ?? true);
        $randomMinutes = array_key_exists('random_minutes',$data) ? EmployeeService::bool($data['random_minutes']) : null;

        return $this->db->transaction(function () use ($data,$id,$employeeId,$name,$event,$trigger,$mark,$offset,$weekdays,$from,$to,$enabled,$workingOnly,$randomMinutes): array {
            $before = $id === 0 ? null : $this->db->one('SELECT * FROM private_automation_rules WHERE id=?'.$this->lock(), [$id]);
            if ($id > 0 && $before === null) { throw new DomainException('Правило не найдено.', 404); }
            if ($before !== null) { $this->checkVersion($before, $data); }
            $randomMinutes ??= (int)($before['random_minutes']??0);
            if ($randomMinutes && !in_array($event,['arrival','departure'],true)) { throw new DomainException('Случайные минуты доступны только для прихода и ухода.',400); }
            if ($randomMinutes && $mark > '23:54:00') { throw new DomainException('Для случайного сдвига задайте время не позже 23:54, чтобы отметка осталась в выбранном дне.',400); }
            $employee = $this->db->one('SELECT id,is_service FROM employees WHERE id=?'.$this->lock(), [$employeeId]);
            if ($employee === null || (int) $employee['is_service'] === 1) { throw new DomainException('Сотрудник не найден.', 404); }
            if ($this->db->one('SELECT id FROM private_automation_rules WHERE employee_id=? AND event_type=? AND trigger_time=? AND trigger_day_offset=? AND id<>?', [$employeeId,$event,$trigger,$offset,$id]) !== null) { throw new DomainException('У сотрудника уже есть правило для этой метки и времени срабатывания.', 409); }
            $newActivation = $enabled && ($before === null || !(bool) $before['enabled'] || (int) $before['employee_id'] !== $employeeId);
            if ($newActivation && $from < $this->today()) { throw new DomainException('При включении правила задайте начало действия не раньше сегодняшнего дня. Прошлое редактируется в недельном табеле.', 400); }
            $stamp = $this->stamp();
            $fields = ['employee_id'=>$employeeId,'name'=>$name,'event_type'=>$event,'trigger_time'=>$trigger,'mark_time'=>$mark,'random_minutes'=>$randomMinutes,'trigger_day_offset'=>$offset,'weekdays'=>$this->json($weekdays),'working_days_only'=>$workingOnly,'enabled'=>$enabled,'enabled_from'=>$enabled ? ($newActivation ? $this->today() : $before['enabled_from']) : ($before['enabled_from'] ?? null),'valid_from'=>$from,'valid_to'=>$to,'version'=>(int) ($before['version'] ?? 0)+1,'updated_at'=>$stamp];
            if ($before === null) { $fields['created_at']=$stamp; $this->db->insert('private_automation_rules',$fields); $id=(int)$this->db->db->lastInsertId(); }
            else { $this->update('private_automation_rules',$fields,'id=?',[$id]); }
            $after = $this->find($id);
            $this->audit('automation.rule.save',$employeeId,$before,$after);
            return $after;
        });
    }

    public function toggle(array $data): array
    {
        $id=EmployeeService::id($data['id']??'');
        return $this->db->transaction(function () use ($id,$data): array {
            $before=$this->db->one('SELECT * FROM private_automation_rules WHERE id=?'.$this->lock(),[$id]);
            if ($before===null) { throw new DomainException('Правило не найдено.',404); }
            $this->checkVersion($before,$data);
            $enabled=EmployeeService::bool($data['enabled']??false);
            $fields=['enabled'=>$enabled,'version'=>(int)$before['version']+1,'updated_at'=>$this->stamp()];
            if ($enabled && !(bool)$before['enabled']) {
                $fields['enabled_from']=$this->today();
                // Explicit enabling starts today; it never retroactively fills earlier days.
                if ($before['valid_from']<$this->today()) { $fields['valid_from']=$this->today(); }
                if ($before['valid_to']!==null && $before['valid_to']<$this->today()) { throw new DomainException('Срок правила закончился. Сначала измените дату окончания.',400); }
            }
            $this->update('private_automation_rules',$fields,'id=?',[$id]);
            $after=$this->find($id);$this->audit('automation.rule.toggle',(int)$before['employee_id'],$before,$after);
            return $after;
        });
    }

    /** Checks only today and yesterday in Sakhalin time. A dry run performs SELECTs only. */
    public function runDue(?DateTimeImmutable $now=null, bool $dryRun=false): array
    {
        $now=($now??$this->now())->setTimezone(new DateTimeZone(ScheduleService::TIMEZONE));
        $summary=['dry_run'=>$dryRun,'processed'=>0,'applied'=>0,'would_apply'=>0,'already_present'=>0,'skipped'=>0,'errors'=>0,'results'=>[]];
        if (!$dryRun) { $this->update('private_automation_state',['last_started_at'=>$this->stamp($now)],'id=?',[1]); }
        $ids=$this->db->all('SELECT id FROM private_automation_rules WHERE enabled=1 ORDER BY trigger_day_offset,trigger_time,id');
        foreach ($ids as $entry) {
            foreach ([$now->modify('-1 day')->format('Y-m-d'),$now->format('Y-m-d')] as $date) {
                try {
                    $operation=fn():?array=>$this->execute((int)$entry['id'],$date,$now,$dryRun);
                    $result=$dryRun?$operation():$this->db->transaction($operation);
                    if ($result!==null) { ++$summary['processed']; ++$summary[$result['status']]; $summary['results'][]=$result; }
                } catch (\Throwable $error) {
                    // The service journal is restricted to operators; the UI gets no SQL or trace.
                    error_log('Private automation rule '.(int)$entry['id'].' ('.$date.') failed: '.get_class($error).': '.$error->getMessage());
                    ++$summary['errors'];
                    $summary['results'][]=['rule_id'=>(int)$entry['id'],'work_date'=>$date,'status'=>'error','message'=>'Не удалось выполнить правило. Следующая попытка — через минуту.'];
                }
            }
        }
        if (!$dryRun) {
            $fields=['last_finished_at'=>$this->stamp($now),'last_error'=>$summary['errors']?'Не выполнено правил: '.$summary['errors'].'. Проверьте журнал службы.':''];
            if (!$summary['errors']) { $fields['last_success_at']=$this->stamp($now); }
            $this->update('private_automation_state',$fields,'id=?',[1]);
        }
        return $summary;
    }

    public function recentRuns(int $limit=30): array
    {
        $limit=max(1,min(50,$limit));
        $rows=$this->db->all("SELECT r.*,p.name AS rule_name,e.pass_code,e.surname,e.given_name,e.patronymic FROM private_automation_runs r JOIN private_automation_rules p ON p.id=r.rule_id JOIN employees e ON e.id=r.employee_id ORDER BY r.created_at DESC,r.id DESC LIMIT ".$limit);
        foreach ($rows as &$row) { $row['employee_name']=trim($row['surname'].' '.$row['given_name'].' '.$row['patronymic']);$row['display_id']=(string)$row['pass_code'];$row['mark_time']=substr((string)$row['mark_time'],0,5); } unset($row);
        return $rows;
    }

    public function status(): array
    {
        return ($this->db->one('SELECT last_started_at,last_finished_at,last_success_at,last_error FROM private_automation_state WHERE id=1')??['last_started_at'=>null,'last_finished_at'=>null,'last_success_at'=>null,'last_error'=>''])+['enabled_rules'=>(int)$this->db->scalar('SELECT COUNT(*) FROM private_automation_rules WHERE enabled=1')];
    }

    private function execute(int $ruleId,string $date,DateTimeImmutable $now,bool $dryRun): ?array
    {
        $rule=$this->db->one('SELECT * FROM private_automation_rules WHERE id=?'.($dryRun?'':$this->lock()),[$ruleId]);
        if ($rule===null || !(bool)$rule['enabled'] || $date<$rule['valid_from'] || $date>($rule['valid_to']??'9999-12-31') || $date<($rule['enabled_from']??$rule['valid_from'])) { return null; }
        $weekday=(int)(new DateTimeImmutable($date,new DateTimeZone(ScheduleService::TIMEZONE)))->format('N');
        if (!in_array($weekday,array_map('intval',json_decode((string)$rule['weekdays'],true,32,JSON_THROW_ON_ERROR)),true)) { return null; }
        $trigger=(new DateTimeImmutable($date.' '.$rule['trigger_time'],new DateTimeZone(ScheduleService::TIMEZONE)))->modify('+'.(int)$rule['trigger_day_offset'].' day');
        // A keyed per-rule/day draw is stable before execution too: repeated minute
        // checks and dry runs cannot reroll a future choice. Applied values are also
        // recorded in the unique daily run, independently of later rule edits.
        $randomOffset=!empty($rule['random_minutes'])?1+(int)hexdec(substr(hash_hmac('sha256',$ruleId.':'.$date,$this->randomSecret),0,8))%5:0;
        $chosenMark=(new DateTimeImmutable($date.' '.$rule['mark_time'],new DateTimeZone(ScheduleService::TIMEZONE)))->modify('+'.$randomOffset.' minutes');
        if ($now<$trigger || $chosenMark>$now) { return null; }
        if ($this->db->one('SELECT id FROM private_automation_runs WHERE rule_id=? AND work_date=?',[$ruleId,$date])!==null) { return null; }
        $employee=$this->db->one('SELECT * FROM employees WHERE id=?'.($dryRun?'':$this->lock()),[(int)$rule['employee_id']]);
        $reason=$this->exemption($employee,$date);
        if ($reason===null) {
            $day=$this->db->one('SELECT * FROM attendance_days WHERE employee_id=? AND work_date=?',[(int)$rule['employee_id'],$date]);
            if ($day!==null && $day[$rule['event_type'].'_at']!==null) { $status='already_present';$reason='Метка уже есть. Существующее время сохранено.'; }
            else {
                if ((bool)$rule['working_days_only']) {
                    $plan=!empty($day['plan_json'])?$this->schedules->applyHistoryBoundary(json_decode((string)$day['plan_json'],true,512,JSON_THROW_ON_ERROR)):$this->schedules->plan((int)$rule['employee_id'],$date,!$dryRun);
                    if (empty($plan['is_working'])) { $reason='Нет рабочего плана на этот день.'; }
                }
                if ($reason===null) { $status=$dryRun?'would_apply':'applied';$reason=$dryRun?'Будет заполнена отсутствующая метка.':'Заполнена отсутствующая метка.'; }
            }
        }
        $status??='skipped';
        $requestId='owner-auto-'.$ruleId.'-'.$date;
        if (!$dryRun && $status==='applied') {
            $this->attendance->ownerAdjust(['request_id'=>$requestId,'action'=>'correct','employee_id'=>(int)$rule['employee_id'],'work_date'=>$date,'version'=>(int)($day['version']??0),'values'=>[$rule['event_type']=>$chosenMark->format('H:i')],'automation_rule_id'=>$ruleId,'reason'=>'Автоматизация: '.$rule['name']]);
        }
        $result=['rule_id'=>$ruleId,'rule_version'=>(int)$rule['version'],'employee_id'=>(int)$rule['employee_id'],'work_date'=>$date,'event_type'=>$rule['event_type'],'mark_time'=>$chosenMark->format('H:i:s'),'base_mark_time'=>$rule['mark_time'],'random_offset_minutes'=>$randomOffset,'status'=>$status,'message'=>$reason,'request_id'=>$status==='applied'?$requestId:null,'created_at'=>$this->stamp($now)];
        if (!$dryRun) { $this->db->insert('private_automation_runs',$result); }
        return $result;
    }

    private function exemption(?array $employee,string $date): ?string
    {
        if ($employee===null || (int)$employee['is_service']===1 || !(bool)$employee['active']) { return 'Сотрудник неактивен.'; }
        if ((bool)$employee['is_guard']) { return 'Охранник исключён из автоматизации.'; }
        if ($date<$employee['started_on'] || ($employee['ended_on']!==null && $date>$employee['ended_on'])) { return 'Дата вне периода работы сотрудника.'; }
        $history=$this->settings->get('history_start',null);
        if (is_string($history) && $history!=='' && $date<$history) { return 'Дата раньше начала учёта.'; }
        $profile=$this->db->one('SELECT active FROM employee_profile_versions WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC,id DESC LIMIT 1',[(int)$employee['id'],$date,$date]);
        if ($profile!==null && !(bool)$profile['active']) { return 'Дата вне периода работы сотрудника.'; }
        if ($this->db->one('SELECT id FROM employee_leaves WHERE employee_id=? AND active=1 AND start_date<=? AND end_date>=?',[(int)$employee['id'],$date,$date])!==null) { return 'На этот день оформлен отпуск.'; }
        return null;
    }

    private function find(int $id): array
    {
        $row=$this->db->one('SELECT r.*,e.pass_code,e.surname,e.given_name,e.patronymic,e.active AS employee_active FROM private_automation_rules r JOIN employees e ON e.id=r.employee_id WHERE r.id=?',[$id]);
        if ($row===null) { throw new DomainException('Правило не найдено.',404); }
        return $this->decorate($row);
    }
    private function decorate(array $row): array { $row['random_minutes']=(bool)($row['random_minutes']??false);$row['weekdays']=json_decode((string)$row['weekdays'],true,32,JSON_THROW_ON_ERROR);$row['enabled']=(bool)$row['enabled'];$row['working_days_only']=(bool)$row['working_days_only'];$row['trigger_day_offset']=(int)$row['trigger_day_offset'];$row['employee_name']=trim($row['surname'].' '.$row['given_name'].' '.$row['patronymic']);$row['display_id']=(string)$row['pass_code'];$row['trigger_time']=substr((string)$row['trigger_time'],0,5);$row['mark_time']=substr((string)$row['mark_time'],0,5);return $row; }
    private function checkVersion(array $row,array $data): void { if (!isset($data['version']) || !is_scalar($data['version']) || !preg_match('/^\d+$/D',(string)$data['version']) || (int)$data['version']!==(int)$row['version']) { throw new DomainException('Правило изменилось. Обновите экран.',409); } }
    private function update(string $table,array $fields,string $where,array $params): void { $this->db->exec('UPDATE '.$table.' SET '.implode(',',array_map(static fn(string $key):string=>$key.'=?',array_keys($fields))).' WHERE '.$where,[...array_values($fields),...$params]); }
    private function audit(string $action,int $employeeId,?array $before,array $after): void { $this->db->insert('private_service_events',['action'=>$action,'employee_id'=>$employeeId,'work_date'=>$this->today(),'before_json'=>$this->json($before),'after_json'=>$this->json($after),'reason'=>'','created_at'=>$this->stamp()]); }
    private function now(): DateTimeImmutable { return ($this->clock)(); }
    private function today(): string { return $this->now()->setTimezone(new DateTimeZone(ScheduleService::TIMEZONE))->format('Y-m-d'); }
    private function stamp(?DateTimeImmutable $time=null): string { return ($time??$this->now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    private function lock(): string { return $this->db->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform?' FOR UPDATE':''; }
    private function json(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
}

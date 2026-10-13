<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EmployeeService
{
    public function __construct(private Database $database, private Settings $settings) {}

    public function list(array $filters = []): array
    {
        $where = ['e.is_service = 0']; $params = [];
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(CONCAT_WS(' ',e.surname,e.given_name,e.patronymic) LIKE ? OR e.position LIKE ? OR e.pass_code LIKE ?)";
            array_push($params, '%'.$q.'%', '%'.$q.'%', '%'.strtoupper($q).'%');
        }
        $active = $filters['active'] ?? $filters['filter'] ?? 'all';
        if (in_array((string)$active, ['1','active','0','inactive'], true)) {
            $where[] = 'e.active = ?'; $params[] = in_array((string)$active, ['1','active'], true) ? 1 : 0;
        }
        if (!empty($filters['group_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM schedule_assignments sa WHERE sa.employee_id=e.id AND sa.group_id=? AND sa.valid_from<=? AND (sa.valid_to IS NULL OR sa.valid_to>=?))';
            array_push($params, self::id($filters['group_id']), self::today(), self::today());
        }
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : null;
        if ($limit !== null && ($limit < 1 || $limit > 1000)) { throw new \InvalidArgumentException('Размер страницы: 1–1000'); }
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $sql = 'SELECT e.id,e.pass_code,e.surname,e.given_name,e.patronymic,e.legacy_full_name,e.position,e.active,e.admin_role,e.auth_version,e.started_on,e.ended_on,e.version, '.
            '(SELECT sa.group_id FROM schedule_assignments sa WHERE sa.employee_id=e.id AND sa.valid_from<=? AND (sa.valid_to IS NULL OR sa.valid_to>=?) ORDER BY sa.valid_from DESC LIMIT 1) group_id, '.
            '(SELECT sg.name FROM schedule_assignments sa JOIN schedule_groups sg ON sg.id=sa.group_id WHERE sa.employee_id=e.id AND sa.valid_from<=? AND (sa.valid_to IS NULL OR sa.valid_to>=?) ORDER BY sa.valid_from DESC LIMIT 1) group_name '.
            'FROM employees e WHERE '.implode(' AND ', $where).' ORDER BY e.id'.($limit === null ? '' : ' LIMIT '.$limit.' OFFSET '.$offset);
        return array_map($this->decorate(...), $this->database->all($sql, [self::today(),self::today(),self::today(),self::today(),...$params]));
    }

    public function count(array $filters=[]): int
    {
        unset($filters['limit'],$filters['offset']);
        return count($this->list($filters));
    }

    public function find(int $id, bool $includeService = false): ?array
    {
        $row = $this->database->one('SELECT id,pass_code,surname,given_name,patronymic,legacy_full_name,position,active,is_service,admin_role,auth_version,started_on,ended_on,version FROM employees WHERE id=?'.($includeService ? '' : ' AND is_service=0'), [$id]);
        return $row ? $this->decorate($row) : null;
    }

    public function save(array $data, int $actorId): array
    {
        return $this->database->transaction(fn() => $this->persist($data, $actorId, false));
    }

    /** Called by ImportService inside its complete import transaction. */
    public function applyImportRow(array $data, int $actorId): array
    {
        if (!$this->database->db->isTransactionActive()) { throw new \LogicException('Import requires an active transaction'); }
        return $this->persist($data, $actorId, true);
    }

    public function deactivate(array $data, int $actorId): array
    {
        $id = self::id($data['id'] ?? $data['employee_id'] ?? '');
        $current = $this->find($id) ?? throw new NotFoundHttpException('Сотрудник не найден');
        $current['version'] = $data['version'] ?? $current['version'];
        $current['active'] = array_key_exists('active', $data) ? self::bool($data['active']) : 1 - (int)$current['active'];
        $current['ended_on'] = $current['active'] ? null : ($data['ended_on'] ?? self::today());
        return $this->save($current, $actorId);
    }

    public function rotatePassCode(array $data,int $actorId): array
    {
        $id=self::id($data['id']??$data['employee_id']??'');
        return $this->database->transaction(function()use($data,$id,$actorId):array {
            $this->database->one('SELECT next_id FROM employee_id_sequence WHERE id=1 FOR UPDATE') ?? throw new \RuntimeException('Не настроен счётчик сотрудников');
            $before=$this->database->one('SELECT * FROM employees WHERE id=? FOR UPDATE',[$id]) ?? throw new NotFoundHttpException('Сотрудник не найден');
            if ((int)$before['is_service']===1 || (int)$before['active']!==1) { throw new \InvalidArgumentException('Перевыпуск доступен только действующему сотруднику'); }
            if (!isset($data['version']) || (int)$data['version']!==(int)$before['version']) { throw new ConflictHttpException('Карточка изменилась. Обновите список перед перевыпуском.'); }
            $wallet=$this->database->one('SELECT state FROM wallet_passes WHERE employee_id=? FOR UPDATE',[$id]);
            if ($wallet && in_array($wallet['state'],['ready','issuing','ambiguous','revoking','revoke_unknown'],true)) { throw new ConflictHttpException('Сначала отзовите прежний Wallet и дождитесь подтверждения отзыва. Затем перевыпустите код пропуска.'); }
            $oldCode=IdentifierService::codeOf($before); $newCode=$this->newCode();
            $now=self::utc();
            $this->database->db->update('employees',['pass_code'=>$newCode,'version'=>(int)$before['version']+1,'auth_version'=>(int)$before['auth_version']+1,'updated_at'=>$now],['id'=>$id]);
            // NFC credentials are independent. All QR aliases for this person expire on explicit reissue.
            $this->database->exec("UPDATE employee_credentials SET active=0,revoked_at=? WHERE employee_id=? AND kind IN ('LEGACY_NUMERIC_QR','OPAQUE_QR') AND active=1",[$now,$id]);
            $kind=ctype_digit($oldCode)?'LEGACY_NUMERIC_QR':'OPAQUE_QR';
            $value=$kind==='LEGACY_NUMERIC_QR'?(string)(int)$oldCode:$oldCode;
            $historic=$this->database->one('SELECT id,employee_id FROM employee_credentials WHERE kind=? AND value=? FOR UPDATE',[$kind,$value]);
            if ($historic && (int)$historic['employee_id']!==$id) { throw new ConflictHttpException('Прежний код связан с другой карточкой. Проверьте привязки перед перевыпуском.'); }
            if (!$historic) { $this->database->insert('employee_credentials',['employee_id'=>$id,'kind'=>$kind,'value'=>$value,'active'=>0,'revoked_at'=>$now,'created_at'=>$now]); }
            $after=$this->find($id) ?? throw new \RuntimeException('Ошибка перевыпуска пропуска');
            $this->audit($actorId,'employee.passcode.rotate','employee',$id,['pass_code'=>$oldCode,'version'=>$before['version']],['pass_code'=>$newCode,'version'=>$after['version'],'qr_aliases_revoked'=>true,'nfc_unchanged'=>true]);
            return $after;
        });
    }

    private function persist(array $data, int $actorId, bool $import): array
    {
        // Serialize number allocation and administrative role transitions consistently.
        $sequence = $this->database->one('SELECT next_id FROM employee_id_sequence WHERE id=1 FOR UPDATE')
            ?? throw new \RuntimeException('Не настроен счётчик сотрудников');
        $requested = trim((string)($data['id'] ?? ''));
        $id = $requested === '' ? 0 : self::id($requested);
        $before = $id ? $this->database->one('SELECT * FROM employees WHERE id=? FOR UPDATE', [$id]) : null;
        if ($before && (int)$before['is_service'] === 1) { throw new \InvalidArgumentException('Служебная запись не редактируется кадровой формой'); }
        if (!$before && $id && !$import) { throw new NotFoundHttpException('Сотрудник не найден'); }
        if ($before && (!isset($data['version']) || (int)$data['version'] !== (int)$before['version'])) { throw new ConflictHttpException('Карточка изменилась. Обновите список и повторите сохранение.'); }
        if (isset($data['pass_code']) && trim((string)$data['pass_code'])!=='' && (!$before || strtoupper(trim((string)$data['pass_code']))!==IdentifierService::codeOf($before))) { throw new \InvalidArgumentException('Код назначается автоматически. Для смены используйте перевыпуск пропуска.'); }
        $get = fn(string $key, mixed $default = '') => $data[$key] ?? $before[$key] ?? $default;
        $values = [];
        foreach (['surname','given_name','patronymic','position'] as $key) {
            $values[$key] = trim((string)$get($key));
            if (mb_strlen($values[$key]) > ($key==='position' ? 255 : 160) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $values[$key])) { throw new \InvalidArgumentException('Некорректное или слишком длинное поле '.$key); }
        }
        if ($values['surname'] === '' || $values['given_name'] === '') { throw new \InvalidArgumentException('Укажите фамилию и имя'); }
        // Keep the imported source spelling independently from the editable structured name.
        $legacy=$import && array_key_exists('legacy_full_name',$data) ? $data['legacy_full_name'] : ($before['legacy_full_name']??null);
        if ($legacy!==null && (!is_string($legacy) || mb_strlen($legacy)>255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$legacy))) { throw new \InvalidArgumentException('Некорректная исходная строка ФИО'); }
        $values['legacy_full_name']=$legacy;
        if ($before) { $values['pass_code']=IdentifierService::codeOf($before); }
        $values['active'] = self::bool($get('active', 1));
        $values['admin_role'] = strtoupper((string)$get('admin_role', 'NONE'));
        if (!in_array($values['admin_role'], ['NONE','MODERATOR','ADMIN'], true)) { throw new \InvalidArgumentException('Некорректная роль'); }
        $values['started_on'] = self::date($get('started_on', self::today()), true) ?? ($before['started_on']??self::today());
        $values['ended_on'] = self::date($get('ended_on', null), true);
        if (!$values['active'] && $values['ended_on'] === null) { $values['ended_on'] = self::today(); }
        if ($values['active']) { $values['ended_on'] = null; }
        if ($values['started_on'] && $values['ended_on'] && $values['ended_on'] < $values['started_on']) { throw new \InvalidArgumentException('Дата увольнения раньше начала работы'); }
        $password = (string)($data['password'] ?? ''); $pin = (string)($data['enrollment_pin'] ?? '');
        $values['password_hash'] = $before['password_hash'] ?? null;
        $values['enrollment_pin_hash'] = $before['enrollment_pin_hash'] ?? null;
        if ($password !== '') {
            if (mb_strlen($password) < 8 || strlen($password) > 256) { throw new \InvalidArgumentException('Пароль должен содержать от 8 символов'); }
            $values['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($pin !== '') {
            if (!preg_match('/^\d{4,12}$/D', $pin)) { throw new \InvalidArgumentException('PIN выдачи: от 4 до 12 цифр'); }
            $values['enrollment_pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
        }
        if (!empty($data['clear_enrollment_pin'])) { $values['enrollment_pin_hash'] = null; }
        if ($values['admin_role'] === 'NONE') { $values['password_hash'] = null; $values['enrollment_pin_hash'] = null; }
        elseif (!$values['password_hash']) { throw new \InvalidArgumentException('Для административной роли задайте пароль'); }
        $authChanged = !$before || (int)$before['active'] !== $values['active'] || $before['admin_role'] !== $values['admin_role'] || $before['password_hash'] !== $values['password_hash'] || $before['enrollment_pin_hash'] !== $values['enrollment_pin_hash'];
        if ($before && $before['admin_role'] === 'ADMIN' && (int)$before['active'] === 1 && (!$values['active'] || $values['admin_role'] !== 'ADMIN')) {
            $count = (int)$this->database->scalar("SELECT COUNT(*) FROM employees WHERE active=1 AND admin_role='ADMIN'");
            if ($count <= 1) { throw new \InvalidArgumentException('Нельзя отключить последнего активного администратора'); }
        }
        $values['auth_version'] = (int)($before['auth_version'] ?? 0) + ($authChanged ? 1 : 0);
        $values['version'] = (int)($before['version'] ?? 0) + 1;
        $values['updated_at'] = self::utc();
        if ($before) {
            $changed = false;
            foreach ($values as $key => $value) { if (!in_array($key, ['version','updated_at'], true) && (string)($before[$key] ?? '') !== (string)($value ?? '')) { $changed = true; } }
            if (!$changed) { return $this->decorate($this->safe($before)); }
            $this->database->db->update('employees', $values, ['id'=>$id]);
        } else {
            $id = $id ?: max(1, (int)$sequence['next_id']);
            if ($id > 4294967295) { throw new \InvalidArgumentException('Исчерпан диапазон номеров'); }
            $this->database->exec('UPDATE employee_id_sequence SET next_id=? WHERE id=1', [max((int)$sequence['next_id'], $id+1)]);
            $values['pass_code']=$import && $requested!=='' ? IdentifierService::numericLegacy($id) : $this->newCode();
            $values += ['id'=>$id,'is_service'=>0,'created_at'=>self::utc()];
            $this->database->insert('employees', $values);
        }
        $profile = array_intersect_key($values, array_flip(['surname','given_name','patronymic','position','active']));
        $profileChanged = !$before;
        foreach ($profile as $key=>$value) { if ((string)($before[$key] ?? '') !== (string)$value) { $profileChanged = true; } }
        $inactiveDateChanged=$before && $values['active']===0 && ($before['ended_on']??null)!==$values['ended_on'];
        $profileChanged=$profileChanged || $inactiveDateChanged;
        if ($profileChanged) {
            $effective = self::date($data['effective_from'] ?? ($before ? self::today() : ($values['started_on'] ?? self::today())));
            if ($effective > self::today()) { throw new \InvalidArgumentException('Будущую кадровую смену нельзя применять сейчас'); }
            $identity=array_intersect_key($profile,array_flip(['surname','given_name','patronymic','position']));
            $identityChanged=!$before;
            foreach ($identity as $key=>$value) { if ((string)($before[$key]??'') !== (string)$value) { $identityChanged=true; } }
            $becameInactive=$before && $values['active']===0 && ((int)$before['active']===1 || $inactiveDateChanged);
            $becameActive=$before && (int)$before['active']===0 && $values['active']===1;
            if ($becameInactive) {
                // ended_on is the LAST working day. Login/cards are disabled immediately;
                // the historical attendance plan becomes inactive starting with the next day.
                $inactiveFrom=(new \DateTimeImmutable($values['ended_on']))->modify('+1 day')->format('Y-m-d');
                if ($identityChanged) { $this->recordProfile($id,$identity+['active'=>$this->profileActive($id,$effective,1)],$effective,$actorId); }
                if ($inactiveDateChanged && (int)$before['active']===0 && $before['ended_on']!==null) {
                    $oldInactiveFrom=(new \DateTimeImmutable($before['ended_on']))->modify('+1 day')->format('Y-m-d');
                    if ($oldInactiveFrom<$inactiveFrom) {
                        $oldProfile=$this->database->one('SELECT surname,given_name,patronymic,position FROM employee_profile_versions WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',[$id,$oldInactiveFrom,$oldInactiveFrom]);
                        $this->recordProfile($id,($oldProfile??$identity)+['active'=>1],$oldInactiveFrom,$actorId);
                        $this->database->exec('UPDATE employee_profile_versions SET active=1 WHERE employee_id=? AND valid_from>=? AND valid_from<?',[$id,$oldInactiveFrom,$inactiveFrom]);
                    }
                }
                $dated=$this->database->one('SELECT surname,given_name,patronymic,position FROM employee_profile_versions WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',[$id,$inactiveFrom,$inactiveFrom]);
                $this->recordProfile($id,($dated??$identity)+['active'=>0],$inactiveFrom,$actorId);
                $this->database->exec('UPDATE employee_profile_versions SET active=0 WHERE employee_id=? AND valid_from>?',[$id,$inactiveFrom]);
            } else {
                $datedActive=(!$before || $becameActive) ? $values['active'] : $this->profileActive($id,$effective,$values['active']);
                $this->recordProfile($id,$identity+['active'=>$datedActive],$effective,$actorId);
                if ($becameActive) { $this->database->exec('UPDATE employee_profile_versions SET active=1 WHERE employee_id=? AND valid_from>?',[$id,$effective]); }
            }
            // Pending next-day retirement versions inherit today's spelling, retaining their activity boundary.
            if ($identityChanged) { $this->database->db->executeStatement('UPDATE employee_profile_versions SET surname=?,given_name=?,patronymic=?,position=? WHERE employee_id=? AND valid_from>?',[...array_values($identity),$id,self::today()]); }
        }
        if ($before) {
            $boundaries=[];
            if ((string)$before['started_on'] !== (string)$values['started_on']) { $boundaries[]=$before['started_on']; $boundaries[]=$values['started_on']; }
            if (($before['ended_on']??null) !== $values['ended_on']) {
                foreach ([$before['ended_on']??null,$values['ended_on']] as $end) { if ($end!==null) { $boundaries[]=(new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'); } }
            }
            if ((int)$before['active'] !== $values['active']) { $boundaries[]=self::date($data['effective_from']??self::today()); }
            if ($boundaries) {
                // Recompute dated employment expectations without changing any recorded facts or corrections.
                $from=min($boundaries);
                $this->database->exec('DELETE FROM schedule_plan_cache WHERE employee_id=? AND work_date>=?',[$id,$from]);
                $this->database->exec('UPDATE attendance_days SET plan_json=NULL WHERE employee_id=? AND work_date>=?',[$id,$from]);
            }
        }
        $after = $this->find($id) ?? throw new \RuntimeException('Ошибка сохранения сотрудника');
        $this->audit($actorId, $before ? 'employee.update' : 'employee.create', 'employee', $id, $before, $after+['password_changed'=>$password !== '', 'enrollment_pin_changed'=>$pin !== '']);
        return $after;
    }

    private function profileActive(int $id,string $date,int $fallback): int
    {
        $row=$this->database->one('SELECT active FROM employee_profile_versions WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',[$id,$date,$date]);
        return $row ? (int)$row['active'] : $fallback;
    }

    /** Insert a dated boundary while retaining already recorded earlier/later profile intervals. */
    private function recordProfile(int $id,array $profile,string $date,int $actorId): void
    {
        $current=$this->database->one('SELECT * FROM employee_profile_versions WHERE employee_id=? AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY valid_from DESC LIMIT 1 FOR UPDATE',[$id,$date,$date]);
        if ($current && $current['valid_from']===$date) { $this->database->db->update('employee_profile_versions',$profile+['actor_employee_id'=>$actorId],['id'=>$current['id']]); return; }
        $next=$this->database->one('SELECT valid_from FROM employee_profile_versions WHERE employee_id=? AND valid_from>? ORDER BY valid_from LIMIT 1 FOR UPDATE',[$id,$date]);
        if ($current) { $this->database->exec('UPDATE employee_profile_versions SET valid_to=? WHERE id=?',[(new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d'),$current['id']]); }
        $to=$next ? (new \DateTimeImmutable($next['valid_from']))->modify('-1 day')->format('Y-m-d') : null;
        $this->database->insert('employee_profile_versions',$profile+['employee_id'=>$id,'valid_from'=>$date,'valid_to'=>$to,'actor_employee_id'=>$actorId,'created_at'=>self::utc()]);
    }

    public function credentials(array $filters = []): array
    {
        $where = ['e.is_service=0']; $params=[];
        if (!empty($filters['employee_id'])) { $where[]='c.employee_id=?'; $params[]=self::id($filters['employee_id']); }
        return array_map(static function(array $row):array { $row['display_id']=IdentifierService::codeOf(['id'=>$row['employee_id'],'pass_code'=>$row['pass_code']]); return $row; },$this->database->all("SELECT c.*,e.pass_code,CONCAT_WS(' ',e.surname,e.given_name,e.patronymic) employee_name,e.active employee_active FROM employee_credentials c JOIN employees e ON e.id=c.employee_id WHERE ".implode(' AND ',$where).' ORDER BY c.active DESC,c.id DESC', $params));
    }

    public function bindCredential(array $data, int $actorId): array
    {
        $employeeId = self::id($data['employee_id'] ?? '');
        $kind = strtoupper((string)($data['kind'] ?? 'NFC_UID'));
        if ($kind === 'NFC') { $kind = 'NFC_UID'; }
        if (!in_array($kind, ['NFC_UID','LEGACY_NUMERIC_QR','OPAQUE_QR'], true)) { throw new \InvalidArgumentException('Неизвестный тип носителя'); }
        $value = trim((string)($data['value'] ?? $data['uid'] ?? ''));
        if ($kind === 'NFC_UID') { $value = self::nfc($value); }
        elseif ($kind === 'LEGACY_NUMERIC_QR') { $value = (string)self::id($value); if ((int)$value !== $employeeId) { throw new \InvalidArgumentException('Числовой QR должен сохранять прежний ID сотрудника'); } }
        elseif (!preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $value)) { throw new \InvalidArgumentException('Некорректный токен'); }
        return $this->database->transaction(function() use ($employeeId,$kind,$value,$actorId,$data) {
            $employee = $this->database->one('SELECT id,pass_code,active,is_service FROM employees WHERE id=? FOR UPDATE', [$employeeId]);
            if (!$employee || !$employee['active'] || $employee['is_service']) { throw new \InvalidArgumentException('Нужен действующий сотрудник'); }
            if ($kind==='LEGACY_NUMERIC_QR' && (!ctype_digit(IdentifierService::codeOf($employee)) || IdentifierService::codeOf($employee)!==IdentifierService::numericLegacy((int)$value))) { throw new \InvalidArgumentException('Прежний числовой код отключён. Используйте действующий код пропуска.'); }
            if ($kind==='OPAQUE_QR' && str_starts_with($value,'VVE-')) { throw new \InvalidArgumentException('Коды VVE назначаются только созданием или перевыпуском пропуска'); }
            $before = $this->database->one('SELECT * FROM employee_credentials WHERE kind=? AND value=? FOR UPDATE', [$kind,$value]);
            if ($before && (int)$before['active'] === 1 && (int)$before['employee_id'] !== $employeeId) { throw new ConflictHttpException('Карта уже назначена другому сотруднику. Сначала отзовите её.'); }
            if ($before && (int)$before['active'] === 1 && (int)$before['employee_id'] === $employeeId) { return $before+['duplicate'=>true]; }
            $after = ['employee_id'=>$employeeId,'kind'=>$kind,'value'=>$value,'active'=>1,'revoked_at'=>null];
            if ($before) { $this->database->db->update('employee_credentials', $after, ['id'=>$before['id']]); $id=(int)$before['id']; }
            else { $this->database->insert('employee_credentials', $after+['created_at'=>self::utc()]); $id=(int)$this->database->db->lastInsertId(); }
            $after['id']=$id;
            $this->audit($actorId, 'credential.bind', 'credential', $id, $before, $after);
            return $after;
        });
    }

    public function revokeCredential(int $id, int $actorId): array
    {
        return $this->database->transaction(function() use ($id,$actorId) {
            $before = $this->database->one('SELECT * FROM employee_credentials WHERE id=? FOR UPDATE', [$id]) ?? throw new NotFoundHttpException('Карта не найдена');
            if (!(int)$before['active']) { return $before+['duplicate'=>true]; }
            $after = ['active'=>0,'revoked_at'=>self::utc()];
            $this->database->db->update('employee_credentials', $after, ['id'=>$id]);
            $this->audit($actorId, 'credential.revoke', 'credential', $id, $before, $after+$before);
            return $after+$before;
        });
    }

    public function audit(int $actorId, string $action, string $entity, int|string|null $id, ?array $before, ?array $after): void
    {
        $this->database->insert('audit_log', ['actor_employee_id'=>$actorId,'action'=>$action,'entity_type'=>$entity,'entity_id'=>$id === null ? '' : (string)$id,'before_json'=>$before === null ? null : json_encode($this->safe($before), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'after_json'=>$after === null ? null : json_encode($this->safe($after), JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'created_at'=>self::utc(),'private'=>0]);
    }

    private function safe(array $row): array { unset($row['password_hash'],$row['enrollment_pin_hash'],$row['password'],$row['enrollment_pin']); return $row; }
    private function decorate(array $row): array { $row=$this->safe($row); $row['pass_code']=$row['display_id']=IdentifierService::codeOf($row); $row['name']=$row['full_name']=trim(implode(' ',array_filter([$row['surname']??'',$row['given_name']??'',$row['patronymic']??''], static fn($v)=>$v!=='' && $v!==null))); return $row; }
    private function newCode(): string { for($attempt=0;$attempt<20;$attempt++) { $code=IdentifierService::randomCode(); if ((int)$this->database->scalar('SELECT COUNT(*) FROM employees WHERE pass_code=?',[$code])===0 && (int)$this->database->scalar('SELECT COUNT(*) FROM employee_credentials WHERE value=?',[$code])===0) { return $code; } } throw new \RuntimeException('Не удалось выдать уникальный код пропуска'); }
    public static function utc(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'); }
    public static function today(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('Asia/Sakhalin')))->format('Y-m-d'); }
    public static function id(mixed $id): int { if (!is_scalar($id) || !preg_match('/^\d{1,10}$/D',(string)$id) || (int)$id<1 || (int)$id>4294967295) { throw new \InvalidArgumentException('Некорректный ID'); } return (int)$id; }
    public static function bool(mixed $value): int { return in_array($value,[true,1,'1','true','on','yes'],true) ? 1 : 0; }
    public static function date(mixed $value, bool $nullable=false): ?string { if (($value===null || $value==='') && $nullable) { return null; } $text=(string)$value; $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$text); if (!$d || $d->format('Y-m-d')!==$text) { throw new \InvalidArgumentException('Некорректная дата'); } return $text; }
    public static function nfc(string $value): string { $value=preg_replace('/^NFC:/i','',trim($value)); $value=strtoupper(str_replace([' ',':','-'], '', $value)); if (!preg_match('/^[0-9A-F]{8,64}$/D',$value) || strlen($value)%2!==0) { throw new \InvalidArgumentException('UID должен быть полной hex-строкой байтов'); } return $value; }
}

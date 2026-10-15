<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ImportService
{
    private const MAX_ROWS = 1000;
    public function __construct(private Database $database, private EmployeeService $employees, private IdentifierService $identifiers, private ScheduleService $schedules) {}

    public function preview(UploadedFile $file, string $mode, int $actorId): array
    {
        if (!$file->isValid() || $file->getSize() > 2*1024*1024) { throw new \InvalidArgumentException('Нужен корректный файл не больше 2 МиБ'); }
        if (!in_array($mode, ['add_only','merge'], true)) { throw new \InvalidArgumentException('Некорректный режим импорта'); }
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['csv','xlsx','xls'], true)) { throw new \InvalidArgumentException('Поддерживаются CSV, XLSX и XLS'); }
        $matrix = $extension === 'csv' ? $this->csv($file->getPathname()) : $this->spreadsheet($file->getPathname(),$extension);
        if (count($matrix)<2) { throw new \InvalidArgumentException('Нет строк сотрудников'); }
        $headers = array_shift($matrix); $map=[];
        $aliases = [
            'id'=>['id','номер','код','табельныйномер'], 'pass_code'=>['passcode','кодпропуска','кодкарточки'], 'full_name'=>['фио','fullname','name'],
            'surname'=>['фамилия','surname','lastname'], 'given_name'=>['имя','givenname','firstname'],
            'patronymic'=>['отчество','patronymic','middlename'], 'position'=>['должность','position','роль','jobtitle'],
            'active'=>['активен','работает','active','enabled','status','статус'], 'dismissed'=>['уволен','dismissed'],
        ];
        foreach ($headers as $i=>$header) {
            $normalized=preg_replace('/[^a-zа-я0-9]/u','',mb_strtolower(str_replace(['ё',"\xEF\xBB\xBF"],['е',''],trim((string)$header))));
            foreach ($aliases as $key=>$names) { if (in_array($normalized,$names,true)) { if (isset($map[$key])) { throw new \InvalidArgumentException('Повторная колонка '.(['id'=>'ID','pass_code'=>'Код пропуска','full_name'=>'ФИО','surname'=>'Фамилия','given_name'=>'Имя','patronymic'=>'Отчество','position'=>'Должность','active'=>'Статус','dismissed'=>'Уволен'][$key] ?? 'Сотрудник')); } $map[$key]=$i; } }
        }
        if (!isset($map['full_name']) && (!isset($map['surname']) || !isset($map['given_name']))) { throw new \InvalidArgumentException('Укажите ФИО либо отдельные колонки Фамилия и Имя'); }
        $rows=[]; $seen=[]; $summary=['create'=>0,'update'=>0,'skip'=>0,'errors'=>0,'total'=>0];
        foreach ($matrix as $i=>$cells) {
            if (count(array_filter($cells,static fn($v)=>$v!==null && trim((string)$v)!==''))===0) { continue; }
            $row=['row'=>$i+2,'action'=>'error','data'=>[],'before'=>null,'diff'=>[],'errors'=>[]]; $full='';
            try {
                $get=fn(string $key,string $default='') => trim((string)($cells[$map[$key]??-1]??$default));
                $rawPassCode=strtoupper($get('pass_code')); $rawColumnId=strtoupper($get('id'));
                $rawId=$rawPassCode!==''?$rawPassCode:$rawColumnId; $id=null; $before=null;
                if ($rawId!=='') {
                    if (preg_match('/^\d{1,10}$/D',$rawId)) {
                        $id=EmployeeService::id($rawId); $before=$this->employees->find($id,true);
                        if ($before && IdentifierService::codeOf($before)!==IdentifierService::numericLegacy($id)) { throw new \InvalidArgumentException('Прежний числовой код отключён; укажите действующий код пропуска'); }
                    } else {
                        try { $resolved=$this->identifiers->resolveCode($rawId,false); }
                        catch (\DomainException|\InvalidArgumentException) { throw new \InvalidArgumentException('Код не найден. Для нового сотрудника оставьте ID пустым'); }
                        $id=(int)$resolved['id']; $before=$this->employees->find($id,true);
                    }
                }
                if ($rawPassCode!=='' && $rawColumnId!=='' && $rawPassCode!==$rawColumnId && !(preg_match('/^\d{1,10}$/D',$rawColumnId) && (int)$rawColumnId===$id)) { throw new \InvalidArgumentException('Колонки ID и код пропуска относятся к разным значениям'); }
                if ($id && isset($seen[$id])) { throw new \InvalidArgumentException('ID повторяется в файле'); } if ($id) { $seen[$id]=true; }
                $full=$get('full_name'); $parts=preg_split('/\s+/u',$full, -1,PREG_SPLIT_NO_EMPTY) ?: [];
                if (!isset($map['surname']) && count($parts)>3) { throw new \InvalidArgumentException('Составное ФИО: укажите отдельные фамилию/имя/отчество, исходная строка сохранена в предпросмотре'); }
                $active=isset($map['active']) ? $this->active($get('active')) : (isset($map['dismissed']) ? $this->dismissed($get('dismissed')) : 1);
                $data=['id'=>$id,'surname'=>$get('surname',$parts[0]??''),'given_name'=>$get('given_name',$parts[1]??''),'patronymic'=>$get('patronymic',implode(' ',array_slice($parts,2))),'position'=>$get('position'),'active'=>$active];
                if (isset($map['full_name'])) { $data['legacy_full_name']=(string)($cells[$map['full_name']]??''); if (mb_strlen($data['legacy_full_name'])>255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$data['legacy_full_name'])) { throw new \InvalidArgumentException('Некорректное исходное ФИО или длина больше 255 символов'); } }
                if ($data['surname']==='' || $data['given_name']==='') { throw new \InvalidArgumentException('Нет фамилии или имени'); }
                foreach (['surname','given_name','patronymic','position'] as $key) {
                    if (mb_strlen($data[$key])>($key==='position' ? 255 : 160) || str_starts_with($data[$key],'=') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u',$data[$key])) { throw new \InvalidArgumentException('Некорректное поле, слишком длинный текст или формула'); }
                }
                if ($before && (int)$before['is_service']===1) { throw new \InvalidArgumentException('Этот ID занят служебной записью; выберите другой номер'); }
                $row['data']=$data; $row['before']=$before; $row['display_id']=$before ? IdentifierService::codeOf($before) : ($id ? IdentifierService::numericLegacy($id) : 'Назначится автоматически');
                $row['action']=!$before ? 'create' : ($mode==='add_only' ? 'skip' : 'update');
                if ($before) {
                    $row['data']['version']=(int)$before['version'];
                    foreach ($data as $key=>$value) { if ($key!=='id' && (string)$value!==(string)($before[$key]??'')) { $row['diff'][$key]=['before'=>$before[$key]??null,'after'=>$value]; } }
                    if (!$row['diff']) { $row['action']='skip'; }
                }
            } catch (\InvalidArgumentException $e) { $row['errors'][]=$e->getMessage(); $row['raw_name']=$full??''; }
            ++$summary[$row['action']==='error' ? 'errors' : $row['action']]; ++$summary['total']; $rows[]=$row;
        }
        if (!$rows) { throw new \InvalidArgumentException('Нет сотрудников для импорта'); }
        $token=bin2hex(random_bytes(32));
        $preview=['token'=>$token,'file_hash'=>hash_file('sha256',$file->getPathname()),'file_name'=>basename($file->getClientOriginalName()),'mode'=>$mode,'rows'=>$rows,'summary'=>$summary,'actor_id'=>$actorId,'expires_at'=>time()+1800];
        $dir=$this->directory();
        if (file_put_contents($dir.'/'.$token.'.json',json_encode($preview,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) { throw new \RuntimeException('Не удалось сохранить предпросмотр'); }
        return $this->publicPreview($preview);
    }

    public function commit(string $token, int $actorId): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/D',$token)) { throw new \InvalidArgumentException('Некорректный предпросмотр'); }
        $path=$this->directory().'/'.$token.'.json'; $handle=@fopen($path,'r');
        if (!$handle) { throw new \InvalidArgumentException('Предпросмотр отсутствует или уже применён'); }
        try {
            if (!flock($handle,LOCK_EX)) { throw new \RuntimeException('Не удалось заблокировать импорт'); }
            $preview=json_decode(stream_get_contents($handle),true,512,JSON_THROW_ON_ERROR);
            if ((int)$preview['actor_id']!==$actorId || (int)$preview['expires_at']<time()) { throw new \InvalidArgumentException('Предпросмотр истёк либо принадлежит другой сессии администратора'); }
            if ($preview['summary']['errors']>0) { throw new \InvalidArgumentException('Исправьте строки с ошибками и загрузите файл заново'); }
            $result=$this->database->transaction(function() use ($preview,$actorId) {
                $sequence=$this->database->one('SELECT next_id FROM employee_id_sequence WHERE id=1 FOR UPDATE') ?? throw new \RuntimeException('Не настроен счётчик сотрудников');
                $existing=$this->database->one('SELECT id,summary_json FROM import_batches WHERE file_hash=? AND mode=? LIMIT 1',[$preview['file_hash'],$preview['mode']]);
                if ($existing) { return ['success'=>true,'duplicate'=>true,'batch_id'=>(int)$existing['id'],'summary'=>json_decode($existing['summary_json'],true,512,JSON_THROW_ON_ERROR)]; }
                // Reserve explicit legacy numbers before any row without a number allocates an ID.
                $next=(int)$sequence['next_id']; foreach ($preview['rows'] as $row) { if ($row['data']['id']!==null) { $next=max($next,(int)$row['data']['id']+1); } }
                if ($next!==(int)$sequence['next_id']) { $this->database->exec('UPDATE employee_id_sequence SET next_id=? WHERE id=1',[$next]); }
                $summary=['created'=>0,'updated'=>0,'skipped'=>0,'employee_ids'=>[],'pass_codes'=>[]];
                foreach ($preview['rows'] as $row) {
                    if ($row['action']==='skip') { ++$summary['skipped']; continue; }
                    $current=$row['data']['id'] ? $this->employees->find((int)$row['data']['id']) : null;
                    if ($row['action']==='create' && $current) { throw new ConflictHttpException('ID '.$row['data']['id'].' занят после предпросмотра. Загрузите файл заново.'); }
                    if ($row['action']==='update' && (!$current || (int)$current['version']!==(int)$row['data']['version'])) { throw new ConflictHttpException('Сотрудник изменился после предпросмотра. Загрузите файл заново.'); }
                    $saved=$this->employees->applyImportRow($row['data'],$actorId);
                    if ($row['action']==='create' && $row['data']['id']===null && (int)$saved['active']===1) {
                        $group=$this->database->one('SELECT id FROM schedule_groups WHERE active=1 ORDER BY CASE WHEN id=1 THEN 0 ELSE 1 END,id LIMIT 1') ?? throw new \InvalidArgumentException('Для новых сотрудников добавьте действующую группу расписания');
                        $this->schedules->saveAssignment(['employee_id'=>$saved['id'],'group_id'=>(int)$group['id'],'valid_from'=>$saved['started_on']??EmployeeService::today()],$actorId);
                    }
                    ++$summary[$row['action']==='create' ? 'created' : 'updated']; $summary['employee_ids'][]=$saved['id']; $summary['pass_codes'][]=$saved['pass_code'];
                }
                $this->database->insert('import_batches',['file_hash'=>$preview['file_hash'],'mode'=>$preview['mode'],'summary_json'=>json_encode($summary,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'actor_employee_id'=>$actorId,'created_at'=>EmployeeService::utc()]);
                $batch=(int)$this->database->db->lastInsertId();
                $this->employees->audit($actorId,'employees.import','import',$batch,null,['file_hash'=>$preview['file_hash'],'mode'=>$preview['mode'],'summary'=>$summary]);
                return ['success'=>true,'duplicate'=>false,'batch_id'=>$batch,'summary'=>$summary];
            });
            // Keep the token until expiry so a lost response can be retried idempotently.
            return $result;
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }

    private function csv(string $path): array
    {
        $handle=fopen($path,'r'); if (!$handle) { throw new \InvalidArgumentException('Не удалось открыть CSV'); }
        try {
            $first=fgets($handle); rewind($handle); $counts=[]; foreach ([";","\t",","] as $delimiter) { $counts[$delimiter]=substr_count((string)$first,$delimiter); } arsort($counts); $delimiter=(string)array_key_first($counts);
            $rows=[]; while (($row=fgetcsv($handle,0,$delimiter,'"',''))!==false) {
                if (count($rows)>self::MAX_ROWS || count($row)>16) { throw new \InvalidArgumentException('Не более 1000 сотрудников и 16 колонок'); }
                $rows[]=array_map(static function($v) { $v=(string)$v; return mb_check_encoding($v,'UTF-8') ? $v : mb_convert_encoding($v,'UTF-8','Windows-1251'); },$row);
            } return $rows;
        } finally { fclose($handle); }
    }

    private function spreadsheet(string $path,string $extension): array
    {
        if ($extension==='xlsx') {
            $zip=new \ZipArchive(); if ($zip->open($path)!==true) { throw new \InvalidArgumentException('Повреждён XLSX'); }
            try { $bytes=0; for($i=0;$i<$zip->numFiles;$i++) { $info=$zip->statIndex($i); $bytes+=(int)($info['size']??0); if ($bytes>16*1024*1024) { throw new \InvalidArgumentException('Распакованная книга слишком велика'); } } } finally { $zip->close(); }
        }
        $reader=IOFactory::createReaderForFile($path,[$extension==='xlsx' ? 'Xlsx' : 'Xls']);
        $info=$reader->listWorksheetInfo($path); $total=0;
        foreach ($info as $sheet) { $total+=(int)$sheet['totalRows']; if ($sheet['totalRows']>self::MAX_ROWS+1 || $sheet['totalColumns']>16 || $total>1500) { throw new \InvalidArgumentException('Не более 1000 строк на лист, 16 колонок и 1500 строк в книге'); } }
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell($columnAddress,$row,$worksheetName=''): bool { return $row<=1001 && Coordinate::columnIndexFromString($columnAddress)<=16; }
        });
        $book=$reader->load($path);
        try {
            $sheet=$book->getActiveSheet(); $maxRow=$sheet->getHighestDataRow(); $maxCol=$sheet->getHighestDataColumn();
            $matrix=$sheet->rangeToArray('A1:'.$maxCol.$maxRow,null,false,false,false);
            foreach ($matrix as $row) { foreach ($row as $value) { if (is_string($value) && str_starts_with($value,'=')) { throw new \InvalidArgumentException('Формулы в справочнике не импортируются; вставьте значения'); } } }
            return $matrix;
        } finally { $book->disconnectWorksheets(); }
    }

    private function active(string $value): int
    {
        $value=mb_strtolower(str_replace('ё','е',trim($value)));
        if (in_array($value,['','1','да','true','yes','on','активен','работает'],true)) { return 1; }
        if (in_array($value,['0','нет','false','no','off','уволен','уволена','не работает','inactive'],true)) { return 0; }
        throw new \InvalidArgumentException('Неизвестное значение активности: '.$value);
    }
    private function dismissed(string $value): int
    {
        $value=mb_strtolower(str_replace('ё','е',trim($value)));
        if (in_array($value,['','работает','активен'],true)) { return 1; }
        if (in_array($value,['уволен','уволена','не работает','inactive'],true)) { return 0; }
        return 1-$this->active($value);
    }
    private function directory(): string { $dir=dirname(__DIR__,2).'/var/import'; if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) { throw new \RuntimeException('Нет каталога предпросмотра'); } return $dir; }
    private function publicPreview(array $preview): array { unset($preview['actor_id']); return $preview; }
}

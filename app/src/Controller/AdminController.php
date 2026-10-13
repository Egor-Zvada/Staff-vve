<?php
declare(strict_types=1);

namespace App\Controller;

use App\Input;
use App\Web;
use App\Infrastructure\Database;
use App\Security\Auth;
use App\Service\AttendanceService;
use App\Service\EmployeeService;
use App\Service\IdentifierService;
use App\Service\ImportService;
use App\Service\ExportService;
use App\Service\ScheduleService;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminController
{
    public function __construct(private Web $web,private Auth $auth,private Database $database,private Settings $settingsService,private EmployeeService $employeeService,private ImportService $importService,private ExportService $exportService,private ScheduleService $scheduleService,private AttendanceService $attendance) {}

    public function index(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR'); $date=EmployeeService::date($request->query->get('date',EmployeeService::today()));
        $dashboard=$this->attendance->dashboard($date);
        $chartDays=min(14,max(1,(int)$this->settingsService->get('report_max_days',366)));
        $from=(new \DateTimeImmutable($date,new \DateTimeZone('Asia/Sakhalin')))->modify('-'.($chartDays-1).' days'); $chart=[];
        for ($i=0;$i<$chartDays;$i++) { $day=$from->modify('+'.$i.' days')->format('Y-m-d'); $chart[$day]=['date'=>$day,'expected'=>0,'arrived'=>0,'late'=>0,'missing'=>0]; }
        foreach ($this->attendance->period($from->format('Y-m-d'),$date) as $row) {
            $day=$row['work_date']??$row['date']??''; if (empty($row['is_working']) || !isset($chart[$day])) { continue; }
            ++$chart[$day]['expected'];
            $flags=array_values(array_filter($row['flags']??[],static fn(array $flag):bool=>empty($flag['suppressed']) && empty($flag['private'])));
            $kinds=array_column($flags,'kind');
            $arrivalChanges=array_filter($row['changes']??[],static fn(array $change):bool=>($change['event_type']??'')==='arrival');
            if (($row['raw_values']['arrival']??null)!==null || (($row['values']['arrival']??null)!==null && !$arrivalChanges && !in_array('MISSING_ARRIVAL',$kinds,true))) { ++$chart[$day]['arrived']; }
            if (array_intersect($kinds,['MISSING_ARRIVAL','MISSING_DEPARTURE','NO_MARKS'])) { ++$chart[$day]['missing']; }
            if (in_array('LATE_ARRIVAL',$kinds,true)) { ++$chart[$day]['late']; }
        }
        return $this->web->render('admin/dashboard.html.twig',$dashboard+['date'=>$date,'summary'=>$dashboard['summary']??$dashboard,'rows'=>$dashboard['rows']??[],'chart'=>array_values($chart)]);
    }

    public function employees(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR'); $filters=$request->query->all()+['active'=>'1'];
        // A complete registry is returned unless the caller explicitly requests a page.
        $pagination=null;
        if ($request->query->has('page')) {
            $page=max(1,(int)$request->query->get('page',1)); $size=max(1,min(1000,(int)$request->query->get('page_size',100)));
            $total=$this->employeeService->count($filters); $filters['limit']=$size; $filters['offset']=($page-1)*$size;
            $base=$request->query->all(); unset($base['limit'],$base['offset']);
            $link=static fn(int $number): string=>'/admin/employees?'.http_build_query(array_replace($base,['page'=>$number,'page_size'=>$size]));
            $pagination=['page'=>$page,'page_size'=>$size,'total'=>$total,'pages'=>(int)ceil($total/$size),'previous'=>$page>1 ? $link($page-1) : null,'next'=>$page*$size<$total ? $link($page+1) : null];
        }
        return $this->web->render('admin/employees.html.twig',['employees'=>$this->employeeService->list($filters),'groups'=>$this->groups(),'filters'=>$filters,'pagination'=>$pagination,'total'=>$pagination['total']??null]);
    }

    public function employeeSave(Request $request): Response
    {
        $actor=$this->write($request); $data=Input::data($request); $creating=trim((string)($data['id']??''))==='';
        $employee=$this->database->transaction(function()use($data,$creating,$actor):array {
            $employee=$this->employeeService->save($data,(int)$actor['id']);
            if ($creating && (int)$employee['active']===1) {
                $requested=trim((string)($data['group_id']??''));
                $group=$requested!=='' ? $this->database->one('SELECT id,name FROM schedule_groups WHERE id=? AND active=1',[EmployeeService::id($requested)]) : $this->database->one('SELECT id,name FROM schedule_groups WHERE active=1 ORDER BY CASE WHEN id=1 THEN 0 ELSE 1 END,id LIMIT 1');
                if (!$group) { throw new \InvalidArgumentException('Выберите действующую группу расписания перед созданием сотрудника'); }
                $this->scheduleService->saveAssignment(['employee_id'=>$employee['id'],'group_id'=>(int)$group['id'],'valid_from'=>$data['effective_from']??$employee['started_on']??EmployeeService::today()],(int)$actor['id']);
                $employee=$this->employeeService->find((int)$employee['id']) ?? throw new \RuntimeException('Не удалось прочитать созданного сотрудника');
                $employee['group_id']=(int)$group['id']; $employee['group_name']=$group['name'];
            }
            return $employee;
        });
        return $this->finish($request,['success'=>true,'employee'=>$employee],'/admin/employees');
    }
    public function employeeDeactivate(Request $request): Response { $actor=$this->write($request); $employee=$this->employeeService->deactivate(Input::data($request),(int)$actor['id']); return $this->finish($request,['success'=>true,'employee'=>$employee],'/admin/employees'); }
    public function employeeRotatePasscode(Request $request): Response { $actor=$this->write($request); $employee=$this->employeeService->rotatePassCode(Input::data($request),(int)$actor['id']); return $this->finish($request,['success'=>true,'employee'=>$employee],'/admin/employees'); }

    public function schedules(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR');
        $assignments=$this->database->all("SELECT sa.*,e.pass_code,CONCAT_WS(' ',e.surname,e.given_name,e.patronymic) employee_name,sg.name group_name FROM schedule_assignments sa JOIN employees e ON e.id=sa.employee_id JOIN schedule_groups sg ON sg.id=sa.group_id WHERE e.is_service=0 ORDER BY sa.valid_from DESC,sa.id DESC");
        foreach($assignments as &$assignment) { $assignment['display_id']=IdentifierService::codeOf(['id'=>$assignment['employee_id'],'pass_code'=>$assignment['pass_code']]); } unset($assignment);
        $exceptions=$this->database->all("SELECT ce.*,e.pass_code,CONCAT_WS(' ',e.surname,e.given_name,e.patronymic) employee_name,sg.name group_name FROM calendar_exceptions ce LEFT JOIN employees e ON e.id=ce.employee_id LEFT JOIN schedule_groups sg ON sg.id=ce.group_id ORDER BY ce.work_date DESC,ce.id DESC");
        foreach($exceptions as &$exception) { $exception['display_id']=$exception['employee_id']!==null ? IdentifierService::codeOf(['id'=>$exception['employee_id'],'pass_code'=>$exception['pass_code']]) : null; } unset($exception);
        return $this->web->render('admin/schedules.html.twig',['groups'=>$this->groups(),'employees'=>$this->employeeService->list(),'assignments'=>$assignments,'exceptions'=>$exceptions]);
    }
    public function scheduleSave(Request $request): Response { $actor=$this->write($request); $group=$this->scheduleService->saveGroupVersion(Input::data($request),(int)$actor['id']); return $this->finish($request,['success'=>true,'group'=>$group],'/admin/schedules'); }
    public function assignmentSave(Request $request): Response { $actor=$this->write($request); $assignment=$this->scheduleService->saveAssignment(Input::data($request),(int)$actor['id']); return $this->finish($request,['success'=>true,'assignment'=>$assignment],'/admin/schedules'); }
    public function calendarSave(Request $request): Response { $actor=$this->write($request); $exception=$this->scheduleService->saveException(Input::data($request),(int)$actor['id']); return $this->finish($request,['success'=>true,'exception'=>$exception],'/admin/schedules'); }

    public function settings(Request $request): Response
    {
        $this->auth->requireRole('ADMIN'); return $this->web->render('admin/settings.html.twig',['values'=>$this->settingsService->all()]);
    }
    public function settingsSave(Request $request): Response
    {
        $actor=$this->write($request); $data=Input::data($request); $validated=[];
        foreach (Settings::DEFAULTS as $key=>$default) {
            if (!array_key_exists($key,$data)) { continue; }
            $value=$data[$key];
            switch ($key) {
                case 'qr_quiet_ms': $value=$this->integer($value,60,200); break;
                case 'duplicate_seconds': $value=$this->integer($value,0,30); break;
                case 'manual_lag_seconds': $value=$this->integer($value,0,600); break;
                case 'missing_after_minutes': case 'close_after_minutes': $value=$this->integer($value,0,240); break;
                case 'report_max_days': $value=$this->integer($value,1,366); break;
                case 'extra_events': case 'badge_position': $value=EmployeeService::bool($value)===1; break;
                case 'auto_start': case 'auto_cutover': if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$value)) { throw new \InvalidArgumentException('Время в формате HH:MM'); } break;
                case 'night_event': if (!in_array($value,['arrival','departure'],true)) { throw new \InvalidArgumentException('Неизвестная ночная метка'); } break;
                case 'theme': if (!in_array($value,['light','dark','system'],true)) { throw new \InvalidArgumentException('Неизвестная тема'); } break;
                case 'badge_orientation': if (!in_array($value,['portrait','landscape'],true)) { throw new \InvalidArgumentException('Неизвестная ориентация'); } break;
                case 'history_start': $value=EmployeeService::date($value,true); break;
                default: if (!is_scalar($value) || mb_strlen((string)$value)>1000) { throw new \InvalidArgumentException('Некорректное значение настройки'); } $value=trim((string)$value);
            } $validated[$key]=$value;
        }
        $start=(string)($validated['auto_start']??$this->settingsService->get('auto_start','05:00')); $cutover=(string)($validated['auto_cutover']??$this->settingsService->get('auto_cutover','12:00'));
        if ($start >= $cutover) { throw new \InvalidArgumentException('Начало окна прихода должно быть раньше переключения на уход'); }
        $this->database->transaction(function() use ($validated,$actor) { foreach($validated as $key=>$value) { if ($this->settingsService->get($key)!==$value) { $this->settingsService->set($key,$value,$actor); } } });
        return $this->finish($request,['success'=>true,'settings'=>$this->settingsService->all()],'/admin/settings');
    }

    public function credentials(Request $request): Response
    {
        $this->auth->requireRole('ADMIN'); $filters=$request->query->all();
        $credentials=$this->employeeService->credentials($filters);
        return $this->web->render('admin/credentials.html.twig',['employees'=>$this->employeeService->list(['active'=>'active']),'credentials'=>$credentials,'rows'=>$credentials,'filters'=>$filters]);
    }
    public function credentialSave(Request $request): Response { $actor=$this->write($request); $credential=$this->employeeService->bindCredential(Input::data($request),(int)$actor['id']); return $this->finish($request,['success'=>true,'credential'=>$credential],'/admin/credentials'); }
    public function credentialRevoke(Request $request): Response { $actor=$this->write($request); $data=Input::data($request); $credential=$this->employeeService->revokeCredential(EmployeeService::id($data['id']??$data['credential_id']??''),(int)$actor['id']); return $this->finish($request,['success'=>true,'credential'=>$credential],'/admin/credentials'); }

    public function imports(Request $request): Response { $this->auth->requireRole('ADMIN'); return $this->web->render('admin/import.html.twig',['batches'=>$this->database->all('SELECT id,file_hash,mode,summary_json,actor_employee_id,created_at FROM import_batches ORDER BY id DESC'),'preview'=>null,'rows'=>[],'summary'=>[]]); }
    public function importPreview(Request $request): Response
    {
        $actor=$this->write($request); $file=$request->files->get('file');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) { throw new \InvalidArgumentException('Выберите файл'); }
        $preview=$this->importService->preview($file,(string)$request->request->get('mode','add_only'),(int)$actor['id']);
        if ($this->wantsJson($request)) { return $this->web->json(['success'=>true,'preview'=>$preview]+$preview); }
        return $this->web->render('admin/import.html.twig',['preview'=>$preview,'rows'=>$preview['rows'],'summary'=>$preview['summary'],'batches'=>[]]);
    }
    public function importCommit(Request $request): Response { $actor=$this->write($request); $data=Input::data($request); return $this->finish($request,$this->importService->commit((string)($data['token']??''),(int)$actor['id']),'/admin/import'); }

    public function export(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR'); $to=(string)$request->query->get('to',EmployeeService::today()); $from=(string)$request->query->get('from',substr($to,0,7).'-01');
        $id=$request->query->get('employee_id'); $file=$this->exportService->export($from,$to,(string)$request->query->get('format','xlsx'),$id!==null && $id!=='' && $id!=='0' ? EmployeeService::id($id) : null,(string)$request->query->get('finding',''));
        return new Response($file['content'],200,['Content-Type'=>$file['mime'],'Content-Disposition'=>'attachment; filename="'.$file['filename'].'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function help(Request $request): Response
    {
        $this->auth->requireRole('MODERATOR');
        $articles=[
            ['title'=>'Отметиться без карточки','text'=>'Откройте ручной режим, введите свой ID и нажмите «Записать сейчас». PIN сотрудника не нужен; время назначает сервер.'],
            ['title'=>'Приход, уход и другие отметки','text'=>'AUTO: с 05:00 до 12:00 приход, с 12:00 уход; ночное правило задано настройкой. Для необычного прохода выберите метку. Обед и уважительный уход видны при включении дополнительных событий.'],
            ['title'=>'Сотрудники и доступ','text'=>'Новые сотрудники получают случайный код VVE; прежние числовые пропуска продолжают работать. Увольнение сохраняет историю. Перевыпуск меняет код у того же сотрудника и отключает прежний QR, не удаляя записи. Перед перевыпуском необходимо подтвердить отзыв прежнего Wallet. ADMIN меняет роли, MODERATOR смотрит табель и отчёты. Вход использует действующий код пропуска и пароль.'],
            ['title'=>'Графики и календарь','text'=>'Назначьте группу с даты. Отпуск, сокращённый или рабочий выходной день задайте исключением. Изменение новой версии графика не переписывает прошлые расчёты.'],
            ['title'=>'Импорт','text'=>'CSV, XLSX или XLS: ID, ФИО либо Фамилия/Имя/Отчество, Должность, Активен. Сначала проверьте предпросмотр. «Только добавить» сохраняет существующих; «Обновить по ID» показывает различия. Тот же файл не создаёт повторных людей. Формулы не принимаются.'],
            ['title'=>'Печать и калибровка','text'=>'Выберите сотрудников, макет и лист. Координаты заданы в миллиметрах. Проверьте перекрытия и границы, печатайте PDF в 100% масштабе. Смещение принтера исправляется offset X/Y; масштаб — настройкой принтера.'],
            ['title'=>'NFC','text'=>'ADMIN выбирает сотрудника и привязывает стабильный UID карты. UID не равен ID. Режим обучения не пишет приход. Утерянную карту отзовите; QR и ручной ввод продолжают работать. Считыватель должен быть настроен и испытан.'],
            ['title'=>'Отчёт','text'=>'Выберите период и сотрудника. XLSX, CSV и PDF показывают действующие времена и доступные признаки. Если период слишком велик для XLSX/PDF, сократите период или выберите CSV.'],
        ];
        return $this->web->render('admin/help.html.twig',['articles'=>$articles]);
    }
    public function status(Request $request): Response
    {
        $this->auth->requireRole('ADMIN'); $root=dirname(__DIR__,2); $backup=(string)$this->settingsService->get('backup_external_path','');
        $metadata=null; $lastBackup=null; $metadataPath=$root.'/var/backup-status.json';
        if (is_readable($metadataPath) && filesize($metadataPath)<=16384) {
            try { $candidate=json_decode((string)file_get_contents($metadataPath),true,16,JSON_THROW_ON_ERROR); if (is_array($candidate) && isset($candidate['last_backup_at']) && is_string($candidate['last_backup_at']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$candidate['last_backup_at']) && is_bool($candidate['local']??null) && is_bool($candidate['external']??null)) { $lastBackup=(new \DateTimeImmutable($candidate['last_backup_at']))->setTimezone(new \DateTimeZone('Asia/Sakhalin'))->format('Y-m-d H:i:s P'); $metadata=$candidate; } } catch (\Exception) { /* Invalid/missing metadata remains explicitly unknown. */ }
        }
        $status=['php_version'=>PHP_VERSION,'database_version'=>$this->database->scalar('SELECT VERSION()'),'timezone'=>date_default_timezone_get(),'server_time'=>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Sakhalin')))->format('Y-m-d H:i:s P'),'employees'=>$this->employeeService->count(),'disk_free_bytes'=>disk_free_space($root),'last_backup_at'=>$lastBackup,'local_backup_ok'=>$metadata['local']??null,'external_copy_ok'=>$metadata['external']??null,'external_copy_configured'=>$backup!=='' ];
        return $this->web->render('admin/status.html.twig',['status'=>$status,'summary'=>$status]);
    }

    private function groups(): array { return array_map(static function($row) { $row['weekdays']=json_decode($row['weekdays'],true,512,JSON_THROW_ON_ERROR); if (isset($row['config_json'])) { $row['config']=json_decode($row['config_json'],true); } return $row; },$this->database->all('SELECT * FROM schedule_groups ORDER BY name')); }
    private function write(Request $request): array { $actor=$this->auth->requireRole('ADMIN'); if (!$request->isMethod('POST')) { throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['POST']); } $this->auth->checkCsrf($request); return $actor; }
    private function wantsJson(Request $request): bool { return str_contains($request->headers->get('Accept',''),'application/json') || str_contains($request->headers->get('Content-Type',''),'application/json') || $request->isXmlHttpRequest(); }
    private function finish(Request $request,array $data,string $url): Response { if ($this->wantsJson($request)) { return $this->web->json($data); } if ($request->hasSession()) { $request->getSession()->getFlashBag()->add('success','Сохранено'); } return $this->web->redirect($url); }
    private function integer(mixed $value,int $min,int $max): int { $v=filter_var($value,FILTER_VALIDATE_INT); if ($v===false || $v<$min || $v>$max) { throw new \InvalidArgumentException('Значение вне диапазона '.$min.'–'.$max); } return $v; }
}

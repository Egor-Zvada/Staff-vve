<?php
declare(strict_types=1);
namespace App\Controller;
use App\Input;
use App\Web;
use App\Security\Auth;
use App\Infrastructure\Database;
use App\Service\AttendanceService;
use App\Service\OwnerWeekService;
use App\Service\PrivateAutomationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
final class OwnerController
{
    public function __construct(private Web $web,private Auth $auth,private Database $database,private AttendanceService $attendance,private OwnerWeekService $weeks,private PrivateAutomationService $automation){}
    private function env(string $name):string{return (string)($_SERVER[$name]??$_ENV[$name]??'');}
    private function authenticated(Request $request):bool{return (int)$request->getSession()->get('owner_expires',0)>time()&&hash_equals(hash('sha256',$this->env('BACKDOOR_PASSWORD_HASH')),(string)$request->getSession()->get('owner_key',''));}
    public function index(Request $request):Response
    {
        if(!$this->env('BACKDOOR_LOGIN')||!$this->env('BACKDOOR_PASSWORD_HASH'))throw new NotFoundHttpException();$error=null;
        if($request->isMethod('POST')){
            $this->auth->checkCsrf($request);$data=Input::data($request);$login=(string)($data['login']??'');$this->auth->checkLimit($request,'owner:'.$login);
            $ok=hash_equals($this->env('BACKDOOR_LOGIN'),$login)&&password_verify((string)($data['password']??''),$this->env('BACKDOOR_PASSWORD_HASH'));
            $this->auth->attempt($request,'owner:'.$login,$ok);
            if($ok){$s=$request->getSession();$s->migrate(true);$s->set('owner_expires',time()+1800);$s->set('owner_key',hash('sha256',$this->env('BACKDOOR_PASSWORD_HASH')));return $this->web->redirect($this->env('PRIVATE_PATH'));}$error='Неверные реквизиты';
        }
        if(!$this->authenticated($request))return $this->web->render('private/login.html.twig',['error'=>$error,'private_path'=>$this->env('PRIVATE_PATH')]);
        $people=$this->database->all("SELECT id,pass_code,pass_code AS display_id,CONCAT_WS(' ',surname,given_name,patronymic) name,active FROM employees WHERE is_service=0 ORDER BY surname,given_name");
        $id=$request->query->has('employee_id')?(int)$request->query->get('employee_id'):((int)($people[0]['id']??0));
        $today=$this->weeks->today();$date=(string)$request->query->get('date',$today);
        $tab=(string)$request->query->get('tab',$request->query->has('date')?'day':'week');
        if(!in_array($tab,['week','automation','day'],true))$tab='week';
        $weekDate=(string)$request->query->get('week',$date);$week=$id>0?$this->weeks->week($id,$weekDate):null;
        if($week===null){$first=new \DateTimeImmutable(OwnerWeekService::monday($weekDate));$week=['start'=>$first->format('Y-m-d'),'end'=>$first->modify('+6 days')->format('Y-m-d'),'previous'=>$first->modify('-7 days')->format('Y-m-d'),'next'=>$first->modify('+7 days')->format('Y-m-d'),'days'=>[]];}
        $detail=$id>0&&$tab==='day'?$this->attendance->privateDay($id,$date):null;
        $rules=$this->automation->listRules($id>0?$id:null);$edit=null;
        if($request->query->has('edit_rule'))foreach($rules as$rule)if((int)$rule['id']===(int)$request->query->get('edit_rule'))$edit=$rule;
        return $this->web->render('private/panel.html.twig',['private_path'=>$this->env('PRIVATE_PATH'),'employees'=>$people,'detail'=>$detail,'employee_id'=>$id,'date'=>$date,'today'=>$today,'tab'=>$tab,'week'=>$week,'rules'=>$rules,'rule_edit'=>$edit,'automation_runs'=>$this->automation->recentRuns(),'automation_status'=>$this->automation->status(),'saved'=>$request->query->getBoolean('saved'),'week_request_id'=>'owner-week-'.bin2hex(random_bytes(16)),'clear_request_id'=>'owner-'.bin2hex(random_bytes(16)),'advanced_request_id'=>'owner-'.bin2hex(random_bytes(16))]);
    }
    public function adjust(Request $request):Response
    {
        if(!$this->authenticated($request))throw new AccessDeniedHttpException();$this->auth->checkCsrf($request);$data=Input::data($request);
        foreach(['flag_ids','correction_ids','event_ids'] as $key)if(isset($data[$key])&&!is_array($data[$key]))$data[$key]=array_filter(array_map('intval',explode(',',(string)$data[$key])));
        if(isset($data['all']))$data['all']=filter_var($data['all'],FILTER_VALIDATE_BOOL);
        if(isset($data['values'])&&is_array($data['values']))foreach($data['values'] as &$value)if($value==='')$value=null;
        unset($value);
        if(($data['action']??'')==='correct')$data['values']=array_intersect_key($data['values']??[],array_flip($data['apply_fields']??[]));
        $result=$this->attendance->ownerAdjust($data);
        if(str_contains($request->headers->get('Accept',''),'application/json'))return $this->web->json($result);
        return $this->web->redirect($this->env('PRIVATE_PATH').'?employee_id='.(int)$data['employee_id'].'&date='.urlencode((string)($data['work_date']??date('Y-m-d'))).'&saved=1');
    }
    public function logout(Request $request):Response{$this->auth->checkCsrf($request);$request->getSession()->remove('owner_expires');$request->getSession()->remove('owner_key');return $this->web->redirect($this->env('PRIVATE_PATH'));}
    public function weekSave(Request $request):Response
    {
        $this->privateWrite($request);$result=$this->weeks->save(Input::data($request));
        $redirect=$this->env('PRIVATE_PATH').'?'.http_build_query(['tab'=>'week','employee_id'=>$result['employee_id'],'week'=>$result['week_start'],'saved'=>1]);
        return $this->privateResult($request,$result,$redirect);
    }
    public function automationSave(Request $request):Response
    {
        $this->privateWrite($request);$rule=$this->automation->save(Input::data($request));
        $redirect=$this->env('PRIVATE_PATH').'?'.http_build_query(['tab'=>'automation','employee_id'=>$rule['employee_id'],'saved'=>1]);
        return $this->privateResult($request,['success'=>true,'rule'=>$rule],$redirect);
    }
    public function automationToggle(Request $request):Response
    {
        $this->privateWrite($request);$rule=$this->automation->toggle(Input::data($request));
        $redirect=$this->env('PRIVATE_PATH').'?'.http_build_query(['tab'=>'automation','employee_id'=>$rule['employee_id'],'saved'=>1]);
        return $this->privateResult($request,['success'=>true,'rule'=>$rule],$redirect);
    }
    private function privateWrite(Request $request):void { if(!$this->authenticated($request))throw new AccessDeniedHttpException('Служебная сессия закончилась. Откройте вход заново; введённые значения оставлены на экране.');$this->auth->checkCsrf($request); }
    private function privateResult(Request $request,array $result,string $redirect):Response { return str_contains($request->headers->get('Accept',''),'application/json')?$this->web->json($result+['redirect'=>$redirect]):$this->web->redirect($redirect); }
}

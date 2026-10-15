<?php
declare(strict_types=1);
namespace App\Controller;
use App\Input;
use App\Web;
use App\Security\Auth;
use App\Infrastructure\Database;
use App\Service\AttendanceService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
final class OwnerController
{
    public function __construct(private Web $web,private Auth $auth,private Database $database,private AttendanceService $attendance){}
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
        $id=(int)$request->query->get('employee_id',0);$date=(string)$request->query->get('date',date('Y-m-d'));
        $detail=$id>0?$this->attendance->privateDay($id,$date):null;
        return $this->web->render('private/panel.html.twig',['private_path'=>$this->env('PRIVATE_PATH'),'employees'=>$this->database->all("SELECT id,pass_code,pass_code AS display_id,CONCAT_WS(' ',surname,given_name,patronymic) name,active FROM employees WHERE is_service=0 ORDER BY surname,given_name"),'detail'=>$detail,'employee_id'=>$id,'date'=>$date,'saved'=>$request->query->getBoolean('saved'),'clear_request_id'=>'owner-'.bin2hex(random_bytes(16)),'advanced_request_id'=>'owner-'.bin2hex(random_bytes(16))]);
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
}

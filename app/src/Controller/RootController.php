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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
final class RootController
{
    public function __construct(private Web $web,private Auth $auth,private Database $database,private AttendanceService $attendance,private EmployeeService $employees,private IdentifierService $identifiers){}
    public function scanner(Request $request):Response{return $this->web->render('scanner.html.twig',['date'=>date('Y-m-d'),'server_time'=>date('H:i:s'),'timestamp'=>date(DATE_ATOM)]);}
    public function login(Request $request):Response
    {
        if($this->auth->user())return $this->web->redirect('/admin');$error=null;
        if($request->isMethod('POST')){$this->auth->checkCsrf($request);$data=Input::data($request);if($this->auth->login($request,(string)($data['employee_id']??$data['id']??''),(string)($data['password']??'')))return $this->web->redirect('/admin');$error='Неверный ID или пароль';}
        return $this->web->render('login.html.twig',['error'=>$error]);
    }
    public function logout(Request $request):Response{$this->auth->checkCsrf($request);$this->auth->logout($request);return $this->web->redirect('/login');}
    public function mark(Request $request):Response
    {
        $this->auth->checkCsrf($request);$data=Input::data($request);$data['code']=IdentifierService::normalizeInput((string)($data['code']??$data['employee_id']??''));
        $source=str_starts_with($data['code'],'NFC:')?'nfc':(($data['source']??'qr')==='keyboard'?'keyboard':'qr');
        return $this->web->json($this->attendance->mark($data,$source));
    }
    public function manual(Request $request):Response
    {
        $code=trim((string)$request->query->get('code',''));$rawId=(string)$request->query->get('employee_id','');
        $id=($code!==''||($rawId!==''&&$rawId!=='0'))?$this->publicDayId($request):0;$date=(string)$request->query->get('date',date('Y-m-d'));
        $data=$id>0?$this->attendance->day($id,$date):['employee'=>null,'events'=>[],'changes'=>[],'flags'=>[],'plan'=>null];
        $data['day']=$id>0?$data:[];
        $display=$id>0?($data['employee']['pass_code']??str_pad((string)$id,3,'0',STR_PAD_LEFT)):'';
        return $this->web->render('manual.html.twig',$data+['employee_id'=>$id,'code'=>$code!==''?$code:$display,'date'=>$date]);
    }
    public function day(Request $request):Response
    {
        $day=$this->attendance->day($this->publicDayId($request),(string)$request->query->get('date',date('Y-m-d')));
        return $this->web->json(['success'=>true,'day'=>$day,'employee'=>$day['employee'],'events'=>$day['events'],'changes'=>$day['changes'],'flags'=>$day['flags'],'plan'=>$day['plan']]);
    }
    private function publicDayId(Request $request):int
    {
        $code=trim((string)$request->query->get('code',''));
        if($code!=='')return (int)$this->identifiers->resolveCode($code)['id'];
        $raw=(string)$request->query->get('employee_id','');
        $user=$this->auth->user();
        if($user&&in_array($user['admin_role'],['ADMIN','MODERATOR'],true))return EmployeeService::id($raw);
        return (int)$this->identifiers->resolveCode($raw)['id'];
    }
    private function manualPayload(Request $request):array
    {
        $data=Input::data($request);$data['session_fingerprint']=hash('sha256',$request->getSession()->getId().'|'.($request->getClientIp()??'').'|'.$request->headers->get('User-Agent',''));return $data;
    }
    public function manualSave(Request $request):Response{$this->auth->checkCsrf($request);return $this->web->json($this->attendance->manual($this->manualPayload($request),$this->auth->user()['id']??null));}
    public function attendance(Request $request):Response
    {
        $this->auth->requireRole('MODERATOR');$from=(string)$request->query->get('from',date('Y-m-01'));$to=(string)$request->query->get('to',date('Y-m-d'));$id=(int)$request->query->get('employee_id',0);
        $period=$this->attendance->period($from,$to,$id?:null);$finding=(string)$request->query->get('finding','');
        $rows=\App\Service\AttendanceFilter::apply($period['rows']??$period,$finding);
        return $this->web->render('admin/attendance.html.twig',['rows'=>$rows,'summary'=>$period['summary']??[],'from'=>$from,'to'=>$to,'employees'=>$this->employees->list(['active'=>'all']),'filters'=>['employee_id'=>$id?:'','finding'=>$finding],'employee_id'=>$id]);
    }
    public function adminDay(Request $request):Response{$this->auth->requireRole('MODERATOR');return $this->day($request);}
    public function adminManual(Request $request):Response{$user=$this->auth->requireRole('ADMIN');$this->auth->checkCsrf($request);return $this->web->json($this->attendance->manual($this->manualPayload($request),(int)$user['id']));}
    public function enrollmentLogin(Request $request):Response
    {
        $this->auth->checkCsrf($request);$data=Input::data($request);$id=$this->auth->loginKey((string)($data['employee_id']??''));$this->auth->checkLimit($request,'enroll:'.$id);
        $row=$this->auth->accountForCode($id);
        $hash=isset($data['password'])?$row['password_hash']??'':$row['enrollment_pin_hash']??'';
        $secret=(string)($data['password']??$data['pin']??'');$ok=$row&&(int)$row['active']&&$row['admin_role']==='ADMIN'&&password_verify($secret,(string)$hash);
        $this->auth->attempt($request,'enroll:'.$id,(bool)$ok);if(!$ok)throw new AccessDeniedHttpException('Неверный ID или PIN выдачи');
        $s=$request->getSession();$s->migrate(true);$s->set('enrollment_id',(int)$row['id']);$s->set('enrollment_version',(int)$row['auth_version']);$s->set('enrollment_expires',time()+120);
        return $this->web->json(['success'=>true,'expires_in'=>120]);
    }
    public function enrollmentEmployees(Request $request):Response{$this->auth->enrollment($request);$rows=$this->database->all("SELECT id,pass_code,pass_code AS display_id,CONCAT_WS(' ',surname,given_name,NULLIF(patronymic,'')) AS name FROM employees WHERE active=1 AND is_service=0 ORDER BY surname,given_name");return $this->web->json(['success'=>true,'employees'=>$rows]);}
    public function enrollmentBind(Request $request):Response
    {
        $this->auth->checkCsrf($request);$actor=$this->auth->enrollment($request);$data=Input::data($request);
        $result=$this->employees->bindCredential(['employee_id'=>$data['employee_id']??0,'kind'=>'NFC_UID','value'=>$data['code']??$data['value']??''],(int)$actor['id']);
        return $this->web->json(['success'=>true,'credential'=>$result]);
    }
    public function clock(Request $request):Response{return $this->web->json(['success'=>true,'timestamp'=>date(DATE_ATOM),'date'=>date('Y-m-d'),'time'=>date('H:i:s'),'timezone'=>'Asia/Sakhalin']);}
    public function health(Request $request):Response{try{$this->database->scalar('SELECT 1');return $this->web->json(['status'=>'ok','timezone'=>'Asia/Sakhalin']);}catch(\Throwable){return $this->web->json(['status'=>'unavailable'],503);}}
}

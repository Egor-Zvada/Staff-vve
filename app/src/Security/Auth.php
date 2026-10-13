<?php
declare(strict_types=1);
namespace App\Security;
use App\Infrastructure\Database;
use App\Input;
use App\Service\IdentifierService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
final class Auth
{
    public function __construct(private Database $database,private RequestStack $requests,private IdentifierService $identifiers){}
    public function user():?array
    {
        $s=$this->requests->getSession();$id=$s->get('admin_id');if($id===null)return null;
        $row=$this->database->one('SELECT * FROM employees WHERE id=?',[$id]);
        if(!$row||!(int)$row['active']||!in_array($row['admin_role'],['ADMIN','MODERATOR'],true)||(int)$row['auth_version']!==(int)$s->get('auth_version')){$s->remove('admin_id');$s->remove('auth_version');return null;}
        unset($row['password_hash'],$row['enrollment_pin_hash']);$row['name']=trim($row['surname'].' '.$row['given_name'].' '.$row['patronymic']);return $row;
    }
    public function requireRole(string $role):array
    {
        $user=$this->user();if(!$user||($role==='ADMIN'&&$user['admin_role']!=='ADMIN'))throw new AccessDeniedHttpException('Недостаточно прав');return $user;
    }
    public function csrf():string
    {
        $session=$this->requests->getSession();if(!$session->has('csrf'))$session->set('csrf',bin2hex(random_bytes(32)));return $session->get('csrf');
    }
    public function checkCsrf(Request $request):void
    {
        $token=$request->headers->get('X-CSRF-Token')??(Input::data($request)['csrf']??'');
        if(!is_string($token)||!hash_equals($this->csrf(),$token))throw new AccessDeniedHttpException('Страница устарела. Обновите её.');
        $origin=$request->headers->get('Origin');if($origin&&$origin!==$request->getSchemeAndHttpHost())throw new AccessDeniedHttpException('Недопустимый источник запроса');
    }
    public function login(Request $request,string $id,string $password):bool
    {
        $id=$this->loginKey($id);$this->checkLimit($request,$id);$employee=$this->accountForCode($id);
        $ok=$employee&&(int)$employee['active']&&in_array($employee['admin_role'],['ADMIN','MODERATOR'],true)&&password_verify($password,(string)$employee['password_hash']);
        $this->attempt($request,$id,(bool)$ok);
        if($ok){$session=$request->getSession();$session->migrate(true);$session->set('admin_id',(int)$employee['id']);$session->set('auth_version',(int)$employee['auth_version']);$session->remove('csrf');return true;}return false;
    }
    public function accountForCode(string $code):?array
    {
        try{return $this->identifiers->resolveCode($code,true,true);}catch(\DomainException|\InvalidArgumentException){return null;}
    }
    public function loginKey(string $code):string
    {
        $code=IdentifierService::normalizeInput($code);return ctype_digit($code)?(ltrim($code,'0')?:'0'):$code;
    }
    public function logout(Request $request):void{$request->getSession()->invalidate();}
    public function checkLimit(Request $request,string $id):void
    {
        $count=$this->database->scalar('SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND login_id=? AND success=0 AND created_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE',[hash('sha256',$request->getClientIp()??''),substr($id,0,80)]);
        if((int)$count>=8)throw new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(900,'Слишком много попыток. Повторите позже.');
    }
    public function attempt(Request $request,string $id,bool $success):void{$this->database->insert('login_attempts',['ip_hash'=>hash('sha256',$request->getClientIp()??''),'login_id'=>substr($id,0,80),'success'=>(int)$success,'created_at'=>gmdate('Y-m-d H:i:s')]);}
    public function enrollment(Request $request):array
    {
        $s=$request->getSession();if((int)$s->get('enrollment_expires',0)<time())throw new AccessDeniedHttpException('Время регистрации истекло');
        $row=$this->database->one('SELECT id,active,admin_role,auth_version FROM employees WHERE id=?',[$s->get('enrollment_id',-1)]);
        if(!$row||!(int)$row['active']||$row['admin_role']!=='ADMIN'||(int)$row['auth_version']!==(int)$s->get('enrollment_version'))throw new AccessDeniedHttpException('Регистрация недоступна');return $row;
    }
}

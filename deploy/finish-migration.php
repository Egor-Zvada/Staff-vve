<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Root CLI required');
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/staff-v2/app/.env');
date_default_timezone_set('Asia/Sakhalin');
$db=new App\Infrastructure\Database($_SERVER['DATABASE_URL']);$employees=new App\Service\EmployeeService($db,new App\Service\Settings($db));
$changes=json_decode(file_get_contents('/tmp/staff-final-clarifications.json'),true,512,JSON_THROW_ON_ERROR);
$db->transaction(function()use($db,$employees,$changes){
 foreach($changes as$id=>$fields){$row=$employees->find((int)$id)??throw new RuntimeException('Expected employee missing');foreach(['given_name','patronymic']as$field)if(isset($fields[$field]))$row[$field]=$fields[$field];$employees->save($row,7);}
 if((int)$db->scalar("SELECT COUNT(*) FROM employees WHERE active=1 AND is_service=0 AND admin_role='ADMIN'")<2)throw new RuntimeException('Personal administrators must be verified first');
 $service=$db->one('SELECT id,active,admin_role FROM employees WHERE id=0 AND is_service=1 FOR UPDATE');
 if($service&&(int)$service['active']){$db->exec("UPDATE employees SET active=0,admin_role='NONE',password_hash=NULL,enrollment_pin_hash=NULL,auth_version=auth_version+1,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=0 AND is_service=1");$employees->audit(7,'bootstrap.retired','employee',0,$service,['id'=>0,'active'=>0,'admin_role'=>'NONE']);}
});
unlink('/tmp/staff-final-clarifications.json');
echo json_encode(['clarified_employee_ids'=>array_keys($changes),'technical_login_disabled'=>true,'personal_administrators_retained'=>true],JSON_THROW_ON_ERROR).PHP_EOL;

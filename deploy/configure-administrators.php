<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Root CLI required');
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/staff-v2/app/.env');
date_default_timezone_set('Asia/Sakhalin');
$input=json_decode(file_get_contents('/tmp/staff-admin-credentials.json'),true,512,JSON_THROW_ON_ERROR);
$db=new App\Infrastructure\Database($_SERVER['DATABASE_URL']);$settings=new App\Service\Settings($db);$employees=new App\Service\EmployeeService($db,$settings);
$db->transaction(function()use($db,$employees,$settings,$input){
 foreach($input['clarifications']??[] as$id=>$fields){$row=$employees->find((int)$id)??throw new RuntimeException('Clarified employee missing');foreach(['given_name','patronymic']as$field)if(isset($fields[$field]))$row[$field]=$fields[$field];$employees->save($row,0);}
 foreach($input['ids'] as$id){$row=$employees->find((int)$id)??throw new RuntimeException('Expected imported administrator missing');if(!(int)$row['active'])throw new RuntimeException('Expected administrator is inactive');$row['admin_role']='ADMIN';$row['password']=$input['password'];$row['enrollment_pin']=$input['enrollment_pin'];$employees->save($row,0);}
 $settings->set('org_name','ОГАУ ДО «СШ ВВЕ»',0);
});
unlink('/tmp/staff-admin-credentials.json');
echo json_encode(['administrator_ids'=>$input['ids'],'password_and_pin_configured'=>true,'organization_configured'=>true],JSON_THROW_ON_ERROR).PHP_EOL;

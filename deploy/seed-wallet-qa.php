<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Root CLI required');
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/staff-v2/app/.env');
date_default_timezone_set('Asia/Sakhalin');
$url=str_replace('/staff_v2?','/staff_v2_qa?',$_SERVER['DATABASE_URL']);
$db=new App\Infrastructure\Database($url);
if($db->scalar('SELECT DATABASE()')!=='staff_v2_qa')throw new RuntimeException('Isolated QA database required');
$employees=new App\Service\EmployeeService($db,new App\Service\Settings($db));
if(!$employees->find(99))$db->transaction(fn()=>$employees->applyImportRow(['id'=>99,'surname'=>'Проверочный','given_name'=>'Кошелёк','patronymic'=>'','active'=>1],0));
$response=json_decode(file_get_contents('/root/staff-wallet-test/named-response.json'),true,32,JSON_THROW_ON_ERROR);
$wallet=new App\Service\WalletService($db,new App\Service\WalletProvider(),new App\Service\WalletPassValidator());
$result=$wallet->recover(99,0,$response);
echo json_encode(['qa_employee_id'=>99,'state'=>$result['state'],'display_id'=>$result['display_id'],'cached'=>$result['cached'],'quota'=>$result['quota'],'external_creates'=>0],JSON_UNESCAPED_UNICODE).PHP_EOL;

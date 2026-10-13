<?php
declare(strict_types=1);
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/staff-v2/app/.env');
$provider=new App\Service\WalletProvider();$r=$provider->request('GET','/api/auth/usage');$d=json_decode($r['body'],true);
echo json_encode(['http_status'=>$r['status'],'body_length'=>strlen($r['body']),'keys'=>is_array($d)?array_keys($d):[],'usage_count'=>$d['count']??null,'usage_limit'=>$d['limit']??null,'reset_date'=>$d['resetDate']??null,'plan'=>$d['plan']??null],JSON_UNESCAPED_UNICODE).PHP_EOL;
$url=str_replace('/staff_v2?','/staff_v2_qa?',$_SERVER['DATABASE_URL']);$db=new App\Infrastructure\Database($url);
$service=new App\Service\WalletService($db,$provider,new App\Service\WalletPassValidator());
echo json_encode(['refresh'=>$service->refreshUsage(true)],JSON_UNESCAPED_UNICODE).PHP_EOL;
if(is_array($d)&&isset($d['count'],$d['limit'],$d['resetDate'])){
 try{$reset=App\Service\ScheduleService::validateDate((string)$d['resetDate']);$db->exec('UPDATE wallet_provider_usage SET used_count=?,quota_limit=?,reset_date=?,plan=?,fetched_at=? WHERE id=1',[(int)$d['count'],min(1000,(int)$d['limit']),$reset,(string)$d['plan'],gmdate('Y-m-d H:i:s')]);echo 'Direct quota update succeeded.'.PHP_EOL;}catch(Throwable$e){echo json_encode(['quota_error_class'=>get_class($e),'quota_error'=>substr($e->getMessage(),0,180)],JSON_UNESCAPED_UNICODE).PHP_EOL;}
}

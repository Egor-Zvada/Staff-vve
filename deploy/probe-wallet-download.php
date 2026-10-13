<?php
declare(strict_types=1);
require '/opt/staff-v2/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->loadEnv('/opt/staff-v2/app/.env');
$key=$_ENV['WALLETWALLET_API_KEY'];
$test=json_decode(file_get_contents('/root/staff-wallet-test/response.json'),true,512,JSON_THROW_ON_ERROR);$serial=$test['serialNumber'];
$urls=[$test['shareUrl'],'https://api.walletwallet.dev/api/passes/'.$serial.'/apple','https://api.walletwallet.dev/api/pkpass/'.$serial,'https://api.walletwallet.dev/api/passes/'.$serial.'/pkpass'];
foreach($urls as $url){
 $c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key]]);
 $raw=(string)curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);$type=curl_getinfo($c,CURLINFO_CONTENT_TYPE);curl_close($c);
 $links=[];if(str_contains((string)$type,'text/html')){preg_match_all('~(?:href|src)=["\']([^"\']+)["\']~',$raw,$matches);foreach($matches[1]as$link)if(str_contains($link,'api')||str_contains($link,'pkpass'))$links[]=str_replace($serial,'TEST_SERIAL',$link);file_put_contents('/root/staff-wallet-test/install.html',$raw);}
 echo json_encode(['route'=>str_replace($serial,'TEST_SERIAL',$url),'status'=>$status,'type'=>$type,'bytes'=>strlen($raw),'binary_zip'=>str_starts_with($raw,'PK'),'install_links'=>$links],JSON_UNESCAPED_UNICODE).PHP_EOL;
}

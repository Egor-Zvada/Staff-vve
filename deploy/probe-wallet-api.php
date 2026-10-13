<?php
declare(strict_types=1);
$key=trim(file_get_contents('/tmp/staff-wallet-key'));
$test=json_decode(file_get_contents('/root/staff-wallet-test/response.json'),true,512,JSON_THROW_ON_ERROR);
$serial=$test['serialNumber'];
foreach (['/api/passes/'.$serial,'/api/passes?credentials=platform','/api/passes/'.$serial.'?format=pkpass','/api/passes/'.$serial.'/download'] as $route) {
 $c=curl_init('https://api.walletwallet.dev'.$route);
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key]]);
 $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);$type=curl_getinfo($c,CURLINFO_CONTENT_TYPE);curl_close($c);
 $data=json_decode((string)$raw,true);
 $shape=function($v) use (&$shape) {if(!is_array($v))return gettype($v);$result=[];foreach($v as $k=>$item){if(is_int($k)&&$k>0)break;$result[$k]=$shape($item);}return $result;};
 echo json_encode(['route'=>str_replace($serial,'TEST_SERIAL',$route),'status'=>$status,'content_type'=>$type,'bytes'=>strlen((string)$raw),'shape'=>$data===null?null:$shape($data),'binary_zip'=>str_starts_with((string)$raw,'PK')],JSON_UNESCAPED_UNICODE).PHP_EOL;
 file_put_contents('/root/staff-wallet-test/probe-'.md5($route).'.json',(string)$raw);
}

<?php
declare(strict_types=1);
$test=json_decode(file_get_contents('/root/staff-wallet-test/response.json'),true,512,JSON_THROW_ON_ERROR);
$c=curl_init($test['shareUrl']);
curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>25,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_USERAGENT=>'StaffV2 verification']);
$raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_HTTP_CODE);$type=curl_getinfo($c,CURLINFO_CONTENT_TYPE);$errno=curl_errno($c);$error=curl_error($c);curl_close($c);
if(is_string($raw))file_put_contents('/root/staff-wallet-test/install.html',$raw);
echo json_encode(['host'=>'pass.walletwallet.dev','status'=>$status,'type'=>$type,'bytes'=>strlen((string)$raw),'curl_errno'=>$errno,'transport_error'=>$error,'contains_apple_wallet'=>is_string($raw)&&stripos($raw,'Apple Wallet')!==false,'contains_google_wallet'=>is_string($raw)&&stripos($raw,'Google Wallet')!==false],JSON_UNESCAPED_UNICODE).PHP_EOL;

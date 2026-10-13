<?php
declare(strict_types=1);
$key = trim(file_get_contents('/tmp/staff-wallet-key'));
$directory = '/root/staff-wallet-test';
if (!is_dir($directory)) mkdir($directory, 0700, true);
function callWallet(string $method, string $route, ?array $body = null): array {
    global $key;
    $handle = curl_init('https://api.walletwallet.dev'.$route);
    curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>35, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key, 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($handle); $status = curl_getinfo($handle,CURLINFO_HTTP_CODE); $errno = curl_errno($handle); curl_close($handle);
    if ($raw === false) throw new RuntimeException('Transport failed (curl '.$errno.'); no automatic retry');
    $data = json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if ($status !== 200) throw new RuntimeException('API HTTP '.$status.': '.substr((string)($data['error']??'request rejected'),0,160));
    return $data;
}
$usage = callWallet('GET','/api/auth/usage');
echo json_encode(['usage_before'=>$usage],JSON_UNESCAPED_UNICODE).PHP_EOL;
$responseFile = $directory.'/response.json';
if (!is_file($responseFile)) {
    $body = ['barcodeValue'=>'999900001','barcodeFormat'=>'QR','barcodeAltText'=>'999900001','logoText'=>'ПРОПУСК','organizationName'=>'ОГАУ ДО"СШ ВВЕ"','colorPreset'=>'blue','credentials'=>'platform','sharingProhibited'=>true,'primaryFields'=>[['label'=>'ID','value'=>'999900001']]];
    $response = callWallet('POST','/api/passes',$body);
    file_put_contents($responseFile,json_encode($response,JSON_THROW_ON_ERROR)); chmod($responseFile,0600);
} else $response = json_decode(file_get_contents($responseFile),true,512,JSON_THROW_ON_ERROR);
foreach (['serialNumber','shareUrl','googleSaveUrl','applePass'] as $field) if (empty($response[$field])) throw new RuntimeException('Missing response field '.$field);
$bytes=base64_decode($response['applePass'],true); if ($bytes===false||!str_starts_with($bytes,'PK')) throw new RuntimeException('Invalid pkpass');
file_put_contents($directory.'/test.pkpass',$bytes); chmod($directory.'/test.pkpass',0600);
$zip=new ZipArchive(); if ($zip->open($directory.'/test.pkpass')!==true) throw new RuntimeException('Invalid ZIP');
$pass=json_decode($zip->getFromName('pass.json'),true,512,JSON_THROW_ON_ERROR);
$manifest=json_decode($zip->getFromName('manifest.json'),true,512,JSON_THROW_ON_ERROR);$manifestOk=true;
foreach ($manifest as $name=>$hash) if (sha1($zip->getFromName($name))!==$hash) $manifestOk=false;
$barcode=($pass['barcodes'][0]??$pass['barcode']??[])['message']??null;
echo json_encode(['test_id'=>'999900001','apple_pkpass_bytes'=>strlen($bytes),'barcode_matches'=>$barcode==='999900001','signature_present'=>$zip->locateName('signature')!==false,'manifest_hashes_valid'=>$manifestOk,'share_host'=>parse_url($response['shareUrl'],PHP_URL_HOST),'google_link_available'=>str_starts_with($response['googleSaveUrl'],'https://'),'expires'=>isset($pass['expirationDate']),'usage_after'=>callWallet('GET','/api/auth/usage')],JSON_UNESCAPED_UNICODE).PHP_EOL;
$zip->close();

<?php
declare(strict_types=1);
$db=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$file=trim(file_get_contents('/etc/staff-v2/last-backup'));
if(!preg_match('~^/var/backups/staff-v2/staff-v2-[0-9-]+\.sql\.gz$~D',$file)||!is_file($file))throw new RuntimeException('Invalid backup path');
$target='staff_restore_verification';
if($db->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$target'")->fetchColumn())throw new RuntimeException('Restore test database already exists');
$db->exec("CREATE DATABASE $target CHARACTER SET utf8mb4");
$process=proc_open(['mariadb',$target],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot launch restore');
$gzip=gzopen($file,'rb');while(!gzeof($gzip)){fwrite($pipes[0],gzread($gzip,65536));}gzclose($gzip);fclose($pipes[0]);
$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
if($status!==0)throw new RuntimeException('Restore failed '.$status.' '.substr($error,0,120));
$checks=[];
$tables=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema='staff_v2' AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as$table){if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Unsafe table name');$live=(int)$db->query("SELECT COUNT(*) FROM staff_v2.`$table`")->fetchColumn();$restored=(int)$db->query("SELECT COUNT(*) FROM $target.`$table`")->fetchColumn();if($live!==$restored)throw new RuntimeException('Restored count mismatch '.$table);$checks[$table]=$restored;}
$walletChecks=[];
foreach(['wallet_passes','wallet_pass_history']as$table){
    if(!in_array($table,$tables,true))continue;
    $columns='employee_id,serial_number,state,OCTET_LENGTH(apple_pass) AS bytes,SHA2(apple_pass,256) AS digest';
    $live=$db->query("SELECT $columns FROM staff_v2.$table ORDER BY employee_id,serial_number")->fetchAll(PDO::FETCH_ASSOC);
    $restored=$db->query("SELECT $columns FROM $target.$table ORDER BY employee_id,serial_number")->fetchAll(PDO::FETCH_ASSOC);
    if($live!==$restored)throw new RuntimeException('Restored Wallet backup differs');
    $walletChecks[$table]=['rows'=>count($restored),'binary_digest_verified'=>true];
}
$db->exec("DROP DATABASE $target");
$download=tempnam('/tmp','staff-v2-verified-');
if($download===false || !copy($file,$download))throw new RuntimeException('Cannot prepare verified backup download');
chmod($download,0600);
chown($download,'codex-maint');
echo json_encode(['restore_verified'=>true,'counts'=>$checks,'wallet'=>$walletChecks,'download_path'=>$download],JSON_UNESCAPED_UNICODE).PHP_EOL;

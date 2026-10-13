<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Run as root');
$project='/opt/staff-v2/app';
$secrets=json_decode(file_get_contents('/tmp/staff-v2-secrets.json'),true,512,JSON_THROW_ON_ERROR);
$root=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE IF NOT EXISTS staff_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$quoted=$root->quote($secrets['db_password']);
$root->exec("CREATE USER IF NOT EXISTS 'staffv2'@'127.0.0.1' IDENTIFIED BY ".$quoted);
$root->exec("ALTER USER 'staffv2'@'127.0.0.1' IDENTIFIED BY ".$quoted);
$root->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON staff_v2.* TO 'staffv2'@'127.0.0.1'");
$root->exec('USE staff_v2');
$schema=file_get_contents($project.'/migrations/schema.sql');
foreach(preg_split('/;\s*(?:\r?\n|$)/',$schema) as $statement)if(trim($statement)!=='')$root->exec($statement);
$hash=password_hash($secrets['admin_password'],PASSWORD_ARGON2ID,['memory_cost'=>32768,'time_cost'=>3,'threads'=>1]);
$pinHash=password_hash($secrets['enrollment_pin'],PASSWORD_ARGON2ID,['memory_cost'=>16384,'time_cost'=>2,'threads'=>1]);
$stmt=$root->prepare('INSERT IGNORE INTO employees (id,surname,given_name,patronymic,position,active,is_service,admin_role,password_hash,enrollment_pin_hash,auth_version,version,created_at,updated_at) VALUES (0,?,?,?,?,1,1,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
$stmt->execute(['Технический','Администратор','','Служебный вход','ADMIN',$hash,$pinHash,1]);
require $project.'/vendor/autoload.php';
date_default_timezone_set('Asia/Sakhalin');
foreach(App\Service\Settings::DEFAULTS as $key=>$value){if($key==='history_start')$value=date('Y-m-d');$stmt=$root->prepare('INSERT IGNORE INTO app_settings(setting_key,value_json,version,updated_at) VALUES (?,?,1,UTC_TIMESTAMP())');$stmt->execute([$key,json_encode($value,JSON_UNESCAPED_UNICODE)]);}
$ownerHash=password_hash($secrets['owner_password'],PASSWORD_ARGON2ID,['memory_cost'=>32768,'time_cost'=>3,'threads'=>1]);
$env="APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET='".$secrets['app_secret']."'\nDATABASE_URL='mysql://staffv2:".$secrets['db_password']."@127.0.0.1:3306/staff_v2?charset=utf8mb4'\nBACKDOOR_LOGIN='".$secrets['owner_login']."'\nBACKDOOR_PASSWORD_HASH='".$ownerHash."'\nPRIVATE_PATH='".$secrets['private_path']."'\n";
file_put_contents($project.'/.env.local',$env);chown($project.'/.env.local','root');chgrp($project.'/.env.local','www-data');chmod($project.'/.env.local',0640);
unlink('/tmp/staff-v2-secrets.json');
echo "Schema, isolated credentials and technical ADMIN installed.\n";

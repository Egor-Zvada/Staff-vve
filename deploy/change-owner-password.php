<?php
declare(strict_types=1);

// Owner credentials are read only from a private operator-supplied JSON file.
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Root CLI required');
$input=$argv[1]??'';
if($input===''||!is_file($input)||is_link($input))throw new RuntimeException('Private credentials file required');
chmod($input,0600);
$data=json_decode(file_get_contents($input),true,32,JSON_THROW_ON_ERROR);
$password=$data['password']??null;
if(!is_string($password)||strlen($password)<8||strlen($password)>256)throw new RuntimeException('Password length must be 8–256 bytes');
$path='/opt/staff-v2/app/.env.local';$metadata=stat($path);$text=file_get_contents($path);
if($text===false||!preg_match('/^BACKDOOR_PASSWORD_HASH=/m',$text))throw new RuntimeException('Owner configuration missing');
$hash=password_hash($password,PASSWORD_DEFAULT);
$text=preg_replace_callback('/^BACKDOOR_PASSWORD_HASH=.*$/m',static fn():string=>"BACKDOOR_PASSWORD_HASH='".$hash."'",$text);
$temporary=tempnam(dirname($path),'.owner-');
if($temporary===false)throw new RuntimeException('Cannot prepare configuration');
try {
    if(file_put_contents($temporary,$text,LOCK_EX)!==strlen($text))throw new RuntimeException('Cannot write configuration');
    chmod($temporary,$metadata['mode']&0777);chown($temporary,$metadata['uid']);chgrp($temporary,$metadata['gid']);
    if(!rename($temporary,$path))throw new RuntimeException('Cannot activate configuration');
} finally { if(is_file($temporary))unlink($temporary); }
unlink($input);
echo json_encode(['owner_password_updated'=>true,'old_owner_sessions_invalidated'=>true,'ordinary_admin_accounts_unchanged'=>true]).PHP_EOL;

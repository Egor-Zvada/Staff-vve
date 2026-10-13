<?php
declare(strict_types=1);
$path='/opt/staff-v2/app/.env.local';
$key=trim(file_get_contents('/tmp/staff-wallet-key'));
if(!preg_match('/^ww_live_[a-f0-9]{32}$/D',$key))throw new RuntimeException('Invalid key format');
$text=file_get_contents($path);
$text=preg_replace('/^WALLETWALLET_API_KEY=.*\R?/m','',$text);
file_put_contents($path,rtrim($text)."\nWALLETWALLET_API_KEY='".$key."'\n");
chmod($path,0640);
unlink('/tmp/staff-wallet-key');
echo 'Wallet provider key configured in server environment.'.PHP_EOL;

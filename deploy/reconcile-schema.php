<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Run as root');
$root=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('USE staff_v2');
$schema=file_get_contents('/opt/staff-v2/app/migrations/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+) \((.*?)\) ENGINE=/s',$schema,$tables,PREG_SET_ORDER);
foreach($tables as $table){
    $name=$table[1];$existing=$root->query('SHOW COLUMNS FROM `'.$name.'`')->fetchAll(PDO::FETCH_COLUMN);
    foreach(explode("\n",$table[2]) as $line){$line=trim($line);if(!preg_match('/^([a-z][a-z_0-9]*)\s+(INT|BIGINT|VARCHAR|DATE|DATETIME|TIME|TINYINT|JSON|ENUM|TEXT|CHAR|DECIMAL)\b/i',$line,$match))continue;if(in_array(strtoupper($match[1]),['PRIMARY','KEY','CONSTRAINT','FOREIGN','UNIQUE'],true)||in_array($match[1],$existing,true))continue;
        $root->exec('ALTER TABLE `'.$name.'` ADD COLUMN '.rtrim($line,','));echo 'Added missing initial column '.$name.'.'.$match[1]."\n";
    }
}

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Run as root');
$project=$argv[2]??'/opt/staff-v2/app';
if(!preg_match('~^/opt/staff-v2/(?:app|qa-[a-z0-9-]+)$~D',$project)||realpath($project)!==$project)throw new RuntimeException('Unexpected QA project directory');
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
$root=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($root->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='staff_v2_qa'")->fetchColumn())throw new RuntimeException('QA database must be empty; existing test data retained');
$credentialsFile=$argv[1]??'/tmp/staff-v2-qa-access.json';
if(!is_file($credentialsFile)||is_link($credentialsFile))throw new RuntimeException('Private QA credentials JSON required');
chmod($credentialsFile,0600);$qa=json_decode(file_get_contents($credentialsFile),true,32,JSON_THROW_ON_ERROR);
if(strlen((string)($qa['admin_password']??''))<8||!preg_match('/^[0-9]{4,12}$/D',(string)($qa['enrollment_pin']??'')))throw new RuntimeException('Invalid QA credentials');
$root->exec('CREATE DATABASE IF NOT EXISTS staff_v2_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON staff_v2_qa.* TO 'staffv2'@'127.0.0.1'");
$root->exec('USE staff_v2_qa');
foreach(preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($project.'/migrations/schema.sql')) as $statement)if(trim($statement)!=='')$root->exec($statement);
$statement=$root->prepare("INSERT INTO employees (id,pass_code,surname,given_name,active,is_service,admin_role,password_hash,enrollment_pin_hash,started_on) VALUES (0,'000','Проверочный','Администратор',1,1,'ADMIN',?,?,'1970-01-01')");
$statement->execute([password_hash($qa['admin_password'],PASSWORD_DEFAULT),password_hash($qa['enrollment_pin'],PASSWORD_DEFAULT)]);
foreach(['app_settings','schedule_groups','schedule_group_versions'] as $table){
    $columns=$root->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA='staff_v2' AND TABLE_NAME=".$root->quote($table)." ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    $list=implode(',',array_map(static fn($name)=>'`'.$name.'`',$columns));
    $select=implode(',',array_map(static fn($name)=>$name==='actor_employee_id'?'NULL':'`'.$name.'`',$columns));
    $root->exec('INSERT IGNORE INTO '.$table.' ('.$list.') SELECT '.$select.' FROM staff_v2.'.$table);
}
$root->exec('UPDATE employee_id_sequence SET next_id=100001 WHERE id=1');
unlink($credentialsFile);
$url=str_replace('/staff_v2?','/staff_v2_qa?',$_SERVER['DATABASE_URL']);
$config='server { listen 8443 ssl; server_name staff.vve.local; root '.$project.'/public; ssl_certificate /etc/staff-v2/server.crt; ssl_certificate_key /etc/staff-v2/server.key; client_max_body_size 10m; location / { try_files $uri /index.php$is_args$args; } location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_param HTTPS on; fastcgi_param HTTP_HOST "$host:8443"; fastcgi_param APP_ENV qa; fastcgi_param APP_DEBUG 0; fastcgi_param DATABASE_URL "'.$url.'"; fastcgi_pass unix:/run/php/staff-v2.sock; } location ~ \.php$ { return 404; } location ~ /\. { deny all; } }';
file_put_contents('/etc/nginx/sites-available/staff-v2-qa',$config);chmod('/etc/nginx/sites-available/staff-v2-qa',0600);
if(!is_link('/etc/nginx/sites-enabled/staff-v2-qa'))symlink('/etc/nginx/sites-available/staff-v2-qa','/etc/nginx/sites-enabled/staff-v2-qa');
echo "Isolated QA database and temporary 8443 listener prepared.\n";

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||posix_geteuid()!==0)throw new RuntimeException('Run as root');
$project='/opt/staff-v2/app';require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
$root=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE IF NOT EXISTS staff_v2_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON staff_v2_qa.* TO 'staffv2'@'127.0.0.1'");
$root->exec('USE staff_v2_qa');
foreach(preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($project.'/migrations/schema.sql')) as $statement)if(trim($statement)!=='')$root->exec($statement);
foreach(['employees','app_settings','schedule_groups','schedule_group_versions','employee_id_sequence'] as $table){
    $columns=$root->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA='staff_v2' AND TABLE_NAME=".$root->quote($table)." ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    $list=implode(',',array_map(static fn($name)=>'`'.$name.'`',$columns));
    $root->exec('INSERT IGNORE INTO '.$table.' ('.$list.') SELECT '.$list.' FROM staff_v2.'.$table);
}
$url=str_replace('/staff_v2?','/staff_v2_qa?',$_SERVER['DATABASE_URL']);
$config='server { listen 8443 ssl; server_name staff.vve.local; root '.$project.'/public; ssl_certificate /etc/staff-v2/server.crt; ssl_certificate_key /etc/staff-v2/server.key; client_max_body_size 10m; location / { try_files $uri /index.php$is_args$args; } location = /index.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/index.php; fastcgi_param HTTPS on; fastcgi_param HTTP_HOST "$host:8443"; fastcgi_param APP_ENV qa; fastcgi_param APP_DEBUG 0; fastcgi_param DATABASE_URL "'.$url.'"; fastcgi_pass unix:/run/php/staff-v2.sock; } location ~ \.php$ { return 404; } location ~ /\. { deny all; } }';
file_put_contents('/etc/nginx/sites-available/staff-v2-qa',$config);chmod('/etc/nginx/sites-available/staff-v2-qa',0600);
if(!is_link('/etc/nginx/sites-enabled/staff-v2-qa'))symlink('/etc/nginx/sites-available/staff-v2-qa','/etc/nginx/sites-enabled/staff-v2-qa');
echo "Isolated QA database and temporary 8443 listener prepared.\n";

<?php
declare(strict_types=1);
namespace App\Service;
use App\Infrastructure\Database;
final class Settings
{
    private ?array $cache=null;
    public const DEFAULTS=['org_name'=>'Школа','qr_quiet_ms'=>100,'auto_start'=>'05:00','auto_cutover'=>'12:00','night_event'=>'arrival','duplicate_seconds'=>3,'manual_lag_seconds'=>120,'extra_events'=>true,'missing_after_minutes'=>15,'close_after_minutes'=>30,'report_max_days'=>366,'history_start'=>null,'theme'=>'light','badge_orientation'=>'portrait','badge_position'=>true,'backup_external_path'=>''];
    public function __construct(private Database $database){}
    public function all():array{if($this->cache===null){$this->cache=self::DEFAULTS;foreach($this->database->all('SELECT setting_key,value_json FROM app_settings') as $row)$this->cache[$row['setting_key']]=json_decode($row['value_json'],true);}return $this->cache;}
    public function get(string $key,mixed $default=null):mixed{return $this->all()[$key]??$default;}
    public function set(string $key,mixed $value,mixed $actor=null):void
    {
        if(!array_key_exists($key,self::DEFAULTS))throw new \InvalidArgumentException('Неизвестная настройка');
        $before=$this->get($key);$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->database->exec('INSERT INTO app_settings(setting_key,value_json,version,updated_at) VALUES (?,?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),version=version+1,updated_at=UTC_TIMESTAMP()',[$key,$json]);
        $actorId=is_array($actor)?($actor['id']??null):$actor;
        $this->database->insert('audit_log',['actor_employee_id'=>$actorId,'action'=>'settings.update','entity_type'=>'settings','entity_id'=>$key,'before_json'=>json_encode($before,JSON_UNESCAPED_UNICODE),'after_json'=>$json,'created_at'=>gmdate('Y-m-d H:i:s'),'private'=>0]);$this->cache=null;
    }
}

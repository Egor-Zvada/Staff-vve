<?php
declare(strict_types=1);
namespace App\Infrastructure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
final class Database
{
    public readonly Connection $db;
    public function __construct(string $url)
    {
        $params=(new \Doctrine\DBAL\Tools\DsnParser(['mysql'=>'pdo_mysql']))->parse($url);
        $params['charset']??='utf8mb4';
        $this->db=DriverManager::getConnection($params);
        if($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform)$this->db->executeStatement("SET time_zone = '+00:00'");
    }
    public function all(string $sql,array $params=[]):array{return $this->db->fetchAllAssociative($sql,$params);}
    public function one(string $sql,array $params=[]):?array{return $this->db->fetchAssociative($sql,$params)?:null;}
    public function scalar(string $sql,array $params=[]):mixed{return $this->db->fetchOne($sql,$params);}
    public function exec(string $sql,array $params=[]):int{return $this->db->executeStatement($sql,$params);}
    public function insert(string $table,array $data):int{return $this->db->insert($table,$data);}
    public function transaction(callable $fn):mixed{return $this->db->transactional(fn()=> $fn());}
}

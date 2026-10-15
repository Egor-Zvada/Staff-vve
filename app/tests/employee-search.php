<?php
declare(strict_types=1);

/** MariaDB regression: STAFF_TEST_DSN must name an EMPTY disposable *_test database. */
use App\Infrastructure\Database;
use App\Service\EmployeeService;
use App\Service\Settings;

$dsn = (string) getenv('STAFF_TEST_DSN');
if ($dsn === '') {
    throw new RuntimeException('Set STAFF_TEST_DSN to an EMPTY disposable MariaDB database ending in _test; production DATABASE_URL is never used.');
}
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$db = new Database($dsn);
$databaseName = (string) $db->scalar('SELECT DATABASE()');
if (!preg_match('/_test$/D', $databaseName) || (int) $db->scalar('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()') !== 0) {
    throw new RuntimeException('STAFF_TEST_DSN must select an EMPTY disposable database ending in _test.');
}
if (!str_contains((string) $db->scalar('SELECT VERSION()'), 'MariaDB')) {
    throw new RuntimeException('This collation regression must run against MariaDB.');
}
$schema = preg_replace('/^\s*--.*$/m', '', file_get_contents($root.'/migrations/schema.sql'));
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema))) as $statement) {
    $db->exec($statement);
}
$db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
foreach ([
    ['id'=>0, 'pass_code'=>'000', 'surname'=>'Иванов', 'given_name'=>'Служебный', 'is_service'=>1],
    ['id'=>7, 'pass_code'=>'007', 'surname'=>'Иванов', 'given_name'=>'Иван', 'patronymic'=>'Иванович', 'position'=>'Тренер'],
    ['id'=>8, 'pass_code'=>'VVE-ABCD-EF12-XYZ9', 'surname'=>'Петров', 'given_name'=>'Пётр', 'position'=>'Инструктор', 'active'=>0],
    ['id'=>9, 'pass_code'=>'009', 'surname'=>'Яковлева', 'given_name'=>'Елена', 'position'=>'Старший тренер'],
    ['id'=>10, 'pass_code'=>'010', 'surname'=>"О'Нил", 'given_name'=>'Анна', 'position'=>'Методист'],
] as $employee) {
    $db->insert('employees', $employee);
}
$db->insert('schedule_groups', ['id'=>1, 'name'=>'Офис']);
$db->insert('schedule_assignments', ['employee_id'=>7, 'group_id'=>1, 'valid_from'=>'1970-01-01']);
$service = new EmployeeService($db, new Settings($db));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
};
$ids = static fn(array $rows): array => array_map('intval', array_column($rows, 'id'));
$assert($db->scalar("SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees' AND COLUMN_NAME='pass_code'") === 'ascii_bin', 'The fixture must retain the production ASCII pass-code collation.');
$reproduced = false;
try {
    $db->all('SELECT id FROM employees WHERE pass_code LIKE ?', ['%Иванов%']);
} catch (Throwable $error) {
    $reproduced = str_contains($error->getMessage(), 'Illegal mix of collations') || str_contains($error->getMessage(), '1267');
}
$assert($reproduced, 'The original Cyrillic-to-ASCII LIKE failure was not reproduced on MariaDB.');

foreach ([
    [['q'=>'Иванов'], [7]],
    [['q'=>'иванов'], [7]],
    [['q'=>'Иван Иванович'], [7]],
    [['q'=>'  Иванов  '], [7]],
    [['q'=>'тренер'], [7,9]],
    [['q'=>'Старший'], [9]],
    [['q'=>'007'], [7]],
    [['q'=>'00'], [7,9]],
    [['q'=>'vve-abcd'], [8]],
    [['q'=>'ef12'], [8]],
    [['q'=>'Петров','active'=>'0'], [8]],
    [['q'=>'Петров','active'=>'1'], []],
    [['q'=>'тренер','group_id'=>1], [7]],
    [['q'=>'Яковлева','group_id'=>1], []],
    [['q'=>"О'Нил"], [10]],
    [['q'=>"' OR 1=1 --"], []],
    [['q'=>'😀'], []],
    [['q'=>'Несуществующий'], []],
] as [$filters, $expected]) {
    $assert($ids($service->list($filters)) === $expected, 'Search list returned incorrect employee IDs for '.json_encode($filters, JSON_UNESCAPED_UNICODE));
    $assert($service->count($filters) === count($expected), 'Search count disagrees with the list for '.json_encode($filters, JSON_UNESCAPED_UNICODE));
}
$assert($ids($service->list(['q'=>'тренер', 'limit'=>1, 'offset'=>1])) === [9], 'Search pagination lost or reordered a Cyrillic result.');
$assert($service->count(['q'=>'тренер', 'limit'=>1, 'offset'=>1]) === 2, 'Search total was incorrectly limited to one page.');
$assert($ids($service->list()) === [7,8,9,10], 'Search changed the unfiltered registry or exposed a service account.');
echo "Employee search (MariaDB): {$checks} checks passed.\n";

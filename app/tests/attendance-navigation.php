<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Service/ScheduleService.php';
require dirname(__DIR__).'/src/Service/AttendanceDayNavigation.php';

use App\Service\AttendanceDayNavigation;

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) { throw new RuntimeException($message); }
    $passed++;
};
$bad = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (DomainException $exception) { $assert($exception->getCode() === 400, $message); return; }
    throw new RuntimeException($message);
};
$navigation = AttendanceDayNavigation::build('2026-10-10', '2026-10-15', '', '2026-10-15');
$assert($navigation['day'] === '2026-10-15' && $navigation['index'] === 1 && $navigation['count'] === 6, 'Latest day is first when opening a completed range');
$assert(array_column($navigation['dates'], 'date') === ['2026-10-15','2026-10-14','2026-10-13','2026-10-12','2026-10-11','2026-10-10'], 'Date picker is newest first');
$assert($navigation['newer'] === null && $navigation['older'] === '2026-10-14', 'Newest edge has only an older neighbour');
$navigation = AttendanceDayNavigation::build('2026-10-10', '2026-10-20', '', '2026-10-15');
$assert($navigation['day'] === '2026-10-15' && $navigation['today'] === '2026-10-15', 'Range including the future opens today');
$navigation = AttendanceDayNavigation::build('2026-10-10', '2026-10-15', '', '2026-10-25');
$assert($navigation['day'] === '2026-10-15' && $navigation['today'] === null, 'Historical range opens its latest date');
$navigation = AttendanceDayNavigation::build('2026-11-01', '2026-11-03', '', '2026-10-15');
$assert($navigation['day'] === '2026-11-01' && $navigation['older'] === null && $navigation['newer'] === '2026-11-02', 'Future-only range opens its closest date');
$navigation = AttendanceDayNavigation::build('2026-10-10', '2026-10-15', '2026-10-12', '2026-10-15');
$assert($navigation['day'] === '2026-10-12' && $navigation['older'] === '2026-10-11' && $navigation['newer'] === '2026-10-13' && $navigation['index'] === 4, 'Explicit selected date has both neighbours');
$navigation = AttendanceDayNavigation::build('2026-10-10', '2026-10-15', '2026-09-01', '2026-10-15');
$assert($navigation['day'] === '2026-10-15', 'Stale selection from another range is safely reset');
$navigation = AttendanceDayNavigation::build('2028-02-29', '2028-03-01', '2028-03-01', '2028-03-01');
$assert($navigation['older'] === '2028-02-29' && $navigation['count'] === 2, 'Leap-day boundary is preserved');
$navigation = AttendanceDayNavigation::build('2026-10-15', '2026-10-15', '', '2026-10-15');
$assert($navigation['older'] === null && $navigation['newer'] === null && $navigation['count'] === 1, 'One-day range has no links outside its bounds');
$bad(static fn () => AttendanceDayNavigation::build('2026-02-30', '2026-03-01', '', '2026-03-01'), 'Malformed range is rejected');
$bad(static fn () => AttendanceDayNavigation::build('2026-10-15', '2026-10-10', '', '2026-10-15'), 'Reversed range is rejected');
$bad(static fn () => AttendanceDayNavigation::build('2026-10-10', '2026-10-15', '2026-02-30', '2026-10-15'), 'Malformed explicit day is rejected');
$bad(static fn () => AttendanceDayNavigation::build('2026-01-01', '2026-01-11', '', '2026-01-01', 10), 'Configured range limit is honoured before attendance loading');
$navigation = AttendanceDayNavigation::build('2026-01-01', '2026-01-10', '', '2026-01-01', 10);
$assert($navigation['count'] === 10, 'Inclusive range limit accepts the exact boundary');
echo "Attendance navigation: {$passed} checks passed.\n";

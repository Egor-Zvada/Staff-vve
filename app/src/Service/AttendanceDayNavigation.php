<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** Small date-only navigation model; attendance itself is loaded for one day. */
final class AttendanceDayNavigation
{
    public static function build(string $from, string $to, string $requestedDay, string $today, int $maxDays = 366): array
    {
        foreach ([$from, $to, $today] as $date) { ScheduleService::validateDate($date); }
        if ($to < $from) { throw new DomainException('Конец периода раньше начала.', 400); }
        $timezone = new DateTimeZone(ScheduleService::TIMEZONE);
        $start = new DateTimeImmutable($from, $timezone);
        $end = new DateTimeImmutable($to, $timezone);
        $count = (int) $start->diff($end)->days + 1;
        if ($count > max(1, min(3660, $maxDays))) { throw new DomainException('Слишком большой период; разбейте его на части.', 400); }
        $defaultDay = min($to, max($from, $today));
        $day = $requestedDay === '' ? $defaultDay : ScheduleService::validateDate($requestedDay);
        if ($day < $from || $day > $to) { $day = $defaultDay; }
        $selected = new DateTimeImmutable($day, $timezone);
        $dates = [];
        for ($date = $end; $date >= $start; $date = $date->modify('-1 day')) {
            $dates[] = ['date' => $date->format('Y-m-d'), 'label' => $date->format('d.m.Y')];
        }
        return [
            'day' => $day, 'label' => $selected->format('d.m.Y'), 'dates' => $dates, 'count' => $count,
            'index' => (int) $selected->diff($end)->days + 1,
            'older' => $day > $from ? $selected->modify('-1 day')->format('Y-m-d') : null,
            'newer' => $day < $to ? $selected->modify('+1 day')->format('Y-m-d') : null,
            'latest' => $to, 'today' => $today >= $from && $today <= $to ? $today : null,
        ];
    }
}

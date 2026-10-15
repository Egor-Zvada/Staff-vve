<?php
declare(strict_types=1);

namespace App\Service;

/** Chart totals and drill-down lists are built together from the same public day rows. */
final class DashboardChartService
{
    public static function build(array $rows, array $dates, string $selectedDate): array
    {
        $days = [];
        foreach ($dates as $date) {
            $days[$date] = ['date'=>$date, 'expected'=>0, 'arrived'=>0, 'late'=>0, 'missing'=>0, 'pending'=>0, 'cohorts'=>['on_time'=>[], 'late'=>[], 'missing'=>[]]];
        }
        $groups = $hours = [];
        foreach ($rows as $row) {
            $date = (string) ($row['work_date'] ?? $row['date'] ?? '');
            if (!isset($days[$date]) || empty($row['is_working']) || !empty($row['is_guard']) || !empty($row['on_leave'])) { continue; }
            $flags = array_values(array_filter($row['flags'] ?? [], static fn (array $flag): bool => empty($flag['suppressed']) && empty($flag['private'])));
            $kinds = array_column($flags, 'kind');
            $arrivalChanges = array_filter($row['changes'] ?? [], static fn (array $change): bool => ($change['event_type'] ?? '') === 'arrival');
            $missing = (bool) array_intersect($kinds, ['MISSING_ARRIVAL', 'NO_MARKS']);
            $arrival = $row['raw_values']['arrival'] ?? ((!$arrivalChanges && !$missing) ? ($row['values']['arrival'] ?? null) : null);
            $groupName = trim((string) ($row['group_name'] ?? '')) ?: 'Без группы';
            $groupKey = (string) ($row['plan']['group_id'] ?? $groupName);
            $groups[$groupKey] ??= ['name'=>$groupName, 'expected'=>0, 'arrived'=>0, 'late'=>0, 'cohort'=>[]];
            ++$days[$date]['expected'];
            ++$groups[$groupKey]['expected'];
            $person = ['employee_id'=>(int) ($row['employee_id'] ?? $row['id'] ?? 0), 'name'=>(string) ($row['full_name'] ?? $row['name'] ?? ''), 'date'=>$date, 'arrival'=>$arrival];
            if ($missing) {
                ++$days[$date]['missing'];
                $person['arrival'] = null;
                $days[$date]['cohorts']['missing'][] = $person;
                continue;
            }
            if ($arrival === null) { ++$days[$date]['pending']; continue; }
            ++$days[$date]['arrived'];
            ++$groups[$groupKey]['arrived'];
            $late = in_array('LATE_ARRIVAL', $kinds, true);
            $days[$date]['cohorts'][$late ? 'late' : 'on_time'][] = $person;
            if ($late) {
                ++$days[$date]['late'];
                ++$groups[$groupKey]['late'];
                $groups[$groupKey]['cohort'][] = $person;
            }
            if ($date === $selectedDate && preg_match('/^(\d{2}):[0-5]\d/', (string) $arrival, $match)) {
                $hour = (int) $match[1];
                if ($hour >= 0 && $hour <= 23) {
                    $hours[$hour] ??= ['hour'=>$hour, 'label'=>sprintf('%02d:00–%02d:59', $hour, $hour), 'count'=>0, 'cohort'=>[]];
                    ++$hours[$hour]['count'];
                    $hours[$hour]['cohort'][] = $person;
                }
            }
        }
        $sort = static function (array &$people): void {
            usort($people, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: strcmp($a['name'], $b['name']) ?: ($a['employee_id'] <=> $b['employee_id']));
        };
        foreach ($days as &$day) { foreach ($day['cohorts'] as &$people) { $sort($people); } unset($people); } unset($day);
        foreach ($groups as &$group) { $sort($group['cohort']); $group['late_percent'] = $group['arrived'] > 0 ? round(100 * $group['late'] / $group['arrived'], 1) : null; } unset($group);
        usort($groups, static fn (array $a, array $b): int => ($b['late_percent'] ?? -1) <=> ($a['late_percent'] ?? -1) ?: strcmp($a['name'], $b['name']));
        ksort($hours);
        foreach ($hours as &$hour) { $sort($hour['cohort']); } unset($hour);
        return ['chart'=>array_values($days), 'group_chart'=>array_values($groups), 'hour_chart'=>array_values($hours)];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The manager's list of one of their sites on a date (design Part 2): the site's workers on that date, then the manager's engineers,
 * who float between the manager's sites and so show the site they were stored at. No pay figures: transport, outside_* and advance_amount stay out.
 */
final class SiteDay
{
    private const DAY = ['state', 'version', 'site_id', 'leave_code', 'in_time', 'out_time', 'note'];

    public function __construct(private readonly EmployeeRoster $roster, private readonly EffectiveDays $days, private readonly SiteService $sites) {}

    /** @return array{site: array{id: string, name_ar: string, name_en: ?string}, date: string, today: string, rows: list<array<string, mixed>>} */
    public function read(string $siteId, string $date, string $managerId): array
    {
        if (! in_array($siteId, $this->sites->idsManagedBy($managerId), true)) {
            throw new ModelNotFoundException;
        }
        $today = EffectiveDays::today();
        if ($date > $today) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        $site = Site::findOrFail($siteId);

        $listed = [
            ...array_map(fn (array $e) => [$e, 'workers'], $this->roster->onSite($siteId, $date)),
            ...array_map(fn (array $e) => [$e, 'engineers'], $this->roster->engineersOf($managerId, $date)),
        ];
        // Both lists hold only those employed on $date, so each has a type on it; one period read per type present.
        $byType = [];
        foreach ($listed as [$e]) {
            $byType[Employment::typeOn($e, $date)?->value][] = $e;
        }
        $days = [];
        foreach ($byType as $type => $employees) {
            $days += $this->days->forPeriod(Period::containing(EmployeeType::from((string) $type), $date), $employees);
        }

        return [
            'site' => ['id' => (string) $site->getKey(), 'name_ar' => (string) $site->name_ar, 'name_en' => $site->name_en],
            'date' => $date,
            'today' => $today,
            'rows' => array_map(function (array $l) use ($days, $date): array {
                [$e, $section] = $l;
                $day = $days[$e['id']][$date];

                return [
                    'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'type' => $day['type']],
                    'section' => $section,
                    'day' => array_intersect_key($day, array_flip(self::DAY)),
                ];
            }, $listed),
        ];
    }
}

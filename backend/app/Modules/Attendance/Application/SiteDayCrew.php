<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * The site manager's changes to today's day-labor crew on one of their sites (owner, 2026-11-04). Only today, Cairo; only day laborers;
 * a laborer homed on another manager's site is HR's to move. Removing someone keeps their record and their past days.
 */
final class SiteDayCrew
{
    public function __construct(private readonly DayCrew $crew, private readonly SiteService $sites) {}

    /**
     * Day laborers matching $search, each with where they are today and whether this site manager may add them here.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $siteId, string $search, string $managerId): array
    {
        $mine = $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        $matches = $this->crew->matching($search, $today);
        $names = Site::whereKey(array_filter(array_column($matches, 'site_id')))->get(['id', 'name_ar', 'name_en'])->keyBy('id');

        return array_map(function (array $m) use ($mine, $siteId, $names): array {
            $site = $m['site_id'] === null ? null : $names[$m['site_id']] ?? null;

            return [
                ...array_diff_key($m, ['site_id' => true]),
                'site' => $site === null ? null : ['id' => (string) $site->id, 'name_ar' => (string) $site->name_ar, 'name_en' => $site->name_en],
                'here' => $m['site_id'] === $siteId,
                'can_add' => $m['site_id'] !== $siteId && ($m['site_id'] === null || in_array($m['site_id'], $mine, true)),
            ];
        }, $matches);
    }

    public function add(string $siteId, string $employeeId, string $managerId): void
    {
        $mine = $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        if (! $this->crew->isLaborerOn($employeeId, $today)) {
            throw new ModelNotFoundException;
        }
        $current = $this->crew->laborerSiteOn($employeeId, $today);
        if ($current !== null && ! in_array($current, $mine, true)) {
            throw ValidationException::withMessages(['employee_id' => __('attendance.crew.on_other_site')]);
        }
        $this->crew->setSite($employeeId, $siteId, $today);
    }

    public function remove(string $siteId, string $employeeId, string $managerId): void
    {
        $this->mySites($siteId, $managerId);
        $today = EffectiveDays::today();
        if ($this->crew->laborerSiteOn($employeeId, $today) !== $siteId) {
            throw new ModelNotFoundException;
        }
        if (AttendanceDay::where(['employee_id' => $employeeId, 'date' => $today])->exists()) {
            throw ValidationException::withMessages(['employee_id' => __('attendance.crew.has_day')]);
        }
        $this->crew->setSite($employeeId, null, $today);
    }

    /**
     * @param  array{name_ar: string, phone?: ?string, daily_wage: string}  $data
     * @return array{id: string, code: int, name_ar: string}
     */
    public function propose(string $siteId, array $data, string $managerId): array
    {
        $this->mySites($siteId, $managerId);

        return $this->crew->propose(['name_ar' => $data['name_ar'], 'phone' => $data['phone'] ?? null], $siteId, EffectiveDays::today(), $data['daily_wage']);
    }

    /** @return list<string> the manager's sites; 404 unless $siteId is one of them */
    private function mySites(string $siteId, string $managerId): array
    {
        $mine = $this->sites->idsManagedBy($managerId);
        if (! in_array($siteId, $mine, true)) {
            throw new ModelNotFoundException;
        }

        return $mine;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Application\DayCrew;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The evening bells (hrms-module-plan, 1c; owner, 2026-11-04), each sent once per (user, date):
 * - missing days: per open period, how many days are still unrecorded up to $date, to each holder of attendance.edit.all;
 * - open days: field work days with an arrival and no departure, to the same holders;
 * - crew changes: the site managers' changes to the day's day-labor crews, to each holder of employee.assign_site.all.
 * Counts only, never names (§9).
 * Counts are cumulative, so the next day's run also covers a day whose run failed.
 */
final class SendMissingDaysAlert implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $date)
    {
        $this->onQueue('maintenance');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(AttendanceGrid $grid, Notifier $notifier, PermissionHolders $holders, DayCrew $crew): void
    {
        $hr = $holders->userIds('attendance.edit.all');
        $groups = array_values(array_filter($grid->missingCounts($this->date), fn (array $g) => $g['missing'] > 0));
        if ($groups !== []) {
            foreach ($hr as $id) {
                $notifier->notifyOnce($id, 'attendance_missing_days', $this->date, ['date' => $this->date, 'groups' => $groups]);
            }
        }

        // Field work days up to $date with an arrival and no departure: only HR completes them once the site manager's window passes.
        $open = AttendanceDay::join('sites', 'sites.id', '=', 'attendance_days.site_id')->where('sites.kind', SiteKind::Work->value)
            ->where('attendance_days.date', '<=', $this->date)->whereNotNull('attendance_days.in_time')->whereNull('attendance_days.out_time')->count();
        if ($open > 0) {
            foreach ($hr as $id) {
                $notifier->notifyOnce($id, 'attendance_open_days', $this->date, ['date' => $this->date, 'count' => $open]);
            }
        }

        // The day's crew changes go to HR in this evening digest (routine attendance, CLAUDE.md).
        $changes = $crew->changesOn($this->date);
        if ($changes['added'] !== [] || $changes['removed'] > 0) {
            $names = Site::whereKey(array_keys($changes['added']))->pluck('name_ar', 'id');
            $sites = array_map(fn (string $site, int $n) => ['site_name_ar' => (string) ($names[$site] ?? ''), 'added' => $n], array_keys($changes['added']), $changes['added']);
            foreach ($holders->userIds('employee.assign_site.all') as $id) {
                $notifier->notifyOnce($id, 'crew_changes', $this->date, ['date' => $this->date, 'sites' => $sites, 'removed' => $changes['removed']]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('attendance.missing_days_alert_failed', ['date' => $this->date, 'error' => $e->getMessage()]);
    }
}

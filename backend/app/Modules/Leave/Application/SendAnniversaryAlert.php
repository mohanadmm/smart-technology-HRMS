<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application;

use App\Modules\Attendance\Domain\Employment;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one-year alert (leave spec, Anniversary alert; calculations §7): on day d, each employee of a monthly type hired one year
 * earlier completes a year of service mid-year, so HR decides the year's entitlement. A run on $date covers every d from six days
 * before it to $date, so the next run makes up a missed or failed one. Sent once per (user, d, employee), dated d, to each holder
 * of leave.decide.all. A Jan 1 hire is left out: on Jan 1 they already have a year, so the full entitlement applies. A Feb 29
 * hire is alerted on Feb 28 of the next year.
 */
final class SendAnniversaryAlert implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** $date and the six days before it. */
    private const WINDOW_DAYS = 7;

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

    public function handle(EmployeeRoster $roster, Notifier $notifier, PermissionHolders $holders): void
    {
        $to = Carbon::parse($this->date)->toImmutable();
        $from = $to->subDays(self::WINDOW_DAYS - 1)->toDateString();
        // A year back without overflow, and the day after for a Feb 29 hire; the exact anniversary is checked below.
        $hired = $roster->hiredBetween(
            Carbon::parse($from)->subYearNoOverflow()->toDateString(),
            $to->subYearNoOverflow()->addDay()->toDateString(),
        );
        $due = [];
        foreach ($hired as $e) {
            $day = Carbon::parse($e['hire_date'])->addYearNoOverflow()->toDateString();
            if ($day >= $from && $day <= $this->date && substr((string) $e['hire_date'], 5) !== '01-01' && Employment::typeOn($e, $day)?->isMonthly() === true) {
                $due[] = [$day, $e];
            }
        }
        if ($due === []) {
            return;
        }
        foreach ($holders->userIds('leave.decide.all') as $id) {
            foreach ($due as [$day, $e]) {
                $notifier->notifyOnce($id, 'leave_anniversary', $day, ['employee_id' => $e['id'], 'hire_date' => $e['hire_date']], $e['id']);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('leave.anniversary_alert_failed', ['date' => $this->date, 'error' => $e->getMessage()]);
    }
}

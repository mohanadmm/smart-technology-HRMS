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
 * The one-year alert (leave spec, Anniversary alert; calculations §7): on $date, each employee of a monthly type hired one year
 * earlier completes a year of service mid-year, so HR decides the year's entitlement. Sent once per (user, date, employee) to
 * each holder of leave.decide.all. A Jan 1 hire is left out: on Jan 1 they already have a year, so the full entitlement applies.
 * A Feb 29 hire is alerted on Feb 28 of the next year.
 */
final class SendAnniversaryAlert implements ShouldQueue
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

    public function handle(EmployeeRoster $roster, Notifier $notifier, PermissionHolders $holders): void
    {
        // A year back without overflow, and the day after for a Feb 29 hire; the exact match is checked below.
        $from = Carbon::parse($this->date)->toImmutable()->subYearNoOverflow();
        $employees = array_filter($roster->hiredBetween($from->toDateString(), $from->addDay()->toDateString()), fn (array $e) => (
            Carbon::parse($e['hire_date'])->addYearNoOverflow()->toDateString() === $this->date
            && substr((string) $e['hire_date'], 5) !== '01-01'
            && Employment::typeOn($e, $this->date)?->isMonthly() === true
        ));
        if ($employees === []) {
            return;
        }
        foreach ($holders->userIds('leave.decide.all') as $id) {
            foreach ($employees as $e) {
                $notifier->notifyOnce($id, 'leave_anniversary', $this->date, ['employee_id' => $e['id'], 'hire_date' => $e['hire_date']], $e['id']);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('leave.anniversary_alert_failed', ['date' => $this->date, 'error' => $e->getMessage()]);
    }
}

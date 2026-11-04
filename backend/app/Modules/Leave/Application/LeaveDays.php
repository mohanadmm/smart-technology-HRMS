<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application;

use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Employees\Domain\EmployeeType;

/** A leave request's counted days (calculations §7): never written to attendance, so a holiday added later changes the count. */
final class LeaveDays
{
    public function __construct(private readonly EffectiveDays $days) {}

    /**
     * The dates from $from to $to the employee is employed as a monthly type, less that type's weekly off and the official holidays.
     *
     * @param  array<string, mixed>  $employee  an EmployeeRoster row
     * @return list<string>
     */
    public function dates(array $employee, string $from, string $to): array
    {
        $off = [];
        $dates = [];
        // offDays() reads any date range of one group, not only a payroll period.
        foreach ((new Period(EmployeeType::Office, $from, $to))->days() as $date) {
            $type = Employment::typeOn($employee, $date);
            if ($type === null || ! $type->isMonthly()) {
                continue;
            }
            $off[$type->value] ??= $this->days->offDays(new Period($type, $from, $to));
            if (! $off[$type->value][$date]) {
                $dates[] = $date;
            }
        }

        return $dates;
    }
}

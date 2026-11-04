<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain;

enum EmployeeType: string
{
    case Office = 'office';
    case Engineer = 'engineer';
    case PermanentWorker = 'permanent_worker';
    case DayLaborer = 'day_laborer';

    /** @return non-empty-list<self> the types paid a monthly salary, which also have a leave balance; a day laborer has neither */
    public static function monthly(): array
    {
        return [self::Office, self::Engineer, self::PermanentWorker];
    }

    public function isMonthly(): bool
    {
        return in_array($this, self::monthly(), true);
    }
}

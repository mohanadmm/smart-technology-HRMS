<?php

declare(strict_types=1);

namespace App\Modules\Sites\Domain;

enum LeaveCode: string
{
    case Friday = 'friday';
    case Saturday = 'saturday';
    case OfficialHoliday = 'official_holiday';
    case Sick = 'sick';
    case Rest = 'rest';
    case Absent = 'absent';
    case Other = 'other';
    case Annual = 'annual';
    case Casual = 'casual';
    case Unpaid = 'unpaid';

    /** Leave an own-scope writer may not store: it goes through a leave request (leave spec, Manager rule). */
    public function needsRequest(): bool
    {
        return in_array($this, [self::Annual, self::Casual, self::Unpaid], true);
    }
}

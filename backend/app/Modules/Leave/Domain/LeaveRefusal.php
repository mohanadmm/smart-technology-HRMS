<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

/** Why a leave request was refused; the value is the API error code and the lang key under leave.refusal. */
enum LeaveRefusal: string
{
    case DayLaborer = 'leave_day_laborer';
    case Overlap = 'leave_overlap';
    case NoDays = 'leave_no_days';
    case CrossesYear = 'leave_crosses_year';
    case TooLong = 'leave_too_long';
    case NotInReach = 'site_not_in_reach';
    case DaysBlocked = 'leave_days_blocked';
    case CancelBlocked = 'leave_cancel_blocked';

    public function status(): int
    {
        return in_array($this, [self::DaysBlocked, self::CancelBlocked], true) ? 409 : 422;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/** Why an attendance write was refused; the value is the API error code and the lang key suffix. */
enum AttendanceRefusal: string
{
    case PeriodLocked = 'period_locked';
    case FutureDate = 'future_date';
    case EmployeeNotActive = 'employee_not_active';
    case MissingDaysUnconfirmed = 'missing_days_unconfirmed';
    case PeriodAlreadyLocked = 'period_already_locked';
    case PeriodNotLocked = 'period_not_locked';
    case LeaveTypeMissing = 'leave_type_missing';
    case TemplateMismatch = 'template_mismatch';
    case UnreadableFile = 'unreadable_file';
    case TooManyRows = 'too_many_rows';
    case SiteNotInReach = 'site_not_in_reach';
    case LeaveNeedsRequest = 'leave_needs_request';

    public function status(): int
    {
        return match ($this) {
            self::PeriodAlreadyLocked => 409,
            self::PeriodLocked => 423,
            self::FutureDate, self::EmployeeNotActive, self::MissingDaysUnconfirmed, self::PeriodNotLocked, self::LeaveTypeMissing,
            self::TemplateMismatch, self::UnreadableFile, self::TooManyRows, self::SiteNotInReach, self::LeaveNeedsRequest => 422,
        };
    }
}

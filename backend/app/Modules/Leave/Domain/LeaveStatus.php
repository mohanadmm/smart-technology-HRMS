<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

enum LeaveStatus: string
{
    case Pending = 'pending';
    case Escalated = 'escalated';
    case Approved = 'approved';
    case Refused = 'refused';
    case Cancelled = 'cancelled';

    /** The statuses that hold the request's dates: a new request may not overlap them. */
    public const LIVE = [self::Pending, self::Escalated, self::Approved];
}

<?php

declare(strict_types=1);

namespace App\Modules\Leave\Infrastructure;

use Illuminate\Support\Facades\DB;

/** Serializes one employee's leave request writes, so two racing requests cannot both pass the overlap check. */
final class LeaveRequestLock
{
    /** Call inside the caller's transaction: the lock is released at its commit. */
    public static function forEmployee(string $employeeId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['leave_request:'.$employeeId]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use Illuminate\Support\Facades\DB;

/** The employee code counter (owner, 2026-11-03): codes are never reissued, whoever creates the employee. */
final class EmployeeCodes
{
    /**
     * Locks the employees table and takes the next code, moving the counter past it. Call it inside the transaction that creates
     * the employee, so the code and the row commit together.
     * ponytail: the table-wide lock serialises employee creation; fine at HR-entry rates and the one-off master-data import.
     */
    public static function issueNext(): int
    {
        DB::statement('LOCK TABLE employees IN SHARE ROW EXCLUSIVE MODE');
        $code = self::highestIssued() + 1;
        DB::table('employee_code_counter')->update(['last_issued' => $code]);

        return $code;
    }

    /** The counter (migration 2026_11_03_000003), or a higher code written around create() (a seeder, a raw insert). */
    public static function highestIssued(): int
    {
        return (int) DB::scalar('SELECT greatest(last_issued, (SELECT coalesce(max(code), 0) FROM employees)) FROM employee_code_counter');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application;

use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Leave\Domain\LeaveBalance;
use App\Modules\Leave\Infrastructure\Eloquent\LeaveYearAdjustment;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** Reads leave balances. Attendance is the ledger: used days are the stored attendance days whose leave type has a counted code. */
final class LeaveBalances
{
    private const COUNTED = [LeaveCode::Annual, LeaveCode::Casual, LeaveCode::Sick, LeaveCode::Unpaid];

    public function __construct(private readonly EmployeeRoster $roster, private readonly BusinessRules $rules) {}

    /** @return array{employee_id: string, year: int, type: ?string, hire_date: ?string, balance: ?array<string, ?int>, adjustment: ?array<string, mixed>} */
    public function forEmployee(string $employeeId, int $year): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        // ponytail: the type today decides balance or none; a mid-year monthly <-> daily change is not pro-rated (spec, Balance).
        $type = EffectiveDated::at($employee['types'], now('Africa/Cairo')->toDateString())['type'] ?? null;
        $adjustment = LeaveYearAdjustment::where(['employee_id' => $employeeId, 'year' => $year])->first();

        return [
            'employee_id' => $employeeId,
            'year' => $year,
            'type' => $type,
            'hire_date' => $employee['hire_date'],
            'balance' => $type === EmployeeType::DayLaborer->value ? null : $this->balance($employee['hire_date'], $year, $adjustment, $this->counts($employeeId, $year)),
            'adjustment' => $adjustment?->only(['entitlement', 'opening_used', 'opening_casual_used', 'reason', 'version']),
        ];
    }

    /**
     * @param  array{annual: int, casual: int, sick: int, unpaid: int}  $counts
     * @return array<string, ?int>
     */
    private function balance(?string $hireDate, int $year, ?LeaveYearAdjustment $adjustment, array $counts): array
    {
        $rules = $this->rules->all();
        $entitlement = $adjustment->entitlement
            ?? LeaveBalance::entitlement($hireDate, $year, (int) $rules['leave_days_under_one_year'], (int) $rules['leave_days_one_year_plus']);

        return LeaveBalance::compute($entitlement, (int) $rules['leave_casual_days'], $adjustment->opening_used ?? 0, $adjustment->opening_casual_used ?? 0, $counts);
    }

    /** @return array{annual: int, casual: int, sick: int, unpaid: int} */
    private function counts(string $employeeId, int $year): array
    {
        $codes = array_map(fn (LeaveCode $c) => $c->value, self::COUNTED);
        $found = DB::table('attendance_days as d')->join('sites as s', 's.id', '=', 'd.site_id')
            ->where('d.employee_id', $employeeId)->whereNull('d.deleted_at')
            ->whereBetween('d.date', ["{$year}-01-01", "{$year}-12-31"])
            ->where('s.kind', SiteKind::Leave->value)->whereIn('s.leave_code', $codes)
            ->groupBy('s.leave_code')->selectRaw('s.leave_code, count(*) as n')->pluck('n', 'leave_code');

        return [
            'annual' => (int) ($found[LeaveCode::Annual->value] ?? 0),
            'casual' => (int) ($found[LeaveCode::Casual->value] ?? 0),
            'sick' => (int) ($found[LeaveCode::Sick->value] ?? 0),
            'unpaid' => (int) ($found[LeaveCode::Unpaid->value] ?? 0),
        ];
    }
}

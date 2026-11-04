<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Leave\Domain\LeaveBalance;
use App\Modules\Leave\Infrastructure\Eloquent\LeaveYearAdjustment;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reads leave balances and writes HR's yearly adjustment. Attendance is the ledger: used days are the stored attendance days whose leave type has a counted code. */
final class LeaveBalances
{
    private const COUNTED = [LeaveCode::Annual, LeaveCode::Casual, LeaveCode::Sick, LeaveCode::Unpaid];

    private const MONTHLY = [EmployeeType::Office, EmployeeType::Engineer, EmployeeType::PermanentWorker];

    private const ADJUSTMENT = ['entitlement', 'opening_used', 'opening_casual_used', 'reason', 'version'];

    public function __construct(private readonly EmployeeRoster $roster, private readonly BusinessRules $rules) {}

    /** @return array{employee_id: string, year: int, type: ?string, hire_date: ?string, balance: ?array<string, ?int>, adjustment: ?array<string, mixed>} */
    public function forEmployee(string $employeeId, int $year): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        [$read] = $this->read([$employee], $year);

        return ['employee_id' => $employeeId, 'year' => $year, 'type' => $read['type'], 'hire_date' => $employee['hire_date'], 'balance' => $read['balance'], 'adjustment' => $read['adjustment']];
    }

    /**
     * Active monthly employees by code, each with the year's balance flat on the row; $managerId narrows to their reach today.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(int $year, ?string $type, ?string $q, int $perPage, int $page, ?string $managerId): array
    {
        $types = $type === null ? self::MONTHLY : [EmployeeType::from($type)];
        $result = $this->roster->activeOfTypes($types, $q, $perPage, $page, $managerId);
        $data = [];
        foreach ($this->read($result['data'], $year) as $i => $read) {
            $e = $result['data'][$i];
            $data[] = [
                'id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'type' => $read['type'], 'hire_date' => $e['hire_date'],
                ...$read['balance'] ?? [],
            ];
        }

        return ['data' => $data, 'pagination' => $result['pagination']];
    }

    /**
     * Creates the year's adjustment, or updates it when $version matches the stored one (else 409); a day laborer has none (422).
     *
     * @param  array<string, mixed>  $input  validated: entitlement ?int, opening_used int, opening_casual_used int, reason ?string
     * @return array<string, mixed> forEmployee() after the write
     */
    public function adjust(string $employeeId, int $year, array $input, ?int $version): array
    {
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        if (self::typeToday($employee) === EmployeeType::DayLaborer->value) {
            throw ValidationException::withMessages(['employee' => __('errors.no_leave_balance')]);
        }
        $values = [
            'entitlement' => $input['entitlement'] ?? null,
            'opening_used' => (int) $input['opening_used'],
            'opening_casual_used' => (int) $input['opening_casual_used'],
            'reason' => $input['reason'] ?? null,
        ];

        try {
            DB::transaction(function () use ($employeeId, $year, $values, $version): void {
                $row = LeaveYearAdjustment::where(['employee_id' => $employeeId, 'year' => $year])->lockForUpdate()->first();
                if ($row === null) {
                    LeaveYearAdjustment::create([...$values, 'employee_id' => $employeeId, 'year' => $year, 'version' => 1]);

                    return;
                }
                if ($row->version !== $version) {
                    throw AttendanceRefused::because(AttendanceRefusal::ConcurrencyConflict);
                }
                $row->fill([...$values, 'version' => $row->version + 1])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // A racing create of the same employee and year won.
            throw AttendanceRefused::because(AttendanceRefusal::ConcurrencyConflict);
        }

        return $this->forEmployee($employeeId, $year);
    }

    /**
     * One counts query and one adjustments query whatever the number of employees.
     *
     * @param  list<array<string, mixed>>  $employees  EmployeeRoster rows
     * @return list<array{type: ?string, balance: ?array<string, ?int>, adjustment: ?array<string, mixed>}> in $employees order
     */
    private function read(array $employees, int $year): array
    {
        $ids = array_map(fn (array $e) => (string) $e['id'], $employees);
        $counts = $this->counts($ids, $year);
        $adjustments = LeaveYearAdjustment::whereIn('employee_id', $ids)->where('year', $year)->get()->keyBy('employee_id');
        $rules = $this->rules->all();

        return array_map(function (array $e) use ($year, $counts, $adjustments, $rules): array {
            // ponytail: the type today decides balance or none; a mid-year monthly <-> daily change is not pro-rated (spec, Balance).
            $type = self::typeToday($e);
            $adjustment = $adjustments->get($e['id']);

            return [
                'type' => $type,
                'balance' => $type === EmployeeType::DayLaborer->value ? null : $this->balance($e['hire_date'], $year, $adjustment, $counts[$e['id']], $rules),
                'adjustment' => $adjustment?->only(self::ADJUSTMENT),
            ];
        }, $employees);
    }

    /** @param  array<string, mixed>  $employee  an EmployeeRoster row */
    private static function typeToday(array $employee): ?string
    {
        return EffectiveDated::at($employee['types'], now('Africa/Cairo')->toDateString())['type'] ?? null;
    }

    /**
     * @param  array{annual: int, casual: int, sick: int, unpaid: int}  $counts
     * @param  array<string, string>  $rules
     * @return array<string, ?int>
     */
    private function balance(?string $hireDate, int $year, ?LeaveYearAdjustment $adjustment, array $counts, array $rules): array
    {
        $entitlement = $adjustment->entitlement
            ?? LeaveBalance::entitlement($hireDate, $year, (int) $rules['leave_days_under_one_year'], (int) $rules['leave_days_one_year_plus']);

        return LeaveBalance::compute($entitlement, (int) $rules['leave_casual_days'], $adjustment->opening_used ?? 0, $adjustment->opening_casual_used ?? 0, $counts);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{annual: int, casual: int, sick: int, unpaid: int}> keyed by employee id
     */
    private function counts(array $ids, int $year): array
    {
        $found = DB::table('attendance_days as d')->join('sites as s', 's.id', '=', 'd.site_id')
            ->whereIn('d.employee_id', $ids)->whereNull('d.deleted_at')
            ->whereBetween('d.date', ["{$year}-01-01", "{$year}-12-31"])
            ->where('s.kind', SiteKind::Leave->value)->whereIn('s.leave_code', array_map(fn (LeaveCode $c) => $c->value, self::COUNTED))
            ->groupBy('d.employee_id', 's.leave_code')->selectRaw("d.employee_id || ':' || s.leave_code as k, count(*) as n")->pluck('n', 'k');
        $n = fn (string $id, LeaveCode $code) => (int) ($found["{$id}:{$code->value}"] ?? 0);
        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = ['annual' => $n($id, LeaveCode::Annual), 'casual' => $n($id, LeaveCode::Casual), 'sick' => $n($id, LeaveCode::Sick), 'unpaid' => $n($id, LeaveCode::Unpaid)];
        }

        return $counts;
    }
}

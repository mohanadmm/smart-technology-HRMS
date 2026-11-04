<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeWageProposal;
use App\Support\Database\ConcurrencyConflict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The day-labor crew's writes (owner, 2026-11-04; PR #22 review). A day's crew is the day laborers whose site on that date is the
 * site: that day's crew change, else their home site. Adding, moving or removing someone writes a crew change for that date only, so
 * yesterday's records and tomorrow's roster stay as they were. A new day laborer a site manager adds has no home site and no pay row:
 * they join that day's crew, and their proposed wage waits for HR in employee_wage_proposals. Callers check who may do this.
 */
final class DayCrew
{
    /**
     * Day laborers employed on $date matching $search (name, or the exact code), with their home site on $date.
     *
     * @return list<array{id: string, code: int, name_ar: string, name_en: ?string, site_id: ?string}>
     */
    public function matching(string $search, string $date, int $limit = 20): array
    {
        $rows = Employee::with(['typeChanges', 'siteAssignments', 'crewChanges'])
            ->whereRaw(Employee::TYPE_ON.' = ?', [$date, EmployeeType::DayLaborer->value])
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $date))
            ->matching($search)
            ->orderBy('code')->limit($limit)->get();

        return array_values($rows->map(fn (Employee $e) => [
            'id' => (string) $e->id,
            'code' => (int) $e->code,
            'name_ar' => (string) $e->name_ar,
            'name_en' => $e->name_en,
            'site_id' => $this->siteOn($e, $date),
        ])->all());
    }

    /** The day laborer's site on $date (crew change, else home site), or null; null too for anyone who is not a day laborer employed on $date. */
    public function laborerSiteOn(string $employeeId, string $date): ?string
    {
        $e = $this->laborer($employeeId, $date);

        return $e === null ? null : $this->siteOn($e, $date);
    }

    public function isLaborerOn(string $employeeId, string $date): bool
    {
        return $this->laborer($employeeId, $date) !== null;
    }

    /**
     * Puts the employee on $siteId's crew for $date only, or on no crew when null. A change back to their home site removes the day's
     * change, so the day reads as their normal assignment again. Audited.
     */
    public function setCrew(string $employeeId, ?string $siteId, string $date): void
    {
        DB::transaction(function () use ($employeeId, $siteId, $date): void {
            $row = DayCrewChange::where(['employee_id' => $employeeId, 'date' => $date])->lockForUpdate()->first();
            $home = $this->homeSiteOn($employeeId, $date);
            if ($siteId === $home) {
                $row?->delete();
            } elseif ($row === null) {
                DayCrewChange::create(['employee_id' => $employeeId, 'date' => $date, 'site_id' => $siteId]);
            } elseif ($row->site_id !== $siteId) {
                $row->site_id = $siteId;
                $row->save();
            }
        });
    }

    /**
     * A new day laborer, hired on $date and on $siteId's crew that day, with no home site and no pay row; $wage waits as a pending proposal.
     *
     * @param  array{name_ar: string, phone?: ?string}  $identity
     * @return array{id: string, code: int, name_ar: string}
     */
    public function propose(array $identity, string $siteId, string $date, string $wage): array
    {
        return DB::transaction(function () use ($identity, $siteId, $date, $wage): array {
            // The same table lock and counter as EmployeeService::create, so codes are never reused (owner, 2026-11-03).
            DB::statement('LOCK TABLE employees IN SHARE ROW EXCLUSIVE MODE');
            $code = (int) DB::scalar('SELECT greatest(last_issued, (SELECT coalesce(max(code), 0) FROM employees)) FROM employee_code_counter') + 1;
            DB::table('employee_code_counter')->update(['last_issued' => $code]);

            $employee = Employee::create(['name_ar' => $identity['name_ar'], 'phone' => $identity['phone'] ?? null, 'hire_date' => $date, 'code' => $code]);
            $employee->typeChanges()->create(['type' => EmployeeType::DayLaborer->value, 'effective_from' => $date]);
            $employee->crewChanges()->create(['date' => $date, 'site_id' => $siteId]);
            EmployeeWageProposal::create(['employee_id' => $employee->id, 'daily_wage' => $wage, 'status' => 'pending']);

            return ['id' => (string) $employee->id, 'code' => $code, 'name_ar' => (string) $employee->name_ar];
        });
    }

    /**
     * HR's pending proposals, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $rows = [];
        foreach (EmployeeWageProposal::with('employee')->where('status', 'pending')->orderBy('id')->get() as $p) {
            $rows[] = [
                'id' => $p->id,
                'version' => $p->version,
                'daily_wage' => $p->daily_wage,
                'proposed_by' => $p->created_by,
                'created_at' => $p->created_at?->toIso8601String(),
                'employee' => ['id' => $p->employee_id, 'code' => (int) $p->employee?->code, 'name_ar' => (string) $p->employee?->name_ar, 'gender' => $p->employee?->gender],
            ];
        }

        return $rows;
    }

    /**
     * HR decides a proposal. Approving writes the pay row from the hire date, at HR's $wage or else the proposed one, and needs the
     * employee's gender when it is still empty. A decided or changed proposal is a 409.
     */
    public function decide(string $proposalId, int $version, bool $approve, ?string $wage, ?string $gender, string $hrId): void
    {
        DB::transaction(function () use ($proposalId, $version, $approve, $wage, $gender, $hrId): void {
            $p = EmployeeWageProposal::lockForUpdate()->findOrFail($proposalId);
            if ($p->status !== 'pending' || $p->version !== $version) {
                throw new ConcurrencyConflict;
            }
            if ($approve) {
                $employee = Employee::lockForUpdate()->findOrFail($p->employee_id);
                if ($employee->gender === null && $gender === null) {
                    throw ValidationException::withMessages(['gender' => __('validation.required', ['attribute' => __('validation.attributes.gender')])]);
                }
                if ($gender !== null && $employee->gender === null) {
                    $employee->gender = $gender;
                    $employee->save();
                }
                $from = (string) ($employee->hire_date ?? $p->created_at?->toDateString());
                $employee->payChanges()->create(['effective_from' => $from, 'daily_wage' => $wage ?? (string) $p->daily_wage]);
            }
            $p->fill(['status' => $approve ? 'approved' : 'refused', 'decided_by' => $hrId, 'decided_at' => now(), 'version' => $version + 1]);
            $p->save();
        });
    }

    private function laborer(string $employeeId, string $date): ?Employee
    {
        $e = Employee::with(['typeChanges', 'siteAssignments', 'crewChanges'])->find($employeeId);
        if ($e === null || ($e->hire_date !== null && $e->hire_date > $date) || ($e->termination_date !== null && $e->termination_date < $date)) {
            return null;
        }
        $types = $e->typeChanges->map(fn ($t) => ['type' => (string) $t->type, 'effective_from' => (string) $t->effective_from])->all();

        return (EffectiveDated::at($types, $date)['type'] ?? null) === EmployeeType::DayLaborer->value ? $e : null;
    }

    private function siteOn(Employee $e, string $date): ?string
    {
        $change = $e->crewChanges->first(fn (DayCrewChange $c) => (string) $c->date === $date);
        if ($change !== null) {
            return $change->site_id === null ? null : (string) $change->site_id;
        }

        return $this->homeOf($e, $date);
    }

    private function homeSiteOn(string $employeeId, string $date): ?string
    {
        $e = Employee::with('siteAssignments')->find($employeeId);

        return $e === null ? null : $this->homeOf($e, $date);
    }

    private function homeOf(Employee $e, string $date): ?string
    {
        $sites = $e->siteAssignments->map(fn (EmployeeSiteAssignment $s) => ['site_id' => (string) $s->site_id, 'effective_from' => (string) $s->effective_from])->all();
        $site = EffectiveDated::at($sites, $date)['site_id'] ?? null;

        return $site === null ? null : (string) $site;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\DayCrewChange;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeWageProposal;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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
    use Paginates;

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
        try {
            $this->writeCrew($employeeId, $siteId, $date);
        } catch (UniqueConstraintViolationException) {
            // Two requests changed the same person's day at once and the other one's row won: the screen is out of date.
            throw new ConcurrencyConflict;
        }
    }

    private function writeCrew(string $employeeId, ?string $siteId, string $date): void
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
     * @return array{id: string, code: int, name_ar: string, proposal_id: string}
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
            $proposal = EmployeeWageProposal::create(['employee_id' => $employee->id, 'daily_wage' => $wage, 'status' => 'pending']);

            return ['id' => (string) $employee->id, 'code' => $code, 'name_ar' => (string) $employee->name_ar, 'proposal_id' => (string) $proposal->id];
        });
    }

    /**
     * The day's crew changes by site, for HR's evening digest: who was added to a site, and who was taken off every crew.
     *
     * @return array{added: array<string, int>, removed: int}
     */
    public function changesOn(string $date): array
    {
        $rows = DayCrewChange::where('date', $date)->get(['site_id']);

        return [
            'added' => $rows->whereNotNull('site_id')->countBy(fn (DayCrewChange $c) => (string) $c->site_id)->all(),
            'removed' => $rows->whereNull('site_id')->count(),
        ];
    }

    /**
     * HR's pending proposals, oldest first, a page at a time.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function pending(int $perPage, int $page): array
    {
        $list = $this->page(EmployeeWageProposal::with('employee.crewChanges')->where('status', 'pending')->orderBy('id'), $perPage, $page);
        // Named in one query each, not per row: the site the laborer was added to, and the site manager who proposed the wage.
        $sites = Site::withTrashed()->whereKey(array_filter(array_column($list['data'], 'site_id')))->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $users = User::withTrashed()->whereKey(array_filter(array_column($list['data'], 'proposed_by')))->pluck('name', 'id');
        $list['data'] = array_map(function (array $r) use ($sites, $users): array {
            $site = $r['site_id'] === null ? null : $sites[$r['site_id']] ?? null;

            return [
                ...array_diff_key($r, ['site_id' => true]),
                'site' => $site === null ? null : ['id' => (string) $site->id, 'name_ar' => (string) $site->name_ar, 'name_en' => $site->name_en],
                'proposed_by' => $r['proposed_by'] === null ? null : ['id' => $r['proposed_by'], 'name' => $users[$r['proposed_by']] ?? null],
            ];
        }, $list['data']);

        return $list;
    }

    /** @return array<string, mixed> a pending proposal as HR's list shows it; pending() names its site and proposer */
    private function row(Model $m): array
    {
        /** @var EmployeeWageProposal $m */
        $e = $m->employee;
        // the crew the site manager added them to: the change on their first day
        $first = $e?->crewChanges->sortBy('date')->first();

        return [
            'id' => $m->id,
            'version' => $m->version,
            'daily_wage' => $m->daily_wage,
            'proposed_by' => $m->created_by,
            'created_at' => $m->created_at?->toIso8601String(),
            'site_id' => $first?->site_id === null ? null : (string) $first->site_id,
            'employee' => ['id' => $m->employee_id, 'code' => (int) $e?->code, 'name_ar' => (string) $e?->name_ar, 'name_en' => $e?->name_en, 'gender' => $e?->gender, 'phone' => $e?->phone, 'hire_date' => $e?->hire_date],
        ];
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

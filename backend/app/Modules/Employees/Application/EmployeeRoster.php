<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeePayChange;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeSiteAssignment;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeTypeChange;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model of live employees with their dated types and shifts, so other modules never query Employees' tables.
 * A row is {id: string, code: int, name_ar: string, name_en: ?string, department: ?string, hire_date: ?string, termination_date: ?string,
 * types: list<{type, effective_from}>, shifts: list<{effective_from, shift_start: ?HH:MM, shift_end: ?HH:MM}>, sites: list<{site_id, effective_from}>}.
 */
final class EmployeeRoster
{
    private const RELATIONS = ['typeChanges', 'payChanges', 'siteAssignments'];

    /**
     * Some type row of $type overlaps [from, to] and the employment window; greatest/least skip a null hire date, termination date or next change.
     * `- 1` turns the next change's effective_from into this row's last day.
     */
    private const EMPLOYED_AS = 'exists (select 1 from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null and t.type = ?
        and greatest(t.effective_from, employees.hire_date, ?::date) <= least(
            (select min(n.effective_from) from employee_type_changes n where n.employee_id = t.employee_id and n.deleted_at is null and n.effective_from > t.effective_from) - 1,
            employees.termination_date, ?::date))';

    /** @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function ofType(EmployeeType $type, string $from, string $to, int $perPage, int $page, ?string $managedBy = null): array
    {
        $result = $this->employedAs($type, $from, $to, $managedBy)->paginate(min(max($perPage, 1), 100), ['*'], 'page', max($page, 1));

        return [
            'data' => array_values($result->getCollection()->map(fn (Employee $e) => $this->row($e))->all()),
            'pagination' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'total_pages' => $result->lastPage(),
                'has_next_page' => $result->hasMorePages(),
                'has_previous_page' => $result->currentPage() > 1,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function allOfType(EmployeeType $type, string $from, string $to, ?string $managedBy = null): array
    {
        return array_values($this->employedAs($type, $from, $to, $managedBy)->get()->map(fn (Employee $e) => $this->row($e))->all());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $e = Employee::with(self::RELATIONS)->find($id);

        return $e === null ? null : $this->row($e);
    }

    /**
     * @param  list<string>  $ids
     * @return array<array-key, array<string, mixed>> keyed by id (a UUID, so never cast to int); unknown ids are left out
     */
    public function findMany(array $ids): array
    {
        $rows = [];
        foreach (Employee::with(self::RELATIONS)->whereKey($ids)->get() as $e) {
            $rows[(string) $e->id] = $this->row($e);
        }

        return $rows;
    }

    /** @return Builder<Employee> narrowed, when $managedBy is set, to the employees in that user's reach on min($to, today) (P4) */
    private function employedAs(EmployeeType $type, string $from, string $to, ?string $managedBy): Builder
    {
        return Employee::with(self::RELATIONS)->whereRaw(self::EMPLOYED_AS, [$type->value, $from, $to])
            ->when($managedBy !== null, fn (Builder $q) => EmployeeReach::apply($q, (string) $managedBy, min($to, now('Africa/Cairo')->toDateString())))
            ->orderBy('code');
    }

    /** @return array<string, mixed> */
    private function row(Employee $e): array
    {
        $hhmm = fn (?string $t) => $t === null ? null : substr($t, 0, 5);

        return [
            'id' => (string) $e->id,
            'code' => (int) $e->code,
            'name_ar' => (string) $e->name_ar,
            'name_en' => $e->name_en,
            'department' => $e->department,
            'hire_date' => $e->hire_date,
            'termination_date' => $e->termination_date,
            'types' => array_values($e->typeChanges->sortBy('effective_from')->map(fn (EmployeeTypeChange $t) => [
                'type' => (string) $t->type, 'effective_from' => (string) $t->effective_from,
            ])->all()),
            'shifts' => array_values($e->payChanges->sortBy('effective_from')->map(fn (EmployeePayChange $p) => [
                'effective_from' => (string) $p->effective_from, 'shift_start' => $hhmm($p->shift_start), 'shift_end' => $hhmm($p->shift_end),
            ])->all()),
            'sites' => array_values($e->siteAssignments->sortBy('effective_from')->map(fn (EmployeeSiteAssignment $s) => [
                'site_id' => (string) $s->site_id, 'effective_from' => (string) $s->effective_from,
            ])->all()),
        ];
    }
}

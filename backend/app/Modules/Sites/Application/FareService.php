<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Support\Http\Paginates;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Residences, residence x site fares and per-employee fare overrides. */
final class FareService
{
    use Paginates;

    public function __construct(private readonly SiteService $sites) {}

    /**
     * @param  array{per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function listResidences(array $filters): array
    {
        return $this->page(Residence::query()->orderBy('name_ar'), $filters['per_page'] ?? 20);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createResidence(array $data): array
    {
        return $this->row(Residence::create($data));
    }

    /** @return array<string, mixed> */
    public function showResidence(string $id): array
    {
        return $this->row(Residence::findOrFail($id));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateResidence(string $id, array $data): array
    {
        $residence = Residence::findOrFail($id);
        $residence->update($data);

        return $this->row($residence);
    }

    public function deleteResidence(string $id): void
    {
        Residence::findOrFail($id)->delete();
    }

    /**
     * Rows carry `set_by` (the last writer; null = the starting values from Excel) and `previous_amount` (the same pair's row just before).
     *
     * @param  array{residence_id?: string, site_id?: string, date?: string, manager_id?: string, changed_since?: string, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function listFares(array $filters, ?string $ownerId = null): array
    {
        $query = Fare::query()->select('fares.*')
            ->selectRaw('fares.updated_by as set_by_id, (select u.name from users u where u.id = fares.updated_by) as set_by_name')
            ->selectRaw('(select p.amount from fares p where p.residence_id = fares.residence_id and p.site_id = fares.site_id and p.deleted_at is null and p.effective_from < fares.effective_from order by p.effective_from desc limit 1) as previous_amount')
            ->orderBy('residence_id')->orderBy('site_id')->orderBy('effective_from');
        foreach (['residence_id', 'site_id'] as $col) {
            if (isset($filters[$col])) {
                $query->where($col, $filters[$col]);
            }
        }
        // Both narrow: an own-scope manager_id can only shrink the user's own sites.
        foreach ([$ownerId, $filters['manager_id'] ?? null] as $managerId) {
            if ($managerId !== null) {
                $query->whereIn('site_id', $this->sites->idsManagedBy($managerId));
            }
        }
        if (isset($filters['changed_since'])) {
            $query->where('updated_at', '>=', CarbonImmutable::parse($filters['changed_since'], 'Africa/Cairo')->startOfDay()->utc());
        }

        if (isset($filters['date'])) {
            // only the row in force on that date per (residence, site)
            $query->whereIn('id', fn ($q) => $q->from('fares')->selectRaw('distinct on (residence_id, site_id) id')
                ->where('effective_from', '<=', $filters['date'])->whereNull('deleted_at')
                ->orderBy('residence_id')->orderBy('site_id')->orderByDesc('effective_from'));
        }

        $page = $this->page($query, $filters['per_page'] ?? 20);
        $page['data'] = array_map(function (array $row): array {
            $setBy = $row['set_by_id'] === null ? null : ['id' => $row['set_by_id'], 'name' => $row['set_by_name']];
            unset($row['set_by_id'], $row['set_by_name']);

            return [...$row, 'set_by' => $setBy];
        }, $page['data']);

        return $page;
    }

    /**
     * @param  array{residence_id: string, site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function addFare(array $data): array
    {
        $key = ['residence_id' => $data['residence_id'], 'site_id' => $data['site_id'], 'effective_from' => $data['effective_from']];
        if (Fare::where($key)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }

        return $this->row(Fare::create($data));
    }

    /**
     * @param  array{site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function addOverride(string $employeeId, array $data): array
    {
        $employee = Employee::findOrFail($employeeId);
        $key = ['employee_id' => $employee->getKey(), 'site_id' => $data['site_id'], 'effective_from' => $data['effective_from']];
        if (EmployeeFareOverride::where($key)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.duplicate_date')]);
        }

        return $this->row(EmployeeFareOverride::create([...$data, 'employee_id' => $employee->getKey()]));
    }
}

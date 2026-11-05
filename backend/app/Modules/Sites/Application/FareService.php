<?php

declare(strict_types=1);

namespace App\Modules\Sites\Application;

use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Support\Http\Paginates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Residences, residence x site fares and per-employee fare overrides. */
final class FareService
{
    use Paginates;

    public function __construct(
        private readonly SiteService $sites,
        private readonly PermissionHolders $holders,
        private readonly Notifier $notifier,
    ) {}

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

    /**
     * The API write: same (residence, site, effective_from) updates the amount. The importer keeps the strict addFare.
     *
     * @param  array{residence_id: string, site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function saveFare(array $data, ?string $ownerId): array
    {
        // A pair fare is the default of both worker types, so a lock of either refuses it.
        $this->assertWritable($ownerId, $data['site_id'], $data['effective_from'], EmployeeType::PermanentWorker, EmployeeType::DayLaborer);
        $key = ['residence_id' => $data['residence_id'], 'site_id' => $data['site_id'], 'effective_from' => $data['effective_from']];

        $pair = ['residence_id' => $data['residence_id'], 'site_id' => $data['site_id']];

        // ponytail: two first writes of one key in the same instant hit the unique index (500); lock the key if it ever happens
        return DB::transaction(function () use ($key, $pair, $data, $ownerId): array {
            $old = $this->inForce(Fare::where($pair), $data['effective_from']);
            $fare = Fare::updateOrCreate($key, ['amount' => $data['amount']]);
            $this->notifyHr($ownerId, $fare, ['kind' => 'fare', ...$pair, 'effective_from' => $data['effective_from'], 'old_amount' => $old]);

            return $this->row($fare);
        });
    }

    /**
     * @param  array{site_id: string, effective_from: string, amount: string}  $data
     * @return array<string, mixed>
     */
    public function saveOverride(string $employeeId, array $data, ?string $ownerId): array
    {
        $date = $data['effective_from'];
        $employee = Employee::query()->select('employees.*')->selectRaw(Employee::TYPE_ON.' as type_on', [$date])->findOrFail($employeeId);
        // employee.scope checks reach today; the override must also be in reach on the day it takes effect.
        if ($ownerId !== null && ! EmployeeReach::reaches($employeeId, $ownerId, $date)) {
            throw new ModelNotFoundException;
        }
        $type = EmployeeType::tryFrom((string) $employee->getAttribute('type_on'));
        $this->assertWritable($ownerId, $data['site_id'], $date, ...($type === null ? [] : [$type]));
        $key = ['employee_id' => $employeeId, 'site_id' => $data['site_id'], 'effective_from' => $date];

        return DB::transaction(function () use ($key, $data, $date, $employeeId, $ownerId): array {
            $old = $this->inForce(EmployeeFareOverride::where(['employee_id' => $employeeId, 'site_id' => $data['site_id']]), $date);
            $override = EmployeeFareOverride::updateOrCreate($key, ['amount' => $data['amount']]);
            $this->notifyHr($ownerId, $override, ['kind' => 'override', 'site_id' => $data['site_id'], 'employee_id' => $employeeId, 'effective_from' => $date, 'old_amount' => $old]);

            return $this->row($override);
        });
    }

    /** Own scope: one of the user's sites, from yesterday (Cairo) on. Every scope: not inside a locked attendance period. */
    private function assertWritable(?string $ownerId, string $siteId, string $date, EmployeeType ...$types): void
    {
        if ($ownerId !== null && ! in_array($siteId, $this->sites->idsManagedBy($ownerId), true)) {
            throw ValidationException::withMessages(['site_id' => __('errors.fare_site_not_yours')]);
        }
        if ($ownerId !== null && $date < now('Africa/Cairo')->subDay()->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => __('errors.fare_too_early')]);
        }
        foreach ($types as $type) {
            if (PeriodGuard::isLocked(Period::containing($type, $date))) {
                throw ValidationException::withMessages(['effective_from' => __('errors.fare_period_locked')]);
            }
        }
    }

    /**
     * @param  Builder<Fare>|Builder<EmployeeFareOverride>  $rows
     */
    private function inForce(Builder $rows, string $date): ?string
    {
        $amount = $rows->where('effective_from', '<=', $date)->orderByDesc('effective_from')->value('amount');

        return $amount === null ? null : (string) $amount;
    }

    /**
     * A team-lead write tells every holder of fare.manage.all, once per row a day (the audit log keeps every write). HR writes notify nobody.
     *
     * @param  array<string, mixed>  $data
     */
    private function notifyHr(?string $ownerId, Fare|EmployeeFareOverride $row, array $data): void
    {
        if ($ownerId === null) {
            return;
        }
        $today = now('Africa/Cairo')->toDateString();
        foreach ($this->holders->userIds('fare.manage.all') as $userId) {
            $this->notifier->notifyOnce($userId, 'fare_changed', $today, [...$data, 'new_amount' => $row->amount], (string) $row->getKey());
        }
    }
}

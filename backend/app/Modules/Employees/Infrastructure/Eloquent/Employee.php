<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Audited;
    use HasStandardColumns;

    /** The employee's type in force on the bound date `?`. */
    public const TYPE_ON = '(select t.type from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null and t.effective_from <= ?::date order by t.effective_from desc limit 1)';

    /** TYPE_ON read on the bound date `?` or the termination date, whichever is earlier (least() skips a null termination date). */
    public const TYPE_ON_OR_LEAVING = '(select t.type from employee_type_changes t where t.employee_id = employees.id and t.deleted_at is null and t.effective_from <= least(?::date, employees.termination_date) order by t.effective_from desc limit 1)';

    protected $guarded = [];

    /**
     * Either name contains $search, or the code equals it when it is a number; $nationalId adds the national id (HR only).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function matching(Builder $query, string $search, bool $nationalId = false): void
    {
        $like = '%'.addcslashes($search, '%_\\').'%';
        // Every arm must be indexable or Postgres scans the table: trigram indexes for the text, the code's unique index for a number.
        $query->where(fn (Builder $q) => $q
            ->where('name_ar', 'ilike', $like)
            ->orWhere('name_en', 'ilike', $like)
            ->when($nationalId, fn (Builder $q) => $q->orWhere('national_id', 'like', $like))
            ->when(preg_match('/^(0|[1-9]\d{0,8})$/D', $search) === 1, fn (Builder $q) => $q->orWhere('code', (int) $search)));
    }

    /** @return HasMany<EmployeeTypeChange, $this> */
    public function typeChanges(): HasMany
    {
        return $this->hasMany(EmployeeTypeChange::class);
    }

    /** @return HasMany<EmployeePayChange, $this> */
    public function payChanges(): HasMany
    {
        return $this->hasMany(EmployeePayChange::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** @return HasMany<EmployeeManagerAssignment, $this> */
    public function managerAssignments(): HasMany
    {
        return $this->hasMany(EmployeeManagerAssignment::class);
    }

    /** @return HasMany<EmployeeSiteAssignment, $this> */
    public function siteAssignments(): HasMany
    {
        return $this->hasMany(EmployeeSiteAssignment::class);
    }
}

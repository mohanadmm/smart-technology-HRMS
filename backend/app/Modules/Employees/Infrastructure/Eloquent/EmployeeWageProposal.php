<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A site manager's proposed daily wage for a day laborer they added; a pay row exists only once HR approves it.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $daily_wage
 * @property string $status
 * @property int $version
 * @property string|null $created_by the site manager who proposed it
 * @property Carbon|null $created_at
 */
class EmployeeWageProposal extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'decided_at' => 'datetime'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

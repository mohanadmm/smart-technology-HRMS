<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * A day laborer on a site (or on none) for one date only, set by a site manager; their home-site history is untouched.
 *
 * @property string $employee_id
 * @property string $date
 * @property string|null $site_id null: off every crew that day
 */
class DayCrewChange extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];
}

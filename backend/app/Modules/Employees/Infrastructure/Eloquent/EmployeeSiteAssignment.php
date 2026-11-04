<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string|null $site_id null: on no site from effective_from (removed from a day-labor crew) */
class EmployeeSiteAssignment extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    /** @return BelongsTo<Site, $this> a deleted site still names its past assignments */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }
}

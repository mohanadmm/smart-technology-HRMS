<?php

declare(strict_types=1);

namespace App\Modules\Leave\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

class LeaveYearAdjustment extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['year' => 'integer', 'entitlement' => 'integer', 'opening_used' => 'integer', 'opening_casual_used' => 'integer', 'version' => 'integer'];
}

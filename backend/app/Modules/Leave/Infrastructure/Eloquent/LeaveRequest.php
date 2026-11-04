<?php

declare(strict_types=1);

namespace App\Modules\Leave\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use Audited;
    use HasStandardColumns;

    protected $guarded = [];

    protected $casts = ['days' => 'integer', 'version' => 'integer', 'decided_at' => 'datetime'];
}

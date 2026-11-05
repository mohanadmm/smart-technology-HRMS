<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Read model for error_log (developer console). */
class ErrorLogEntry extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = 'error_log';

    public $timestamps = false;

    protected $casts = ['occurred_at' => 'datetime'];

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::whereRaw("occurred_at < now() - interval '90 days'");
    }
}

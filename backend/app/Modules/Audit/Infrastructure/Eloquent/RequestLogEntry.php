<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read model for request_log (developer console). */
class RequestLogEntry extends Model
{
    use HasUuids;

    protected $table = 'request_log';

    public $timestamps = false;

    protected $casts = ['query' => 'array', 'request_body' => 'array', 'response_body' => 'array', 'occurred_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

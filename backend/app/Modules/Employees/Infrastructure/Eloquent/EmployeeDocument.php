<?php

declare(strict_types=1);

namespace App\Modules\Employees\Infrastructure\Eloquent;

use App\Modules\Audit\Infrastructure\Audited;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use Audited;
    use HasStandardColumns;

    public const EXT = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    protected $guarded = [];

    public function path(): string
    {
        return "employee-documents/{$this->employee_id}/{$this->id}.".self::EXT[$this->mime];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

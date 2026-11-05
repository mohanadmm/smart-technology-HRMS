<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Identity\Domain\Authentication\ViewAs;
use App\Support\Database\HasStandardColumns;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasStandardColumns;

    /** Set by the guard on a view-as session: Auth::user() is the target, this names the developer. */
    public ?ViewAs $viewAs = null;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed', 'is_active' => 'boolean', 'locked_until' => 'datetime'];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<UserSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }
}

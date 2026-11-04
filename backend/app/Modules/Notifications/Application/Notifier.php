<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use Illuminate\Database\UniqueConstraintViolationException;

final class Notifier
{
    /**
     * One notification per (user, type, date, ref); a retried job adds nothing.
     *
     * @param  array<string, mixed>  $data  counts and ids only, never personal data (§9)
     * @param  string|null  $ref  the id of what the notification is about, when one date can hold several (an employee, a request)
     * @return bool false when the row already exists
     */
    public function notifyOnce(string $userId, string $type, string $date, array $data, ?string $ref = null): bool
    {
        $key = ['user_id' => $userId, 'type' => $type, 'date' => $date, 'ref' => $ref];
        if (Notification::withTrashed()->where($key)->exists()) {
            return false;
        }
        try {
            Notification::create([...$key, 'data' => $data]);
        } catch (UniqueConstraintViolationException) {
            return false;   // a concurrent run inserted it first
        }

        return true;
    }
}

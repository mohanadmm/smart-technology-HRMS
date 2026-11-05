<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Eloquent\RequestLogEntry;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Model;

final class RequestLogQuery
{
    use Paginates;

    /**
     * @param  array<string, mixed>  $filters  status: failed (>= 400) or server (>= 500); path: contains, any case; from/to: inclusive Cairo dates
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters): array
    {
        $query = RequestLogEntry::query()->with('user:id,name')->orderByDesc('occurred_at')->orderByDesc('id');
        foreach (['method', 'user_id', 'request_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['status'])) {
            $query->where('status', '>=', $filters['status'] === 'server' ? 500 : 400);
        }
        if (isset($filters['path'])) {
            $query->where('path', 'ilike', '%'.addcslashes((string) $filters['path'], '%_\\').'%');
        }
        CairoDates::apply($query, $filters);

        return $this->page($query, $filters['per_page'] ?? 20);
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        /** @var RequestLogEntry $m */
        $user = $m->user;

        return [
            ...array_diff_key($m->toArray(), ['user' => true]),
            'user' => $user === null ? null : ['id' => (string) $user->id, 'name' => $user->name],
        ];
    }
}

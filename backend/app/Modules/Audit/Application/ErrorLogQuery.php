<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Eloquent\ErrorLogEntry;
use App\Support\Http\Paginates;

final class ErrorLogQuery
{
    use Paginates;

    /**
     * @param  array<string, mixed>  $filters  request_id: exact; from/to: inclusive Cairo dates
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(array $filters): array
    {
        $query = ErrorLogEntry::query()->orderByDesc('occurred_at')->orderByDesc('id');
        if (isset($filters['request_id'])) {
            $query->where('request_id', $filters['request_id']);
        }
        CairoDates::apply($query, $filters);

        return $this->page($query, $filters['per_page'] ?? 20);
    }
}

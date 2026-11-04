<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** The from/to filter shared by the log queries: inclusive Cairo dates (Y-m-d) over a UTC timestamp column. */
final class CairoDates
{
    /**
     * @param  Builder<*>  $query
     * @param  array<string, mixed>  $filters
     */
    public static function apply(Builder $query, array $filters, string $column = 'occurred_at'): void
    {
        if (isset($filters['from'])) {
            $query->where($column, '>=', Carbon::parse($filters['from'], 'Africa/Cairo')->startOfDay()->utc());
        }
        if (isset($filters['to'])) {
            $query->where($column, '<', Carbon::parse($filters['to'], 'Africa/Cairo')->addDay()->startOfDay()->utc());
        }
    }
}

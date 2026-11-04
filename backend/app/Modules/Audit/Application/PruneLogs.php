<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLogEntry;
use App\Modules\Audit\Infrastructure\Eloquent\ErrorLogEntry;
use App\Modules\Audit\Infrastructure\Eloquent\RequestLogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Retention: each model's prunable() holds its cutoff; a pruned audit gap is itself audited, so it reads as retention, not tampering. */
final class PruneLogs
{
    /** @return array{request_log: int, error_log: int, audit_log: int} */
    public function run(): array
    {
        $counts = [
            'request_log' => (new RequestLogEntry)->pruneAll(),
            'error_log' => (new ErrorLogEntry)->pruneAll(),
        ];
        // One transaction: an audit gap never exists without its PRUNED row. now() is frozen in it, so the cutoff is the deletes' own.
        $counts['audit_log'] = DB::transaction(function (): int {
            $count = (new AuditLogEntry)->pruneAll();
            if ($count > 0) {
                AuditLog::record('PRUNED', 'audit_log', null, null, ['count' => $count, 'cutoff' => CarbonImmutable::parse(AuditLogEntry::cutoff())->toJSON()]);
            }

            return $count;
        });

        return $counts;
    }
}

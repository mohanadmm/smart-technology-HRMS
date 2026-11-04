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
            'audit_log' => (new AuditLogEntry)->pruneAll(),
        ];
        if ($counts['audit_log'] > 0) {
            // Read after the prune: every deleted row is older than this cutoff.
            $cutoff = CarbonImmutable::parse((string) DB::scalar('select '.AuditLogEntry::CUTOFF))->toJSON();
            AuditLog::record('PRUNED', 'audit_log', null, null, ['count' => $counts['audit_log'], 'cutoff' => $cutoff]);
        }

        return $counts;
    }
}

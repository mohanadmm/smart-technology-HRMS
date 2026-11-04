<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation;

use App\Modules\Audit\Application\PruneLogs;
use Illuminate\Console\Command;

/** Scheduled daily at 03:00 Cairo (routes/console.php). */
final class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Delete request/error log rows older than 90 days and audit rows older than 1 year';

    public function handle(PruneLogs $prune): int
    {
        $c = $prune->run();
        $this->line("request_log: {$c['request_log']}, error_log: {$c['error_log']}, audit_log: {$c['audit_log']}");

        return self::SUCCESS;
    }
}

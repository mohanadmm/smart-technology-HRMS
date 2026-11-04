<?php

declare(strict_types=1);

namespace App\Modules\Leave\Presentation;

use App\Modules\Attendance\Application\EffectiveDays;
use App\Modules\Leave\Application\SendAnniversaryAlert;
use DateTimeImmutable;
use Illuminate\Console\Command;

/** Scheduled daily at 08:00 Cairo (routes/console.php); run by hand to replay a date. */
final class AlertAnniversaryCommand extends Command
{
    protected $signature = 'leave:alert-anniversary {date? : Y-m-d, defaults to today in Cairo}';

    protected $description = 'Queue the one-year-of-service notification for HR';

    public function handle(): int
    {
        $date = $this->argument('date') ?? EffectiveDays::today();
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $this->error('The date must be Y-m-d.');

            return self::FAILURE;
        }
        SendAnniversaryAlert::dispatch($date);
        $this->info("Queued the anniversary alert for {$date}.");

        return self::SUCCESS;
    }
}

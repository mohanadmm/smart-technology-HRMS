<?php

declare(strict_types=1);

namespace App\Support\Localization;

use DateInterval;

final class ArabicServiceDuration
{
    public static function format(DateInterval $duration): string
    {
        $parts = [
            self::part($duration->y, 'سنة واحدة', 'سنتان', 'سنوات', 'سنة'),
            self::part($duration->m, 'شهر واحد', 'شهران', 'أشهر', 'شهرًا'),
            self::part($duration->d, 'يوم واحد', 'يومان', 'أيام', 'يومًا'),
        ];

        return implode(' و', array_filter($parts));
    }

    private static function part(int $count, string $singular, string $dual, string $plural, string $accusative): string
    {
        return match (true) {
            $count === 0 => '',
            $count === 1 => $singular,
            $count === 2 => $dual,
            $count <= 10 => "{$count} {$plural}",
            default => "{$count} {$accusative}",
        };
    }
}

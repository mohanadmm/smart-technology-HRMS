<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * Which dates a site manager may write (design Part 2, P8): today, and yesterday until the `manager_yesterday_until` time, Cairo.
 * A returned period (Slice 4) reopens its past days. Dates are Y-m-d and times H:i, so string comparison is chronological.
 */
final class ManagerWindow
{
    public static function allows(string $date, string $today, string $now, string $until, bool $returned): bool
    {
        if ($date === $today) {
            return true;
        }
        if ($returned && $date < $today) {
            return true;
        }

        return $date === self::yesterday($today) && $now < $until;
    }

    /** A write to a day before today outside the yesterday grace: HR is told at once (Slice 4). */
    public static function isPastDayEdit(string $date, string $today, string $now, string $until): bool
    {
        return $date < $today && ! ($date === self::yesterday($today) && $now < $until);
    }

    private static function yesterday(string $today): string
    {
        return (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    }
}

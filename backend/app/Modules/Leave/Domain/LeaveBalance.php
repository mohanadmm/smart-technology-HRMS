<?php

declare(strict_types=1);

namespace App\Modules\Leave\Domain;

/** The yearly leave balance of a monthly employee (docs/calculations.md §7); the rules come in as arguments, read from settings. */
final class LeaveBalance
{
    /** Annual entitlement for $year from the hire date (Y-m-d); null when the hire date is unknown. */
    public static function entitlement(?string $hireDate, int $year, int $underOneYear, int $oneYearPlus): ?int
    {
        if ($hireDate === null) {
            return null;
        }
        if ($hireDate <= sprintf('%04d-01-01', $year - 1)) {
            return $oneYearPlus;
        }
        if ($hireDate < sprintf('%04d-01-01', $year)) {
            return $underOneYear;
        }
        if ($hireDate > sprintf('%04d-12-31', $year)) {
            return 0;
        }
        [, $month, $day] = array_map('intval', explode('-', $hireDate));
        $fullMonths = 12 - $month + ($day === 1 ? 1 : 0);

        return intdiv($underOneYear * $fullMonths, 12);
    }

    /**
     * @param  array{annual: int, casual: int, sick: int, unpaid: int}  $counts  stored attendance days in the year, by leave code
     * @return array{entitlement: ?int, casual_cap: ?int, used: int, casual_used: int, remaining: ?int, casual_remaining: ?int, sick_days: int, unpaid_days: int}
     */
    public static function compute(?int $entitlement, int $casualDays, int $openingUsed, int $openingCasualUsed, array $counts): array
    {
        $casualCap = $entitlement === null ? null : min($casualDays, $entitlement);
        $used = $counts['annual'] + $counts['casual'] + $openingUsed;
        $casualUsed = $counts['casual'] + $openingCasualUsed;

        return [
            'entitlement' => $entitlement,
            'casual_cap' => $casualCap,
            'used' => $used,
            'casual_used' => $casualUsed,
            'remaining' => $entitlement === null ? null : $entitlement - $used,
            'casual_remaining' => $casualCap === null ? null : $casualCap - $casualUsed,
            'sick_days' => $counts['sick'],
            'unpaid_days' => $counts['unpaid'],
        ];
    }

    /**
     * How many of a request's $days keep their kind; the rest become unpaid (L2). Annual keeps what the remaining balance covers,
     * casual what the smaller of the remaining and the casual balance covers. Sick and unpaid, a null balance or an unknown
     * entitlement keep every day.
     *
     * @param  array<string, ?int>|null  $balance  compute()'s result
     * @return array{keep: int, unpaid: int}
     */
    public static function split(LeaveKind $kind, int $days, ?array $balance): array
    {
        $remaining = $balance['remaining'] ?? null;
        $covered = match (true) {
            $remaining === null, $kind === LeaveKind::Sick, $kind === LeaveKind::Unpaid => $days,
            $kind === LeaveKind::Casual => min($remaining, (int) ($balance['casual_remaining'] ?? 0)),
            default => $remaining,
        };
        $keep = min($days, max(0, $covered));

        return ['keep' => $keep, 'unpaid' => $days - $keep];
    }
}

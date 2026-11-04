<?php

namespace Tests\Unit;

use App\Modules\Leave\Domain\LeaveBalance;
use App\Modules\Leave\Domain\LeaveKind;
use PHPUnit\Framework\TestCase;

class LeaveBalanceTest extends TestCase
{
    private const COUNTS = ['annual' => 5, 'casual' => 2, 'sick' => 4, 'unpaid' => 1];

    private function entitlement(?string $hire): ?int
    {
        return LeaveBalance::entitlement($hire, 2026, 10, 21);
    }

    public function test_one_year_of_service_on_jan_1_earns_the_full_entitlement(): void
    {
        $this->assertSame(21, $this->entitlement('2025-01-01'));
        $this->assertSame(21, $this->entitlement('2019-06-15'));
    }

    public function test_hired_before_the_year_with_less_than_a_year_earns_the_first_year_entitlement(): void
    {
        $this->assertSame(10, $this->entitlement('2025-01-02'));
        $this->assertSame(10, $this->entitlement('2025-12-31'));
    }

    public function test_hired_during_the_year_is_pro_rated_by_full_months(): void
    {
        $this->assertSame(10, $this->entitlement('2026-01-01'));
        $this->assertSame(5, $this->entitlement('2026-07-01'));
        $this->assertSame(4, $this->entitlement('2026-07-15'));
        $this->assertSame(0, $this->entitlement('2026-12-01'));
    }

    public function test_hired_after_the_year_earns_nothing_and_no_hire_date_is_unknown(): void
    {
        $this->assertSame(0, $this->entitlement('2027-02-01'));
        $this->assertNull($this->entitlement(null));
    }

    public function test_used_counts_annual_casual_and_opening_days(): void
    {
        $this->assertSame([
            'entitlement' => 21, 'casual_cap' => 6, 'used' => 10, 'casual_used' => 3,
            'remaining' => 11, 'casual_remaining' => 3, 'sick_days' => 4, 'unpaid_days' => 1,
        ], LeaveBalance::compute(21, 6, 3, 1, self::COUNTS));
    }

    public function test_the_casual_cap_never_exceeds_the_entitlement(): void
    {
        $this->assertSame(4, LeaveBalance::compute(4, 6, 0, 0, self::COUNTS)['casual_cap']);
    }

    public function test_over_use_leaves_a_negative_balance(): void
    {
        $b = LeaveBalance::compute(5, 6, 0, 0, ['annual' => 6, 'casual' => 1, 'sick' => 0, 'unpaid' => 0]);
        $this->assertSame(-2, $b['remaining']);
        $this->assertSame(4, $b['casual_remaining']);
    }

    public function test_an_unknown_entitlement_still_counts_the_days_used(): void
    {
        $b = LeaveBalance::compute(null, 6, 0, 0, self::COUNTS);
        $this->assertNull($b['entitlement']);
        $this->assertNull($b['casual_cap']);
        $this->assertNull($b['remaining']);
        $this->assertNull($b['casual_remaining']);
        $this->assertSame(7, $b['used']);
        $this->assertSame(2, $b['casual_used']);
    }

    public function test_annual_keeps_what_the_balance_covers_and_the_rest_goes_unpaid(): void
    {
        $b = LeaveBalance::compute(21, 6, 19, 0, ['annual' => 0, 'casual' => 0, 'sick' => 0, 'unpaid' => 0]);
        $this->assertSame(['keep' => 2, 'unpaid' => 2, 'remaining_after' => 0], LeaveBalance::split(LeaveKind::Annual, 4, $b));
        $this->assertSame(['keep' => 1, 'unpaid' => 0, 'remaining_after' => 1], LeaveBalance::split(LeaveKind::Annual, 1, $b));
    }

    public function test_a_negative_balance_keeps_nothing(): void
    {
        $b = LeaveBalance::compute(5, 6, 0, 0, ['annual' => 6, 'casual' => 0, 'sick' => 0, 'unpaid' => 0]);
        $this->assertSame(['keep' => 0, 'unpaid' => 3, 'remaining_after' => -1], LeaveBalance::split(LeaveKind::Annual, 3, $b));
    }

    public function test_casual_keeps_within_the_smaller_of_the_two_balances(): void
    {
        $b = LeaveBalance::compute(21, 6, 0, 0, ['annual' => 0, 'casual' => 5, 'sick' => 0, 'unpaid' => 0]);   // remaining 16, casual 1
        $this->assertSame(['keep' => 1, 'unpaid' => 2, 'remaining_after' => 15], LeaveBalance::split(LeaveKind::Casual, 3, $b));
        $low = LeaveBalance::compute(21, 6, 20, 0, ['annual' => 0, 'casual' => 0, 'sick' => 0, 'unpaid' => 0]);   // remaining 1, casual 6
        $this->assertSame(['keep' => 1, 'unpaid' => 2, 'remaining_after' => 0], LeaveBalance::split(LeaveKind::Casual, 3, $low));
    }

    public function test_sick_unpaid_and_an_unknown_entitlement_never_split_or_deduct(): void
    {
        $b = LeaveBalance::compute(0, 6, 0, 0, ['annual' => 0, 'casual' => 0, 'sick' => 0, 'unpaid' => 0]);
        $this->assertSame(['keep' => 3, 'unpaid' => 0, 'remaining_after' => 0], LeaveBalance::split(LeaveKind::Sick, 3, $b));
        $this->assertSame(['keep' => 3, 'unpaid' => 0, 'remaining_after' => 0], LeaveBalance::split(LeaveKind::Unpaid, 3, $b));
        $unknown = LeaveBalance::compute(null, 6, 0, 0, ['annual' => 0, 'casual' => 0, 'sick' => 0, 'unpaid' => 0]);
        $this->assertSame(['keep' => 3, 'unpaid' => 0, 'remaining_after' => null], LeaveBalance::split(LeaveKind::Annual, 3, $unknown));
        $this->assertSame(['keep' => 3, 'unpaid' => 0, 'remaining_after' => null], LeaveBalance::split(LeaveKind::Casual, 3, null));
    }
}

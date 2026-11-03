<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Employees\Application\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class LeaveBalancesTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
    }

    private function balance(string $employeeId, ?int $year = null): TestResponse
    {
        return $this->getJson("/api/v1/employees/{$employeeId}/leave".($year === null ? '' : "?year={$year}"));
    }

    private function save(string $employeeId, string $date, string $code): void
    {
        app(AttendanceDays::class)->save($employeeId, $date, ['site_id' => $this->leave[$code]]);
    }

    public function test_an_annual_day_lowers_the_remaining_balance(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->balance($e)->assertOk()
            ->assertJsonPath('data.employee_id', $e)->assertJsonPath('data.year', 2026)->assertJsonPath('data.type', 'office')
            ->assertJsonPath('data.hire_date', '2020-01-01')->assertJsonPath('data.adjustment', null)
            ->assertJsonPath('data.balance.entitlement', 21)->assertJsonPath('data.balance.remaining', 21);

        $this->save($e, '2026-03-02', 'annual');
        $this->balance($e)->assertJsonPath('data.balance.used', 1)->assertJsonPath('data.balance.remaining', 20)
            ->assertJsonPath('data.balance.casual_used', 0)->assertJsonPath('data.balance.casual_remaining', 6);
    }

    public function test_a_casual_day_lowers_both_balances(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $this->save($e, '2026-03-02', 'casual');
        $this->balance($e)->assertJsonPath('data.balance.used', 1)->assertJsonPath('data.balance.remaining', 20)
            ->assertJsonPath('data.balance.casual_used', 1)->assertJsonPath('data.balance.casual_remaining', 5);
    }

    public function test_sick_and_unpaid_days_are_counted_apart(): void
    {
        $e = $this->employee('permanent_worker', '2020-01-01');
        $this->save($e, '2026-03-02', 'sick');
        $this->save($e, '2026-03-03', 'unpaid');
        $this->balance($e)->assertJsonPath('data.balance.sick_days', 1)->assertJsonPath('data.balance.unpaid_days', 1)
            ->assertJsonPath('data.balance.used', 0)->assertJsonPath('data.balance.remaining', 21);
    }

    public function test_only_the_requested_year_counts(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->save($e, '2025-12-31', 'annual');
        $this->save($e, '2026-01-01', 'annual');
        $this->balance($e, 2025)->assertJsonPath('data.year', 2025)->assertJsonPath('data.balance.used', 1);
        $this->balance($e, 2026)->assertJsonPath('data.balance.used', 1);
        $this->balance($e, 1999)->assertStatus(422)->assertJsonPath('error.details.0.field', 'year');
    }

    public function test_the_year_adjustment_overrides_the_entitlement_and_adds_opening_days(): void
    {
        $e = $this->employee('office', '2026-07-01');
        $this->balance($e)->assertJsonPath('data.balance.entitlement', 5);
        DB::table('leave_year_adjustments')->insert([
            'id' => (string) Str::uuid7(), 'employee_id' => $e, 'year' => 2026, 'entitlement' => 8, 'opening_used' => 2, 'opening_casual_used' => 1,
            'reason' => 'Reason-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->balance($e)->assertJsonPath('data.balance.entitlement', 8)->assertJsonPath('data.balance.remaining', 6)
            ->assertJsonPath('data.balance.casual_remaining', 5)
            ->assertJsonPath('data.adjustment', ['entitlement' => 8, 'opening_used' => 2, 'opening_casual_used' => 1, 'reason' => 'Reason-1', 'version' => 1]);
    }

    public function test_no_hire_date_leaves_the_entitlement_unknown(): void
    {
        $e = $this->employee('office', '2026-01-01', ['hire_date' => null]);
        $this->balance($e)->assertOk()->assertJsonPath('data.hire_date', null)
            ->assertJsonPath('data.balance.entitlement', null)->assertJsonPath('data.balance.remaining', null);
    }

    public function test_a_day_laborer_has_no_balance(): void
    {
        $e = $this->employee('day_laborer', '2020-01-01');
        $this->balance($e)->assertOk()->assertJsonPath('data.type', 'day_laborer')->assertJsonPath('data.balance', null);
    }

    public function test_an_unknown_employee_is_404(): void
    {
        $this->balance((string) Str::uuid7())->assertNotFound();
    }

    public function test_a_manager_reads_only_employees_in_reach(): void
    {
        $mine = $this->employee('engineer', '2020-01-01');
        $other = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        app(EmployeeService::class)->assignManager($mine, ['manager_id' => $manager->id, 'effective_from' => '2026-01-01']);

        $this->actingAs($manager, 'api');
        $this->balance($mine)->assertOk()->assertJsonPath('data.balance.entitlement', 21);
        $this->balance($other)->assertNotFound();
    }

    public function test_reading_needs_leave_view(): void
    {
        $e = $this->employee('office', '2020-01-01');
        $this->signIn('ceo');
        $this->balance($e)->assertOk();
        $this->actingAs($this->userWith('all', 'all'), 'api');   // attendance only
        $this->balance($e)->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class LeaveSchemaTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const SEEDED = ['annual' => 'اعتيادية', 'casual' => 'عارضة', 'unpaid' => 'بدون أجر'];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_03_100000_create_leave_balances.php');
    }

    /** @return array<string, string> leave_code => name_ar of the live leave types with the new codes */
    private function seeded(): array
    {
        return DB::table('sites')->where(['kind' => 'leave', 'active' => true])->whereNull('deleted_at')
            ->whereIn('leave_code', array_keys(self::SEEDED))->pluck('name_ar', 'leave_code')->sort()->all();
    }

    private function adjustment(string $employeeId, int $year): void
    {
        DB::table('leave_year_adjustments')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $employeeId, 'year' => $year, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_three_leave_types_are_seeded_and_audited(): void
    {
        $expected = self::SEEDED;
        asort($expected);
        $this->assertSame($expected, $this->seeded());
        $ids = DB::table('sites')->whereIn('name_ar', self::SEEDED)->pluck('id');
        $this->assertSame(3, DB::table('audit_log')->where(['entity_type' => 'sites', 'event' => 'CREATED'])->whereIn('entity_id', $ids)->count());
    }

    public function test_attendance_accepts_the_leave_source(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office');
        $site = $this->leaveTypes()['annual'];
        DB::table('attendance_days')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $employee, 'date' => '2026-03-02', 'site_id' => $site, 'source' => 'leave', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('leave', DB::table('attendance_days')->where('employee_id', $employee)->value('source'));
    }

    public function test_an_adjustment_is_unique_per_employee_and_year_among_live_rows(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office');
        $this->adjustment($employee, 2026);
        $this->adjustment($employee, 2025);
        DB::table('leave_year_adjustments')->where('year', 2025)->update(['deleted_at' => now()]);
        $this->adjustment($employee, 2025);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->adjustment($employee, 2026);
    }

    public function test_opening_casual_days_are_part_of_the_opening_used_days(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office');
        $row = fn (int $used, int $casual) => ['id' => (string) Str::uuid7(), 'employee_id' => $employee, 'opening_used' => $used, 'opening_casual_used' => $casual, 'created_at' => now(), 'updated_at' => now()];
        DB::table('leave_year_adjustments')->insert([...$row(2, 2), 'year' => 2025]);

        $this->expectException(QueryException::class);
        DB::table('leave_year_adjustments')->insert([...$row(1, 2), 'year' => 2026]);
    }

    /** Runs down() while an attendance day uses the annual type (so down() keeps it), after $change edits that type. */
    private function keepAnnual(array $change): string
    {
        $this->signIn('hr');
        $annual = $this->leaveTypes()['annual'];
        DB::table('sites')->where('id', $annual)->update($change);
        DB::table('attendance_days')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $this->employee('office'), 'date' => '2026-03-02', 'site_id' => $annual, 'source' => 'entered', 'created_at' => now(), 'updated_at' => now()]);
        $this->migration()->down();

        return $annual;
    }

    public function test_up_reuses_a_kept_leave_type_hr_renamed(): void
    {
        $annual = $this->keepAnnual(['name_ar' => 'Annual-renamed']);
        $this->migration()->up();
        $this->assertEquals(['annual' => 'Annual-renamed', 'casual' => 'عارضة', 'unpaid' => 'بدون أجر'], $this->seeded());
        $this->assertSame($annual, DB::table('sites')->where('leave_code', 'annual')->sole()->id);
    }

    public function test_up_reactivates_a_kept_leave_type_hr_deactivated(): void
    {
        $annual = $this->keepAnnual(['active' => false]);
        $this->migration()->up();
        $this->assertEquals(['annual' => 'اعتيادية', 'casual' => 'عارضة', 'unpaid' => 'بدون أجر'], $this->seeded());
        $this->assertSame($annual, DB::table('sites')->where('leave_code', 'annual')->sole()->id);
        $this->assertSame(1, DB::table('audit_log')->where(['entity_type' => 'sites', 'entity_id' => $annual, 'event' => 'UPDATED'])->count());
    }

    public function test_up_refuses_a_live_site_that_already_has_a_seeded_name(): void
    {
        DB::table('sites')->whereIn('name_ar', self::SEEDED)->delete();
        $this->workSite('عارضة');

        try {
            $this->migration()->up();
            $this->fail('up() accepted a name clash');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('عارضة', $e->getMessage());
        }
        $this->assertSame([], $this->seeded());
    }

    public function test_down_keeps_a_leave_type_an_attendance_day_uses_and_up_runs_again(): void
    {
        $this->signIn('hr');
        $employee = $this->employee('office');
        $annual = $this->leaveTypes()['annual'];
        DB::table('attendance_days')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $employee, 'date' => '2026-03-02', 'site_id' => $annual, 'source' => 'entered', 'created_at' => now(), 'updated_at' => now()]);

        $this->migration()->down();
        $this->assertSame(['annual' => 'اعتيادية'], $this->seeded());
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('leave_year_adjustments'));
        $this->assertSame(0, DB::table('permissions')->where('resource', 'leave')->count());
        $this->assertNull(DB::table('settings')->where('key', 'sick_leave_pay_percent')->value('value'));

        $this->migration()->up();
        $this->assertCount(3, $this->seeded());
        $this->assertSame($annual, DB::table('sites')->where('leave_code', 'annual')->value('id'));
        $this->assertSame(7, DB::table('permissions')->where('resource', 'leave')->count());
    }
}

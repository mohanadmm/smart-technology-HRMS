<?php

namespace Tests\Feature;

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

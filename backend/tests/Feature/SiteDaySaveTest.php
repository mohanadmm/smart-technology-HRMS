<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The site manager's save of a site's day (manager-attendance plan, Task 8, with the 2026-11-04 amendments). */
class SiteDaySaveTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private User $m;

    private string $w1;

    private string $w2;

    private string $l1;

    private string $e1;

    /** @var array<string, string> */
    private array $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        $this->leave = $this->leaveTypes();
        [$this->s1, $this->s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
        $this->m = $this->managerOf($this->s1, $this->s2);
        $this->homeSite($this->w1 = $this->employee('permanent_worker'), $this->s1, '2026-10-01');
        $this->homeSite($this->w2 = $this->employee('permanent_worker'), $this->s2, '2026-10-01');
        $this->homeSite($this->l1 = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->e1 = $this->employee('engineer');
        app(EmployeeService::class)->assignManager($this->e1, ['manager_id' => $this->m->id, 'effective_from' => '2026-10-01']);
    }

    public function test_the_read_gives_each_row_its_shift_and_whether_it_can_be_edited(): void
    {
        $this->actingAs($this->m, 'api');
        $row = $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}")->assertOk()->json('data.rows.0');

        $this->assertSame(['start' => '08:00', 'end' => '17:00'], $row['shift']);   // the field shift setting (R19 open)
        $this->assertSame([true, null], [$row['can_edit'], $row['why_not']]);
    }

    public function test_morning_then_evening_saves_the_times_with_source_manager(): void
    {
        $this->actingAs($this->m, 'api');
        $r = $this->save($this->s1, [
            $this->work($this->w1, '08:00'),
            $this->work($this->l1, '08:20'),
            $this->work($this->e1, '08:00'),
        ])->assertOk();
        $this->assertSame([1, 1, 1], $r->json('data.rows.*.day.version'));
        $this->assertSame([null, null, null], $r->json('data.rows.*.day.out_time'));
        $this->assertSame(3, DB::table('attendance_days')->where(['date' => '2026-10-22', 'source' => 'manager'])->count());
        $this->assertSame(20, $this->stored($this->l1)->late_minutes);            // raw minutes: the grace is taken at payroll (R18 default)
        $this->assertNull($this->stored($this->l1)->ot_minutes);

        $this->cairo('2026-10-22 17:00');
        $this->save($this->s1, [
            $this->work($this->w1, '08:00', '15:00', 1),                          // left early
            $this->work($this->l1, '08:20', '18:30', 1),                          // stayed late
            $this->work($this->e1, '08:00', '17:00', 1),
        ])->assertOk();
        $this->assertSame(['15:00:00', '18:30:00', '17:00:00'], [$this->stored($this->w1)->out_time, $this->stored($this->l1)->out_time, $this->stored($this->e1)->out_time]);
        $this->assertSame(0, $this->stored($this->l1)->ot_minutes);               // held until HR approves overtime
    }

    public function test_absent_sick_rest_and_other_are_allowed_and_request_leave_is_not(): void
    {
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [['employee_id' => $this->w1, 'site_id' => $this->leave['absent']], ['employee_id' => $this->l1, 'site_id' => $this->leave['sick']]])->assertOk();
        foreach (['annual', 'casual', 'unpaid', 'friday'] as $code) {
            $this->save($this->s1, [['employee_id' => $this->e1, 'site_id' => $this->leave[$code]]])->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows.0.site_id');
        }
        $this->save($this->s1, [['employee_id' => $this->e1, 'site_id' => $this->s2]])->assertStatus(422)->assertJsonPath('error.details.0.field', 'rows.0.site_id');
    }

    public function test_yesterday_is_writable_until_noon_only(): void
    {
        $this->actingAs($this->m, 'api');
        $this->cairo('2026-10-22 11:59');
        $this->save($this->s1, [$this->work($this->w1, '08:00')], '2026-10-21')->assertOk();
        $this->cairo('2026-10-22 12:00');
        $this->save($this->s1, [$this->work($this->w1, '08:00', null, 1)], '2026-10-21')->assertStatus(422)
            ->assertJsonPath('error.code', 'outside_manager_window')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}&date=2026-10-21")->assertOk()->assertJsonPath('data.rows.0.why_not', 'window');
        $this->save($this->s1, [$this->work($this->w1, '08:00')], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'outside_manager_window');
        $this->save($this->s1, [$this->work($this->w1, '08:00')], '2026-10-23')->assertStatus(422)->assertJsonPath('error.code', 'future_date');
    }

    public function test_two_site_managers_on_one_site_the_second_save_is_a_conflict(): void
    {
        $other = $this->managerOf($this->s1);
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertOk();
        $this->actingAs($other, 'api');
        $this->save($this->s1, [$this->work($this->w1, '09:00')])->assertStatus(409);
        $this->assertSame('08:00:00', $this->stored($this->w1)->in_time);
    }

    public function test_a_body_cannot_reach_hidden_fields_or_other_sites(): void
    {
        $this->putDay($this->w1, '2026-10-22', ['site_id' => $this->s1, 'in_time' => '08:00', 'transport' => 'half'])->assertOk();
        $this->actingAs($this->m, 'api');
        $this->save($this->s1, [[...$this->work($this->w1, '08:00', '16:00', 1), 'transport' => 'none', 'advance_amount' => '500', 'late_minutes' => 99]])->assertOk();
        $day = $this->stored($this->w1);
        $this->assertSame(['half', null, 'manager'], [$day->transport, $day->advance_amount, $day->source]);

        $this->save($this->s1, [$this->work($this->w2, '08:00')])->assertNotFound();                       // Site-2's worker on Site-1's list
        $s3 = $this->workSite('Site-3');
        $this->managerOf($s3);
        $this->save($s3, [$this->work($this->w1, '08:00')])->assertNotFound();                             // another manager's site
    }

    public function test_only_hr_changes_a_day_of_annual_casual_or_unpaid_leave(): void
    {
        $this->putDay($this->w1, '2026-10-22', ['site_id' => $this->leave['annual']])->assertOk();
        $this->actingAs($this->m, 'api');
        $row = $this->getJson("/api/v1/attendance/site-day?site_id={$this->s1}")->json('data.rows.0');
        $this->assertSame([false, 'leave_request'], [$row['can_edit'], $row['why_not']]);

        $this->save($this->s1, [$this->work($this->w1, '08:00', null, 1)])->assertStatus(422)
            ->assertJsonPath('error.code', 'leave_from_request')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->save($this->s1, [['employee_id' => $this->w1, 'version' => 1, 'clear' => true]])->assertStatus(422)->assertJsonPath('error.code', 'leave_from_request');
        $this->save($this->s1, [['employee_id' => $this->w1, 'version' => 1, 'site_id' => $this->leave['annual']]])->assertOk();   // unchanged
        $this->assertNotNull(DB::table('attendance_days')->where(['employee_id' => $this->w1, 'site_id' => $this->leave['annual']])->first());
    }

    public function test_hr_and_ceo_cannot_save(): void
    {
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertForbidden();
        $this->actingAs($this->makeUser('ceo'), 'api');
        $this->save($this->s1, [$this->work($this->w1, '08:00')])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function work(string $employeeId, ?string $in, ?string $out = null, ?int $version = null): array
    {
        return ['employee_id' => $employeeId, 'version' => $version, 'site_id' => $this->s1, 'in_time' => $in, 'out_time' => $out];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function save(string $siteId, array $rows, string $date = '2026-10-22'): TestResponse
    {
        return $this->postJson('/api/v1/attendance/site-day', ['site_id' => $siteId, 'date' => $date, 'rows' => $rows]);
    }

    private function stored(string $employeeId): object
    {
        return DB::table('attendance_days')->where(['employee_id' => $employeeId, 'date' => '2026-10-22'])->whereNull('deleted_at')->firstOrFail();
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** Today's day-labor crew on a site manager's site, and the day laborers they propose (owner, 2026-11-04). */
class SiteDayCrewTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private string $s3;

    private User $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        [$this->s1, $this->s2, $this->s3] = [$this->workSite('Site-1'), $this->workSite('Site-2'), $this->workSite('Site-3')];
        $this->m = $this->managerOf($this->s1, $this->s2);
        $this->managerOf($this->s3);
    }

    public function test_search_says_where_each_laborer_is_and_who_can_be_added(): void
    {
        $free = $this->employee('day_laborer');                                        // Laborer-1, no site
        $this->homeSite($mine = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->homeSite($theirs = $this->employee('day_laborer'), $this->s3, '2026-10-01');
        $this->homeSite($this->employee('permanent_worker'), $this->s2, '2026-10-01'); // never a match: not a day laborer

        $this->actingAs($this->m, 'api');
        $rows = collect($this->getJson("/api/v1/attendance/site-day/laborers?site_id={$this->s1}&search=Labor")->assertOk()->json('data'))->keyBy('id');
        $this->assertCount(3, $rows);
        $this->assertSame([null, true], [$rows[$free]['site'], $rows[$free]['can_add']]);
        $this->assertSame(['Site-2', true], [$rows[$mine]['site']['name_ar'], $rows[$mine]['can_add']]);
        $this->assertSame(['Site-3', false], [$rows[$theirs]['site']['name_ar'], $rows[$theirs]['can_add']]);
        $this->assertStringNotContainsString('wage', $this->getJson("/api/v1/attendance/site-day/laborers?site_id={$this->s1}&search=Labor")->getContent());
    }

    public function test_add_and_remove_change_today_only_and_keep_yesterday_and_tomorrow(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-21', 'rows' => [['employee_id' => $l, 'site_id' => $this->s1, 'in_time' => '08:00', 'out_time' => '16:00']]])->assertOk();

        $this->crew($this->s1, $l, 'remove')->assertOk();
        $this->assertNotContains($l, $this->roster($this->s1, '2026-10-22'));
        $this->assertContains($l, $this->roster($this->s1, '2026-10-21'));                            // yesterday's list and day stay
        $this->assertSame('16:00:00', DB::table('attendance_days')->where(['employee_id' => $l, 'date' => '2026-10-21'])->value('out_time'));
        $this->assertSame(1, DB::table('employee_site_assignments')->where('employee_id', $l)->count());   // the home site is untouched
        $this->assertNull(DB::table('employees')->where('id', $l)->value('deleted_at'));
        $this->assertSame(0, DB::table('attendance_days')->where(['employee_id' => $l, 'date' => '2026-10-22'])->count());   // not an absence

        $this->cairo('2026-10-23 09:00');
        $this->assertContains($l, $this->roster($this->s1, '2026-10-23'));                            // tomorrow: the normal assignment
        $this->cairo('2026-10-22 09:00');

        $this->crew($this->s1, $l, 'add')->assertOk();                                                // back home: the day's change goes
        $this->assertContains($l, $this->roster($this->s1, '2026-10-22'));
        $this->assertSame(0, DB::table('day_crew_changes')->where('employee_id', $l)->whereNull('deleted_at')->count());
    }

    public function test_moving_a_laborer_between_the_managers_sites_holds_for_today_and_their_day_can_be_saved(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->crew($this->s1, $l, 'add')->assertOk();
        $this->assertContains($l, $this->roster($this->s1, '2026-10-22'));
        $this->assertNotContains($l, $this->roster($this->s2, '2026-10-22'));
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $l, 'site_id' => $this->s1, 'in_time' => '08:00']]])->assertOk();

        $this->cairo('2026-10-23 09:00');
        $this->assertNotContains($l, $this->roster($this->s1, '2026-10-23'));
        $this->assertContains($l, $this->roster($this->s2, '2026-10-23'));
    }

    public function test_a_laborer_with_a_day_saved_at_another_site_is_not_moved(): void
    {
        $this->homeSite($l = $this->employee('day_laborer'), $this->s2, '2026-10-01');
        $this->actingAs($this->m, 'api');
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s2, 'date' => '2026-10-22', 'rows' => [['employee_id' => $l, 'site_id' => $this->s2, 'in_time' => '08:00']]])->assertOk();
        $this->crew($this->s1, $l, 'add')->assertStatus(422)->assertJsonPath('error.details.0.field', 'employee_id');
        $this->assertContains($l, $this->roster($this->s2, '2026-10-22'));
    }

    public function test_the_rules_on_adding_and_removing(): void
    {
        $this->homeSite($theirs = $this->employee('day_laborer'), $this->s3, '2026-10-01');
        $this->homeSite($worker = $this->employee('permanent_worker'), $this->s1, '2026-10-01');
        $this->homeSite($recorded = $this->employee('day_laborer'), $this->s1, '2026-10-01');
        $this->actingAs($this->m, 'api');

        $this->crew($this->s1, $theirs, 'add')->assertStatus(422)->assertJsonPath('error.details.0.field', 'employee_id');
        $this->crew($this->s1, $worker, 'add')->assertNotFound();                                     // only day laborers
        $this->crew($this->s1, $worker, 'remove')->assertNotFound();
        $this->crew($this->s3, $theirs, 'remove')->assertNotFound();                                  // not this manager's site
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $recorded, 'site_id' => $this->s1, 'in_time' => '08:00']]])->assertOk();
        $this->crew($this->s1, $recorded, 'remove')->assertStatus(422);                               // clear today's day first
    }

    public function test_a_proposed_laborer_joins_today_with_no_pay_row_until_hr_approves(): void
    {
        $this->actingAs($this->m, 'api');
        $id = $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'عامل جديد', 'daily_wage' => '350', 'phone' => '01012345678'])
            ->assertCreated()->json('data.id');
        $this->assertContains($id, $this->roster($this->s1, '2026-10-22'));
        $this->assertSame(0, DB::table('employee_pay_changes')->where('employee_id', $id)->count());
        $this->assertSame(0, DB::table('employee_site_assignments')->where('employee_id', $id)->count());     // today's crew only
        $this->postJson('/api/v1/attendance/site-day', ['site_id' => $this->s1, 'date' => '2026-10-22', 'rows' => [['employee_id' => $id, 'site_id' => $this->s1, 'in_time' => '08:00']]])->assertOk();

        $proposal = DB::table('employee_wage_proposals')->where('employee_id', $id)->first();
        $this->assertSame(['pending', '350.000000'], [$proposal->status, $proposal->daily_wage]);
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1])->assertForbidden();   // the site manager
        $this->getJson("/api/v1/employees/{$id}")->assertOk()->assertJsonMissingPath('data.current_pay');

        $this->actingAs($this->makeUser('hr'), 'api');
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1])->assertStatus(422)->assertJsonPath('error.details.0.field', 'gender');
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1, 'gender' => 'male', 'daily_wage' => '340'])->assertOk();
        $this->assertSame(['2026-10-22', '340.000000'], [
            (string) DB::table('employee_pay_changes')->where('employee_id', $id)->value('effective_from'),
            (string) DB::table('employee_pay_changes')->where('employee_id', $id)->value('daily_wage'),
        ]);
        $this->postJson("/api/v1/employees/wage-proposals/{$proposal->id}/approve", ['version' => 1, 'gender' => 'male'])->assertStatus(409);
    }

    public function test_a_site_manager_cannot_create_engineers_or_permanent_workers_or_set_pay(): void
    {
        $this->actingAs($this->m, 'api');
        $this->postJson('/api/v1/employees', ['name_ar' => 'X', 'gender' => 'male', 'type' => 'permanent_worker', 'effective_from' => '2026-10-22', 'salary' => '9000'])->assertForbidden();
        $this->postJson('/api/v1/employees', ['name_ar' => 'X', 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-10-22', 'salary' => '9000'])->assertForbidden();
        $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'X', 'daily_wage' => '0'])->assertStatus(422);
        $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s3, 'name_ar' => 'X', 'daily_wage' => '300'])->assertNotFound();
        $this->postJson('/api/v1/attendance/site-day/laborers', ['site_id' => $this->s1, 'name_ar' => 'X', 'daily_wage' => '300', 'type' => 'engineer'])->assertCreated();
        $this->assertSame('day_laborer', DB::table('employee_type_changes')->orderByDesc('id')->value('type'));   // the body's type is ignored
        $this->getJson('/api/v1/employees/wage-proposals')->assertForbidden();
    }

    private function crew(string $siteId, string $employeeId, string $action): TestResponse
    {
        return $this->postJson('/api/v1/attendance/site-day/crew', ['site_id' => $siteId, 'employee_id' => $employeeId, 'action' => $action]);
    }

    /** @return list<string> */
    private function roster(string $siteId, string $date): array
    {
        return $this->getJson("/api/v1/attendance/site-day?site_id={$siteId}&date={$date}")->assertOk()->json('data.rows.*.employee.id');
    }
}

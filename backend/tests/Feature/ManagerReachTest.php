<?php

namespace Tests\Feature;

use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** A manager reaches the workers homed on their sites and the engineers assigned to them, on the date read or written (design Part 1). */
class ManagerReachTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private string $s1;

    private string $s2;

    private User $m;

    private string $w1;

    private string $e1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-22 09:00');
        $this->signIn('hr');
        [$this->s1, $this->s2] = [$this->workSite('Site-1'), $this->workSite('Site-2')];
        $this->m = $this->managerOf($this->s1);
        $this->w1 = $this->employee('permanent_worker');
        $this->homeSite($this->w1, $this->s1, '2026-10-01');
        $this->e1 = $this->employee('engineer');
        $this->assignManager($this->e1, $this->m);
    }

    public function test_a_manager_reaches_workers_on_their_sites_and_their_engineers(): void
    {
        $this->assignManager($this->employee('office'), $this->m);              // P2: office staff leave every manager's reach
        $this->homeSite($this->employee('permanent_worker'), $this->s2, '2026-10-01');

        $this->actingAs($this->m, 'api');
        $this->assertSame(['Worker-1', 'Engineer-1'], $this->listedNames());
        $this->getJson("/api/v1/employees/{$this->w1}")->assertOk();
    }

    public function test_reach_follows_the_date_read_not_today(): void
    {
        app(EmployeeService::class)->assignSite($this->w1, ['site_id' => $this->s2, 'effective_from' => '2026-10-21', 'returns_on' => '2026-10-22']);
        $service = app(EmployeeService::class);
        $this->assertFalse($service->isManagedBy($this->w1, $this->m->id, '2026-10-21'));
        $this->assertTrue($service->isManagedBy($this->w1, $this->managerOf($this->s2)->id, '2026-10-21'));
        $this->assertTrue($service->isManagedBy($this->w1, $this->m->id));                    // today, back on Site-1

        $lead = $this->userWith('own', null);
        DB::table('manager_sites')->insert(['manager_id' => $lead->id, 'site_id' => $this->s1]);
        $this->actingAs($lead, 'api');
        $this->getJson("/api/v1/attendance/days/{$this->w1}/2026-10-21")->assertNotFound();
        $this->getJson("/api/v1/attendance/days/{$this->w1}/2026-10-22")->assertOk();
        $this->getJson("/api/v1/attendance/days/{$this->w1}/2026-02-30")->assertStatus(422);   // reached as today, then refused by the controller
    }

    public function test_reach_changes_at_cairo_midnight(): void
    {
        $this->homeSite($this->w1, $this->s2, '2026-10-23');
        $other = $this->managerOf($this->s2);

        $this->travelTo('2026-10-22 20:30:00');                                                 // 23:30 in Cairo
        $this->actingAs($this->m, 'api');
        $this->assertSame(['Worker-1', 'Engineer-1'], $this->listedNames());
        $this->travelTo('2026-10-22 21:30:00');                                                 // 2026-10-23 00:30 in Cairo
        $this->assertSame(['Engineer-1'], $this->listedNames());
        $this->actingAs($other, 'api');
        $this->assertSame(['Worker-1'], $this->listedNames());
    }

    public function test_an_own_scope_writer_cannot_use_a_site_outside_their_sites(): void
    {
        $s3 = $this->workSite('Site-3');
        $sick = $this->leaveTypes()['sick'];
        $writer = $this->userWith(null, 'own');
        DB::table('manager_sites')->insert([['manager_id' => $writer->id, 'site_id' => $this->s1], ['manager_id' => $writer->id, 'site_id' => $s3]]);
        $this->assignManager($this->e1, $writer, '2026-10-02');
        $date = '2026-10-22';

        $this->actingAs($writer, 'api');
        $this->postJson('/api/v1/attendance/days/bulk', ['date' => $date, 'rows' => [['employee_id' => $this->e1, 'site_id' => $this->s2]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach')->assertJsonPath('error.details.0.field', 'rows.0');
        $this->putDay($this->w1, $date, ['site_id' => $s3])->assertStatus(422)->assertJsonPath('error.code', 'site_not_in_reach');   // not his home site
        $this->assertSame(0, AttendanceDay::count());

        $this->putDay($this->w1, $date, ['site_id' => $this->s1])->assertOk();
        $this->postJson('/api/v1/attendance/days/bulk', ['date' => $date, 'rows' => [['employee_id' => $this->e1, 'site_id' => $s3]]])->assertOk();   // engineers float
        $this->putDay($this->w1, '2026-10-21', ['site_id' => $sick])->assertOk();
    }

    public function test_an_own_scope_writer_cannot_record_leave_that_needs_a_request(): void
    {
        $leave = $this->leaveTypes();
        $writer = $this->userWith(null, 'own');
        DB::table('manager_sites')->insert(['manager_id' => $writer->id, 'site_id' => $this->s1]);
        $this->assignManager($this->e1, $writer, '2026-10-02');
        $bulk = fn (string $code, string $date = '2026-10-22') => $this->postJson('/api/v1/attendance/days/bulk', [
            'date' => $date, 'rows' => [['employee_id' => $this->e1, 'site_id' => $leave[$code]]],
        ]);

        $this->actingAs($writer, 'api');
        foreach (['annual', 'casual', 'unpaid'] as $code) {
            $bulk($code)->assertStatus(422)->assertJsonPath('error.code', 'leave_needs_request')
                ->assertJsonPath('error.details.0.field', 'rows.0')
                ->assertJsonPath('error.details.0.message', 'Annual, casual and unpaid leave need a leave request.');
        }
        $this->assertSame(0, AttendanceDay::count());
        $bulk('sick', '2026-10-21')->assertOk();
        $bulk('absent')->assertOk();

        $this->actingAs($this->makeUser('hr'), 'api');
        $bulk('annual', '2026-10-20')->assertOk();
        $this->assertSame(3, AttendanceDay::where('employee_id', $this->e1)->count());
    }

    public function test_an_own_scope_writer_keeps_leave_already_stored_but_cannot_switch_to_it(): void
    {
        $leave = $this->leaveTypes();
        $writer = $this->userWith(null, 'own');
        DB::table('manager_sites')->insert(['manager_id' => $writer->id, 'site_id' => $this->s1]);
        $this->assignManager($this->e1, $writer, '2026-10-02');
        $version = fn (string $date) => AttendanceDay::where('employee_id', $this->e1)->whereDate('date', $date)->value('version');
        $bulk = fn (string $siteId, string $date, array $extra = []) => $this->postJson('/api/v1/attendance/days/bulk', [
            'date' => $date, 'rows' => [['employee_id' => $this->e1, 'site_id' => $siteId, 'version' => $version($date), ...$extra]],
        ]);
        $this->actingAs($this->makeUser('hr'), 'api');
        $bulk($leave['annual'], '2026-10-20')->assertOk();
        $bulk($leave['sick'], '2026-10-21')->assertOk();

        $this->actingAs($writer, 'api');
        $bulk($leave['annual'], '2026-10-20', ['note' => 'resubmitted'])->assertOk();
        $this->assertSame('resubmitted', AttendanceDay::where('employee_id', $this->e1)->whereDate('date', '2026-10-20')->value('note'));
        $bulk($leave['casual'], '2026-10-21')->assertStatus(422)->assertJsonPath('error.code', 'leave_needs_request');
        $bulk($leave['casual'], '2026-10-20')->assertStatus(422)->assertJsonPath('error.code', 'leave_needs_request');
        $bulk($this->s1, '2026-10-20')->assertOk();
    }

    private function assignManager(string $employeeId, User $manager, string $from = '2026-10-01'): void
    {
        app(EmployeeService::class)->assignManager($employeeId, ['manager_id' => $manager->id, 'effective_from' => $from]);
    }

    /** @return list<string> in code order */
    private function listedNames(): array
    {
        return $this->getJson('/api/v1/employees')->assertOk()->json('data.*.name_ar');
    }
}

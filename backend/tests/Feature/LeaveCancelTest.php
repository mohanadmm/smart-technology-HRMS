<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\AttendanceDays;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Application\LeaveBalances;
use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** Cancelling a leave request (leave spec, Workflow Cancel; Slice 6 ledger D1–D4). Today is 2026-10-20; 2026-10-16 is a Friday. */
class LeaveCancelTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var list<User> */
    private array $hr;

    private User $ceo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 12:00');
        $this->hr = [$this->makeUser('hr'), $this->makeUser('hr')];
        $this->ceo = $this->makeUser('ceo');
        $this->actingAs($this->hr[0], 'api');
    }

    private function submit(string $employeeId, string $from, string $to, string $kind = 'annual'): string
    {
        return $this->postJson('/api/v1/leave/requests', ['employee_id' => $employeeId, 'kind' => $kind, 'from_date' => $from, 'to_date' => $to])->assertCreated()->json('data.id');
    }

    /** @param  array<string, mixed>  $body */
    private function act(string $id, string $action, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/leave/requests/{$id}/{$action}", ['version' => $this->version($id), ...$body]);
    }

    private function version(string $id): int
    {
        return (int) DB::table('leave_requests')->where('id', $id)->value('version');
    }

    private function statusOf(string $id): string
    {
        return (string) DB::table('leave_requests')->where('id', $id)->value('status');
    }

    private function assign(string $employeeId, User $manager): void
    {
        app(EmployeeService::class)->assignManager($employeeId, ['manager_id' => $manager->id, 'effective_from' => '2026-01-01']);
    }

    /** @param  array<string, mixed>  $values */
    private function resave(string $employeeId, string $date, array $values): void
    {
        $version = DB::table('attendance_days')->where(['employee_id' => $employeeId, 'date' => $date])->whereNull('deleted_at')->value('version');
        app(AttendanceDays::class)->save($employeeId, $date, ['version' => $version, ...$values]);
    }

    /** @return array<string, string> date => "leave_code or work/source" of the employee's live days */
    private function stored(string $employeeId): array
    {
        return DB::table('attendance_days')->join('sites', 'sites.id', '=', 'attendance_days.site_id')
            ->where('employee_id', $employeeId)->whereNull('attendance_days.deleted_at')->orderBy('date')->get(['date', 'leave_code', 'kind', 'source'])
            ->mapWithKeys(fn (object $r) => [substr((string) $r->date, 0, 10) => ($r->leave_code ?? $r->kind).'/'.$r->source])->all();
    }

    public function test_cancelling_an_approved_request_clears_only_the_days_it_still_holds(): void
    {
        $e = $this->employee('engineer', '2026-07-01');   // pro-rata entitlement 5
        $leave = $this->leaveTypes();
        app(AttendanceDays::class)->save($e, '2026-10-13', ['site_id' => $leave['annual']]);   // HR's own day, before the request
        $before = app(LeaveBalances::class)->forEmployee($e, 2026)['balance'] ?? [];
        $id = $this->submit($e, '2026-10-12', '2026-10-22');
        $this->act($id, 'approve')->assertOk();
        $this->assertSame([
            '2026-10-12' => 'annual/leave', '2026-10-13' => 'annual/entered', '2026-10-14' => 'annual/leave', '2026-10-15' => 'annual/leave',
            '2026-10-17' => 'annual/leave', '2026-10-18' => 'unpaid/leave', '2026-10-19' => 'unpaid/leave', '2026-10-20' => 'unpaid/leave',
            '2026-10-21' => 'unpaid/leave', '2026-10-22' => 'unpaid/leave',
        ], $this->stored($e));
        $this->resave($e, '2026-10-14', ['site_id' => $this->workSite()]);   // HR moved it to a work site
        $this->resave($e, '2026-10-15', ['site_id' => $leave['annual']]);   // re-saved on the same leave type
        $this->resave($e, '2026-10-17', ['site_id' => $leave['sick']]);     // changed to another leave type

        $this->act($id, 'cancel', ['reason' => 'Reason-1'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.decided_by', ['id' => $this->hr[0]->id, 'name' => 'hr'])
            ->assertJsonPath('data.decision_reason', 'Reason-1')->assertJsonPath('data.approval', null)->assertJsonPath('data.version', 3);
        $this->assertSame(['2026-10-13' => 'annual/entered', '2026-10-14' => 'work/entered', '2026-10-17' => 'sick/entered'], $this->stored($e));
        $this->assertSame($before['remaining'], app(LeaveBalances::class)->forEmployee($e, 2026)['balance']['remaining'] ?? null);
    }

    public function test_a_leave_day_holding_a_note_or_an_advance_blocks_the_cancel_and_nothing_changes(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-14');
        $this->act($id, 'approve')->assertOk();
        $annual = $this->leaveTypes()['annual'];
        $this->resave($e, '2026-10-12', ['site_id' => $annual, 'note' => 'Note-1']);
        $this->resave($e, '2026-10-14', ['site_id' => $annual, 'advance_amount' => '100']);

        $this->act($id, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'leave_cancel_blocked')
            ->assertJsonPath('error.dates', ['2026-10-12', '2026-10-14'])
            ->assertJsonPath('error.message', 'Leave days to clear hold a note or an advance; change them in attendance first: 2.');
        $this->withHeader('Accept-Language', 'ar')->act($id, 'cancel')->assertStatus(409)
            ->assertJsonPath('error.message', 'عدد أيام الإجازة التي تحمل ملاحظة أو سلفة: 2. عدّلها في الحضور أولًا.');
        $this->assertSame(['2026-10-12' => 'annual/leave', '2026-10-13' => 'annual/leave', '2026-10-14' => 'annual/leave'], $this->stored($e));
        $this->assertSame(['approved', 2], [$this->statusOf($id), $this->version($id)]);
    }

    public function test_a_date_in_a_locked_period_refuses_the_whole_cancel(): void
    {
        $e = $this->employee('engineer', '2026-09-26');
        $id = $this->submit($e, '2026-10-22', '2026-10-27');   // 10-26 starts the next, open period
        $this->act($id, 'approve')->assertOk();
        $this->leaveTypes();   // the lock stores the derived Fridays
        $this->postJson('/api/v1/attendance/locks', ['group' => 'engineer', 'period_start' => '2026-09-26', 'confirm_missing' => true])->assertCreated();

        $this->act($id, 'cancel')->assertStatus(423)->assertJsonPath('error.code', 'period_locked');
        $this->assertCount(5, array_filter($this->stored($e), fn (string $day) => in_array($day, ['annual/leave', 'unpaid/leave'], true)));
        $this->assertSame('approved', $this->statusOf($id));
    }

    public function test_a_manager_cancels_only_their_own_undecided_requests_within_reach(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $byHr = $this->submit($e, '2026-11-23', '2026-11-24');
        $this->actingAs($manager, 'api');
        $pending = $this->submit($e, '2026-11-02', '2026-11-03');
        $approved = $this->submit($e, '2026-11-09', '2026-11-10');
        $this->actingAs($this->hr[0], 'api');
        $this->act($approved, 'approve')->assertOk();

        $this->actingAs($manager, 'api');
        $this->act($pending, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.decided_by.id', $manager->id);
        $this->act($approved, 'cancel')->assertForbidden();
        $this->act($byHr, 'cancel')->assertForbidden();
        $this->actingAs($this->makeUser('manager'), 'api');
        $this->act($approved, 'cancel')->assertNotFound();
        $this->assertSame(['approved', 'pending'], [$this->statusOf($approved), $this->statusOf($byHr)]);
    }

    public function test_hr_cancels_any_undecided_request_and_only_a_decide_all_holder_an_approved_one(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $this->actingAs($manager, 'api');
        $pending = $this->submit($e, '2026-11-02', '2026-11-03');
        $this->actingAs($this->hr[0], 'api');
        $escalated = $this->submit($e, '2026-11-09', '2026-11-10');
        $this->act($escalated, 'escalate')->assertOk();
        $approved = $this->submit($e, '2026-11-16', '2026-11-17');
        $this->act($approved, 'approve')->assertOk();

        $this->actingAs($this->ceo, 'api');
        $this->act($escalated, 'cancel')->assertForbidden();
        $this->act($approved, 'cancel')->assertForbidden();
        $this->actingAs($this->hr[1], 'api');
        $this->act($pending, 'cancel')->assertOk();
        $this->act($escalated, 'cancel')->assertOk();
        $this->act($approved, 'cancel')->assertOk();
        $this->assertSame([], $this->stored($e));
    }

    public function test_a_decided_request_or_a_stale_version_is_a_conflict(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $refused = $this->submit($e, '2026-11-02', '2026-11-03');
        $this->act($refused, 'refuse', ['reason' => 'R'])->assertOk();
        $id = $this->submit($e, '2026-11-09', '2026-11-10');

        $this->act($refused, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->act($id, 'cancel', ['version' => 7])->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->act($id, 'cancel', ['reason' => str_repeat('x', 1001)])->assertStatus(422);
        $this->postJson("/api/v1/leave/requests/{$id}/cancel", [])->assertStatus(422);
        $this->act($id, 'cancel')->assertOk()->assertJsonPath('data.decision_reason', null);
        $this->act($id, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'concurrency_conflict');
        $this->postJson('/api/v1/leave/requests/'.Str::uuid7().'/cancel', ['version' => 1])->assertNotFound();
    }

    public function test_the_requester_and_the_deciders_hear_of_the_cancel_but_not_the_canceller(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $manager = $this->makeUser('manager');
        $this->assign($e, $manager);
        $this->actingAs($manager, 'api');
        $escalated = $this->submit($e, '2026-11-02', '2026-11-03');
        $this->actingAs($this->hr[0], 'api');
        $this->act($escalated, 'escalate')->assertOk();
        $approved = $this->submit($e, '2026-11-09', '2026-11-10');
        $this->act($approved, 'approve')->assertOk();
        Notification::query()->delete();

        $this->actingAs($this->hr[1], 'api');
        $this->act($escalated, 'cancel')->assertOk();
        $this->actingAs($this->hr[0], 'api');
        $this->act($approved, 'cancel')->assertOk();

        $sent = Notification::where('type', 'leave_cancelled')->get();
        $this->assertEqualsCanonicalizing([$manager->id, $this->hr[0]->id, $this->ceo->id], $sent->where('ref', $escalated)->pluck('user_id')->all());
        $this->assertSame([$this->hr[1]->id], $sent->where('ref', $approved)->pluck('user_id')->all());
        $this->assertSame(4, Notification::count());
        $this->assertEquals(
            ['employee_id' => $e, 'request_id' => $approved, 'kind' => 'annual', 'from_date' => '2026-11-09', 'to_date' => '2026-11-10', 'days' => 2],
            $sent->firstWhere('ref', $approved)?->data,
        );
    }

    public function test_the_cancel_and_the_cleared_days_are_audited(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $id = $this->submit($e, '2026-10-12', '2026-10-13');
        $this->act($id, 'approve')->assertOk();
        $this->act($id, 'cancel')->assertOk();

        $update = DB::table('audit_log')->where(['entity_type' => 'leave_requests', 'entity_id' => $id, 'event' => 'UPDATED'])->orderBy('occurred_at')->orderBy('id')->get()->last();
        $this->assertSame(['approved', 'cancelled'], [json_decode((string) $update?->old_values, true)['status'], json_decode((string) $update?->new_values, true)['status']]);
        $deleted = DB::table('audit_log')->where(['entity_type' => 'attendance_days', 'event' => 'DELETED'])->pluck('old_values')
            ->map(fn ($v) => json_decode((string) $v, true))->filter(fn (array $v) => $v['employee_id'] === $e)->map(fn (array $v) => substr((string) $v['date'], 0, 10))->sort()->values()->all();
        $this->assertSame(['2026-10-12', '2026-10-13'], $deleted);
    }

    public function test_deleting_an_employee_cancels_their_undecided_requests_only(): void
    {
        $e = $this->employee('engineer', '2020-01-01');
        $pending = $this->submit($e, '2026-11-02', '2026-11-03');
        $escalated = $this->submit($e, '2026-11-09', '2026-11-10');
        $this->act($escalated, 'escalate')->assertOk();
        $approved = $this->submit($e, '2026-11-16', '2026-11-17');
        $this->act($approved, 'approve')->assertOk();
        Notification::query()->delete();

        $this->deleteJson("/api/v1/employees/{$e}")->assertSuccessful();
        $this->assertSame(['cancelled', 'cancelled', 'approved'], [$this->statusOf($pending), $this->statusOf($escalated), $this->statusOf($approved)]);
        $this->assertSame($this->hr[0]->id, DB::table('leave_requests')->where('id', $pending)->value('decided_by'));
        $this->assertSame(0, Notification::count());
        $audited = DB::table('audit_log')->where(['entity_type' => 'leave_requests', 'event' => 'UPDATED'])->whereIn('entity_id', [$pending, $escalated])->pluck('new_values')
            ->map(fn ($v) => json_decode((string) $v, true)['status'] ?? null)->filter(fn ($s) => $s === 'cancelled')->count();
        $this->assertSame(2, $audited);
    }
}

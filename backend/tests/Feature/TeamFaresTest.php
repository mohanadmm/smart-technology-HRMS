<?php

namespace Tests\Feature;

use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class TeamFaresTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-11-05 10:00');
    }

    private function residence(string $name = 'R-1'): string
    {
        return (string) Residence::create(['name_ar' => $name])->getKey();
    }

    private function fare(string $residence, string $site, string $from, string $amount): string
    {
        return (string) Fare::create(['residence_id' => $residence, 'site_id' => $site, 'effective_from' => $from, 'amount' => $amount])->getKey();
    }

    public function test_a_team_lead_reads_only_fares_of_their_sites(): void
    {
        [$s1, $s2] = [$this->workSite('S-1'), $this->workSite('S-2')];
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '30');
        $this->fare($r, $s2, '2026-01-01', '40');
        $lead = $this->managerOf($s1);
        $other = $this->managerOf($s2);

        $this->actingAs($lead, 'api');
        $this->getJson('/api/v1/fares')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $s1);
        $this->getJson("/api/v1/fares?manager_id={$other->id}")->assertOk()->assertJsonCount(0, 'data');

        $this->signIn('ceo');
        $this->getJson('/api/v1/fares')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_hr_filters_fares_by_team_lead(): void
    {
        [$s1, $s2] = [$this->workSite('S-1'), $this->workSite('S-2')];
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '30');
        $this->fare($r, $s2, '2026-01-01', '40');
        $lead = $this->managerOf($s1);

        $this->signIn('hr');
        $this->getJson("/api/v1/fares?manager_id={$lead->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.site_id', $s1);
        $this->getJson('/api/v1/fares?manager_id=nope')->assertStatus(422);
    }

    public function test_fares_carry_set_by_and_previous_amount(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $this->fare($r, $s1, '2026-10-01', '30');
        $hr = $this->signIn('hr');
        $this->postJson('/api/v1/fares', ['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-01', 'amount' => '35'])->assertCreated();

        $this->getJson('/api/v1/fares?date=2026-11-05')->assertOk()
            ->assertJsonPath('data.0.set_by.id', $hr->id)
            ->assertJsonPath('data.0.set_by.name', $hr->name)
            ->assertJsonPath('data.0.previous_amount', '30.000000')
            ->assertJsonMissingPath('data.0.set_by_id');
        $this->getJson('/api/v1/fares?date=2026-10-15')->assertOk()
            ->assertJsonPath('data.0.set_by', null)
            ->assertJsonPath('data.0.previous_amount', null);
    }

    public function test_starting_fares_read_as_from_excel(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $importer = $this->signIn('hr');
        $fare = $this->fare($r, $s1, '2026-10-01', '30');
        $override = (string) EmployeeFareOverride::create(['employee_id' => $this->employee('day_laborer'), 'site_id' => $s1, 'effective_from' => '2026-10-01', 'amount' => '20'])->getKey();
        $this->assertSame($importer->id, Fare::find($fare)?->updated_by);

        (require database_path('migrations/2026_11_05_000002_mark_starting_fares_from_excel.php'))->up();

        $this->getJson('/api/v1/fares?date=2026-11-05')->assertOk()->assertJsonPath('data.0.set_by', null);
        $this->assertNull(EmployeeFareOverride::find($override)?->created_by);
        $this->assertNull(EmployeeFareOverride::find($override)?->updated_by);
    }

    public function test_changed_since_is_a_cairo_date(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $this->cairo('2026-10-20 10:00');
        $this->fare($r, $s1, '2026-01-01', '30');
        $this->cairo('2026-11-05 00:30');
        $late = $this->fare($r, $s1, '2026-11-05', '35');
        $this->cairo('2026-11-05 10:00');

        $this->signIn('hr');
        $this->getJson('/api/v1/fares?changed_since=2026-11-05')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late);
        $this->getJson('/api/v1/fares?changed_since=05-11-2026')->assertStatus(422);
    }

    private function describe(string $employee, string $site): TestResponse
    {
        return $this->getJson("/api/v1/employees/{$employee}/fare?site_id={$site}&date=2026-11-05")->assertOk();
    }

    public function test_an_engineer_never_takes_the_residence_default(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '40');
        $this->signIn('hr');
        $engineer = $this->employee('engineer', '2026-01-01', ['residence_id' => $r]);

        $this->describe($engineer, $s1)->assertJsonPath('data.source', 'none')->assertJsonPath('data.amount', '0.000000')->assertJsonPath('data.default_amount', null);
        $this->postJson("/api/v1/employees/{$engineer}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-01', 'amount' => '25'])->assertCreated();
        $this->describe($engineer, $s1)->assertJsonPath('data.source', 'override')->assertJsonPath('data.amount', '25.000000');
    }

    public function test_a_worker_without_residence_is_no_residence(): void
    {
        $s1 = $this->workSite('S-1');
        $this->signIn('hr');
        $laborer = $this->employee('day_laborer');

        $this->describe($laborer, $s1)->assertJsonPath('data.source', 'no_residence')->assertJsonPath('data.amount', '0.000000');
    }

    public function test_default_amount_is_the_residence_site_fare(): void
    {
        $s1 = $this->workSite('S-1');
        $r = $this->residence();
        $this->fare($r, $s1, '2026-01-01', '35');
        $this->signIn('hr');
        $worker = $this->employee('permanent_worker', '2026-01-01', ['residence_id' => $r]);
        $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-01', 'amount' => '30'])->assertCreated();

        $this->describe($worker, $s1)->assertJsonPath('data.source', 'override')->assertJsonPath('data.amount', '30.000000')->assertJsonPath('data.default_amount', '35.000000');
    }

    /** @return array{0: string, 1: string, 2: string, 3: \App\Modules\Identity\Infrastructure\Eloquent\User, 4: string} s1, s2, residence, the lead of s1, a permanent worker homed on s1 */
    private function team(): array
    {
        [$s1, $s2] = [$this->workSite('S-1'), $this->workSite('S-2')];
        $r = $this->residence();
        $this->signIn('hr');
        $worker = $this->employee('permanent_worker', '2026-01-01', ['residence_id' => $r]);
        $this->homeSite($worker, $s1, '2026-01-01');

        return [$s1, $s2, $r, $this->managerOf($s1), $worker];
    }

    /** @param  array<string, string>  $body */
    private function postFare(array $body): TestResponse
    {
        return $this->postJson('/api/v1/fares', $body);
    }

    public function test_a_team_lead_writes_only_their_sites(): void
    {
        [$s1, $s2, $r, $lead, $worker] = $this->team();
        $this->actingAs($lead, 'api');

        $this->postFare(['residence_id' => $r, 'site_id' => $s2, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated();
        $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s2, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'site_id');
        $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated();
    }

    public function test_a_team_lead_cannot_date_before_yesterday(): void
    {
        [$s1, , $r, $lead] = $this->team();
        $this->actingAs($lead, 'api');

        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-03', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-04', 'amount' => '30'])->assertCreated();
        $this->signIn('hr');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-01-01', 'amount' => '30'])->assertCreated();
    }

    public function test_a_locked_period_refuses_every_scope(): void
    {
        [$s1, , $r, , $worker] = $this->team();
        $laborer = $this->employee('day_laborer', '2026-01-01', ['residence_id' => $r]);
        $this->leaveTypes();
        $this->postJson('/api/v1/attendance/locks', ['group' => 'day_laborer', 'period_start' => '2026-11-01', 'confirm_missing' => true])->assertCreated();

        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->postJson("/api/v1/employees/{$laborer}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])
            ->assertStatus(422)->assertJsonPath('error.details.0.field', 'effective_from');
        $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated();
    }

    public function test_reach_is_checked_on_the_effective_date(): void
    {
        [$s1, $s2, , $lead] = $this->team();
        $moved = $this->employee('day_laborer');
        $this->homeSite($moved, $s2, '2026-01-01');
        $this->homeSite($moved, $s1, '2026-11-05');
        $outside = $this->employee('day_laborer');
        $this->homeSite($outside, $s2, '2026-01-01');
        $this->actingAs($lead, 'api');

        $this->postJson("/api/v1/employees/{$moved}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-04', 'amount' => '30'])->assertNotFound();
        $this->postJson("/api/v1/employees/{$moved}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated();
        $this->postJson("/api/v1/employees/{$outside}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertNotFound();
    }

    public function test_same_key_updates_in_place_and_is_audited(): void
    {
        [$s1, , $r, , $worker] = $this->team();

        $id = $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated()->json('data.id');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '35'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, Fare::count());
        $this->assertSame('35.000000', Fare::findOrFail($id)->amount);
        $this->assertAudited('fares', $id);

        $url = "/api/v1/employees/{$worker}/fare-overrides";
        $oid = $this->postJson($url, ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertCreated()->json('data.id');
        $this->postJson($url, ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '35'])->assertCreated()->assertJsonPath('data.amount', '35.000000');
        $this->assertSame(1, EmployeeFareOverride::count());
        $this->assertAudited('employee_fare_overrides', $oid);
    }

    private function assertAudited(string $table, string $id): void
    {
        $rows = DB::table('audit_log')->where(['entity_type' => $table, 'entity_id' => $id])->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(['CREATED', 'UPDATED'], $rows->pluck('event')->all());
        $this->assertSame(0, bccomp((string) json_decode((string) $rows[0]->new_values, true)['amount'], '30', 6));
        $this->assertSame(0, bccomp((string) json_decode((string) $rows[1]->old_values, true)['amount'], '30', 6));
        $this->assertSame(0, bccomp((string) json_decode((string) $rows[1]->new_values, true)['amount'], '35', 6));
    }

    public function test_a_manager_without_fare_manage_is_refused(): void
    {
        [$s1, , $r] = $this->team();
        $role = Role::create(['slug' => 'viewer', 'name' => 'viewer', 'name_ar' => 'viewer']);
        $role->permissions()->attach(Permission::where(['resource' => 'site', 'action' => 'view', 'scope' => 'own'])->value('id'));
        $this->signIn('viewer');

        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '30'])->assertForbidden();
    }

    public function test_a_team_lead_write_notifies_hr_once_per_row_per_day(): void
    {
        [$s1, , $r, $lead, $worker] = $this->team();
        $hr = auth()->user();
        $this->fare($r, $s1, '2026-10-01', '30');
        $this->actingAs($lead, 'api');

        $id = $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '35'])->assertCreated()->json('data.id');
        $sent = Notification::where(['user_id' => $hr?->getAuthIdentifier(), 'type' => 'fare_changed'])->get();
        $this->assertCount(1, $sent);
        $this->assertSame('2026-11-05', substr((string) $sent[0]->date, 0, 10));
        $this->assertSame($id, $sent[0]->ref);
        $this->assertSameData(['kind' => 'fare', 'site_id' => $s1, 'residence_id' => $r, 'effective_from' => '2026-11-05', 'old_amount' => '30.000000', 'new_amount' => '35.000000'], $sent[0]->data);

        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '40'])->assertCreated();
        $this->signIn('hr');
        $this->postFare(['residence_id' => $r, 'site_id' => $s1, 'effective_from' => '2026-11-06', 'amount' => '45'])->assertCreated();
        $this->assertSame(1, Notification::where('type', 'fare_changed')->where('user_id', $hr?->getAuthIdentifier())->count());

        $this->actingAs($lead, 'api');
        $oid = $this->postJson("/api/v1/employees/{$worker}/fare-overrides", ['site_id' => $s1, 'effective_from' => '2026-11-05', 'amount' => '25'])->assertCreated()->json('data.id');
        $this->assertSameData(
            ['kind' => 'override', 'site_id' => $s1, 'employee_id' => $worker, 'effective_from' => '2026-11-05', 'old_amount' => null, 'new_amount' => '25.000000'],
            Notification::where(['user_id' => $hr?->getAuthIdentifier(), 'ref' => $oid])->firstOrFail()->data,
        );
    }

    /**
     * jsonb stores keys in its own order.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameData(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }
}

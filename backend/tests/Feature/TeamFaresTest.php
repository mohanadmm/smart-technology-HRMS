<?php

namespace Tests\Feature;

use App\Modules\Sites\Infrastructure\Eloquent\EmployeeFareOverride;
use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use App\Modules\Sites\Infrastructure\Eloquent\Residence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}

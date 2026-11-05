<?php

namespace Tests\Feature;

use App\Modules\Settings\Application\BusinessRules;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_seeded_business_rules(): void
    {
        $this->signIn('hr');
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonCount(19, 'data')
            ->assertJsonPath('data.month_days', '30')
            ->assertJsonPath('data.day_hours', '8')
            ->assertJsonPath('data.overtime_multiplier', '1.5')
            ->assertJsonPath('data.lateness_multiplier_office', '1')
            ->assertJsonPath('data.lateness_multiplier_engineer', '1.5')
            ->assertJsonPath('data.lateness_multiplier_permanent_worker', '2')
            ->assertJsonPath('data.lateness_multiplier_day_laborer', '2')
            ->assertJsonPath('data.office_grace_minutes', '15')
            ->assertJsonPath('data.office_shift_start', '09:00')
            ->assertJsonPath('data.office_shift_end', '17:00')
            ->assertJsonPath('data.worker_shift_start', '08:00')
            ->assertJsonPath('data.worker_shift_end', '17:00')
            ->assertJsonPath('data.manager_yesterday_until', '12:00')
            ->assertJsonPath('data.weekly_off_office', 'friday,saturday')
            ->assertJsonPath('data.weekly_off_field', 'friday')
            ->assertJsonPath('data.leave_days_under_one_year', '10')
            ->assertJsonPath('data.leave_days_one_year_plus', '21')
            ->assertJsonPath('data.leave_casual_days', '6')
            ->assertJsonPath('data.sick_leave_pay_percent', '100');
    }

    public function test_put_saves_changed_rules_exactly_and_audits_each(): void
    {
        $hr = $this->signIn('hr');
        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '20', 'month_days' => '30', 'overtime_multiplier' => '1.333333'])
            ->assertOk()
            ->assertJsonPath('data.office_grace_minutes', '20')
            ->assertJsonPath('data.overtime_multiplier', '1.333333');
        $id = DB::table('settings')->where('key', 'office_grace_minutes')->value('id');
        $row = DB::table('audit_log')->where(['entity_type' => 'settings', 'entity_id' => $id, 'event' => 'UPDATED'])->first();
        $this->assertSame(['value' => '15'], json_decode($row->old_values, true));
        $this->assertSame(['value' => '20'], json_decode($row->new_values, true));
        $this->assertSame($hr->id, $row->user_id);
        $this->assertSame(2, DB::table('audit_log')->where(['entity_type' => 'settings', 'event' => 'UPDATED', 'user_id' => $hr->id])->count()); // month_days did not change
    }

    public function test_settings_are_read_once_and_an_update_is_visible_on_the_next_read(): void
    {
        $this->signIn('hr');
        $rules = app(BusinessRules::class);
        $rules->all();
        DB::enableQueryLog();
        $this->assertSame('15', $rules->all()['office_grace_minutes']);
        $this->assertSame([], DB::getQueryLog());

        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '25'])->assertOk()->assertJsonPath('data.office_grace_minutes', '25');
        $this->getJson('/api/v1/settings')->assertJsonPath('data.office_grace_minutes', '25');
        $this->assertSame('25', $rules->all()['office_grace_minutes']);
    }

    public function test_a_cold_read_that_loaded_settings_before_an_update_commits_cannot_cache_them(): void
    {
        $rules = app(BusinessRules::class);
        $updated = false;
        DB::listen(function (QueryExecuted $q) use (&$updated, $rules): void {
            if (! $updated && str_contains($q->sql, 'from "settings"')) {
                $updated = true;
                $rules->update(['office_grace_minutes' => '25']);
            }
        });

        $this->assertSame('15', $rules->all()['office_grace_minutes']);   // the late, stale write
        $this->assertSame('25', $rules->all()['office_grace_minutes']);
    }

    public function test_put_rejects_malformed_values_under_their_field(): void
    {
        $this->signIn('hr');
        $bad = ['overtime_multiplier' => '1,5', 'month_days' => '30.5', 'office_shift_start' => '25:00', 'weekly_off_field' => 'fri', 'lateness_multiplier_office' => 1.5];
        foreach ($bad as $key => $value) {
            $this->putJson('/api/v1/settings', [$key => $value])->assertStatus(422)->assertJsonPath('error.details.0.field', $key);
        }
        $this->assertSame('1.5', DB::table('settings')->where('key', 'overtime_multiplier')->value('value'));
        $this->assertSame('1', DB::table('settings')->where('key', 'lateness_multiplier_office')->value('value'));
    }

    public function test_sick_leave_pay_percent_is_a_whole_percent(): void
    {
        $this->signIn('hr');
        foreach (['0', '100', '75'] as $good) {
            $this->putJson('/api/v1/settings', ['sick_leave_pay_percent' => $good])->assertOk()->assertJsonPath('data.sick_leave_pay_percent', $good);
        }
        foreach (['101', '-1', '7.5', '07', '00'] as $bad) {
            $this->putJson('/api/v1/settings', ['sick_leave_pay_percent' => $bad])->assertStatus(422)->assertJsonPath('error.details.0.field', 'sick_leave_pay_percent');
        }
        $this->assertSame('75', DB::table('settings')->where('key', 'sick_leave_pay_percent')->value('value'));
    }

    public function test_view_and_manage_are_separate_permissions(): void
    {
        $this->signIn('manager');
        $this->getJson('/api/v1/settings')->assertStatus(403);
        $this->signIn('ceo');
        $this->getJson('/api/v1/settings')->assertOk();
        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '20'])->assertStatus(403);
    }

    public function test_the_field_shift_migration_moves_only_an_unedited_end_and_audits_it(): void
    {
        $rules = app(BusinessRules::class);
        $migration = require database_path('migrations/2026_11_02_000001_set_field_shift_end.php');
        $id = DB::table('settings')->where('key', 'worker_shift_end')->value('id');
        $audits = fn () => DB::table('audit_log')->where(['entity_type' => 'settings', 'entity_id' => $id])->count();

        $rules->update(['worker_shift_end' => '16:00']);   // as before the migration
        $before = $audits();
        $migration->up();
        $this->assertSame('17:00', $rules->all()['worker_shift_end']);
        $this->assertSame($before + 1, $audits());
        $migration->down();
        $this->assertSame('17:00', $rules->all()['worker_shift_end']);   // down() leaves it: HR may have chosen 17:00

        $rules->update(['worker_shift_end' => '15:30']);   // HR's edit
        $before = $audits();
        $migration->up();
        $this->assertSame('15:30', $rules->all()['worker_shift_end']);
        $this->assertSame($before, $audits());
    }
}

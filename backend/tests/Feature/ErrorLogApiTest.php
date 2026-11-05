<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ErrorLogApiTest extends TestCase
{
    use RefreshDatabase;

    // Rows are backdated to January 2026 and every query filters on that window: ErrorEnvelopeTest commits
    // error_log rows into hrms_test, so the table is not empty outside this test's own rows.
    private const string WINDOW = 'from=2026-01-10&to=2026-01-11';

    /** @param  array<string, mixed>  $attrs */
    private function errorRow(array $attrs): string
    {
        $id = (string) Str::uuid7();
        DB::table('error_log')->insert([
            'id' => $id,
            'request_id' => (string) Str::uuid(),
            'user_id' => null,
            'class' => 'RuntimeException',
            'message' => 'boom',
            'file' => '/var/www/html/app/X.php',
            'line' => 7,
            'trace' => '#0 {main}',
            'sqlstate' => null,
            'occurred_at' => '2026-01-10 08:00:00+00',
            ...$attrs,
        ]);

        return $id;
    }

    /** @return list<string> */
    private function ids(string $filter): array
    {
        return $this->getJson('/api/v1/error-log?'.self::WINDOW.($filter === '' ? '' : "&$filter"))->assertOk()->json('data.*.id');
    }

    public function test_only_the_developer_reads_the_error_log(): void
    {
        foreach (['hr', 'ceo', 'manager'] as $role) {
            $this->signIn($role);
            $this->getJson('/api/v1/error-log')->assertStatus(403);
        }
        $this->signIn('developer');
        $this->getJson('/api/v1/error-log')->assertOk();
    }

    public function test_rows_come_newest_first_with_their_details(): void
    {
        $this->signIn('developer');
        $old = $this->errorRow(['occurred_at' => '2026-01-10 07:00:00+00']);
        $new = $this->errorRow([
            'occurred_at' => '2026-01-10 09:00:00+00', 'request_id' => 'req-abc', 'class' => 'Illuminate\Database\UniqueConstraintViolationException',
            'message' => "SQLSTATE 23505, constraint employees_national_id_unique\ninsert into employees", 'file' => '/var/www/html/vendor/Connection.php',
            'line' => 838, 'trace' => "#0 /a.php(1): A->b()\n#1 {main}", 'sqlstate' => '23505',
        ]);
        $mid = $this->errorRow(['occurred_at' => '2026-01-10 08:00:00+00']);

        $res = $this->getJson('/api/v1/error-log?'.self::WINDOW)->assertOk();

        $this->assertSame([$new, $mid, $old], $res->json('data.*.id'));
        $res->assertJsonPath('data.0.request_id', 'req-abc')
            ->assertJsonPath('data.0.class', 'Illuminate\Database\UniqueConstraintViolationException')
            ->assertJsonPath('data.0.message', "SQLSTATE 23505, constraint employees_national_id_unique\ninsert into employees")
            ->assertJsonPath('data.0.file', '/var/www/html/vendor/Connection.php')
            ->assertJsonPath('data.0.line', 838)
            ->assertJsonPath('data.0.trace', "#0 /a.php(1): A->b()\n#1 {main}")
            ->assertJsonPath('data.0.sqlstate', '23505')
            ->assertJsonPath('meta.pagination.total', 3);
        $this->assertNotNull($res->json('data.0.occurred_at'));
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $this->signIn('developer');
        $a = $this->errorRow(['occurred_at' => '2026-01-10 10:00:00+00', 'request_id' => 'req-a']);
        $b = $this->errorRow(['occurred_at' => '2026-01-10 11:00:00+00', 'request_id' => 'req-b']);
        $noRequest = $this->errorRow(['occurred_at' => '2026-01-10 12:00:00+00', 'request_id' => null]);   // a queued job or console command
        $lateNight = $this->errorRow(['occurred_at' => '2026-01-10 22:30:00+00']);   // 2026-01-11 00:30 in Cairo
        $this->errorRow(['occurred_at' => '2026-01-09 21:59:00+00']);                 // 2026-01-09 23:59 in Cairo: outside the window
        $this->errorRow(['occurred_at' => '2026-01-11 22:00:00+00']);                 // 2026-01-12 00:00 in Cairo: outside the window

        $this->assertSame([$lateNight, $noRequest, $b, $a], $this->ids(''));
        $this->assertSame([$b], $this->ids('request_id=req-b'));
        $this->assertSame([], $this->ids('request_id=req'));                          // exact, not contains
        $this->assertSame([$lateNight], $this->getJson('/api/v1/error-log?from=2026-01-11&to=2026-01-11')->json('data.*.id'));
        $this->assertSame([$noRequest, $b, $a], $this->getJson('/api/v1/error-log?from=2026-01-10&to=2026-01-10')->json('data.*.id'));
        $this->assertSame([$lateNight, $noRequest], $this->getJson('/api/v1/error-log?'.self::WINDOW.'&per_page=2')->json('data.*.id'));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->signIn('developer');
        foreach (['from=10-01-2026', 'from=2026-01-11&to=2026-01-10', 'per_page=0', 'per_page=101', 'request_id='.str_repeat('a', 65)] as $q) {
            $this->getJson("/api/v1/error-log?$q")->assertStatus(422);
        }
    }
}

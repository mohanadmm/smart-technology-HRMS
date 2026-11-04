<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestLogApiTest extends TestCase
{
    use RefreshDatabase;

    // Rows are backdated to January 2026 and every query filters on that window, so rows the
    // test's own HTTP calls log (and committed rows other tests leave behind) never count.
    private const string WINDOW = 'from=2026-01-10&to=2026-01-11';

    /** @param  array<string, mixed>  $attrs */
    private function logRow(array $attrs): string
    {
        $id = (string) Str::uuid7();
        DB::table('request_log')->insert([
            'id' => $id,
            'request_id' => (string) Str::uuid(),
            'user_id' => null,
            'method' => 'GET',
            'path' => 'api/v1/employees',
            'status' => 200,
            'duration_ms' => 12,
            'ip_address' => '10.0.0.7',
            'occurred_at' => '2026-01-10 08:00:00+00',
            ...$attrs,
        ]);

        return $id;
    }

    /** @return list<string> */
    private function ids(string $filter): array
    {
        return $this->getJson('/api/v1/request-log?'.self::WINDOW.($filter === '' ? '' : "&$filter"))->assertOk()->json('data.*.id');
    }

    public function test_only_the_developer_reads_the_request_log(): void
    {
        foreach (['hr', 'ceo', 'manager'] as $role) {
            $this->signIn($role);
            $this->getJson('/api/v1/request-log')->assertStatus(403);
        }
        $this->signIn('developer');
        $this->getJson('/api/v1/request-log')->assertOk();
    }

    public function test_rows_come_newest_first_with_their_account_and_details(): void
    {
        $dev = $this->signIn('developer');
        $old = $this->logRow(['occurred_at' => '2026-01-10 07:00:00+00']);
        $new = $this->logRow([
            'occurred_at' => '2026-01-10 09:00:00+00', 'user_id' => $dev->id, 'request_id' => 'req-abc', 'method' => 'POST',
            'route' => 'api/v1/sites', 'path' => 'api/v1/sites', 'status' => 422, 'duration_ms' => 31, 'user_agent' => 'Safari',
            'query' => json_encode(['page' => '2']), 'request_body' => json_encode(['name_ar' => 'سموحة']),
            'response_body' => json_encode(['message' => 'invalid']),
        ]);
        $mid = $this->logRow(['occurred_at' => '2026-01-10 08:00:00+00']);

        $res = $this->getJson('/api/v1/request-log?'.self::WINDOW)->assertOk();

        $this->assertSame([$new, $mid, $old], $res->json('data.*.id'));
        $res->assertJsonPath('data.0.user', ['id' => $dev->id, 'name' => $dev->name])
            ->assertJsonPath('data.1.user', null)
            ->assertJsonPath('data.0.request_id', 'req-abc')
            ->assertJsonPath('data.0.method', 'POST')
            ->assertJsonPath('data.0.path', 'api/v1/sites')
            ->assertJsonPath('data.0.route', 'api/v1/sites')
            ->assertJsonPath('data.0.status', 422)
            ->assertJsonPath('data.0.duration_ms', 31)
            ->assertJsonPath('data.0.ip_address', '10.0.0.7')
            ->assertJsonPath('data.0.user_agent', 'Safari')
            ->assertJsonPath('data.0.query', ['page' => '2'])
            ->assertJsonPath('data.0.request_body', ['name_ar' => 'سموحة'])
            ->assertJsonPath('data.0.response_body', ['message' => 'invalid'])
            ->assertJsonPath('meta.pagination.total', 3);
        $this->assertNotNull($res->json('data.0.occurred_at'));
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $dev = $this->signIn('developer');
        $ok = $this->logRow(['occurred_at' => '2026-01-10 10:00:00+00']);
        $client = $this->logRow(['occurred_at' => '2026-01-10 11:00:00+00', 'status' => 404, 'method' => 'POST', 'user_id' => $dev->id]);
        $server = $this->logRow(['occurred_at' => '2026-01-10 12:00:00+00', 'status' => 500, 'path' => 'api/v1/sites/100%_off', 'request_id' => 'req-500']);
        $lateNight = $this->logRow(['occurred_at' => '2026-01-10 22:30:00+00']);   // 2026-01-11 00:30 in Cairo
        $this->logRow(['occurred_at' => '2026-01-09 21:59:00+00']);                 // 2026-01-09 23:59 in Cairo: outside the window
        $this->logRow(['occurred_at' => '2026-01-11 22:00:00+00']);                 // 2026-01-12 00:00 in Cairo: outside the window

        $this->assertSame([$lateNight, $server, $client, $ok], $this->ids(''));
        $this->assertSame([$server, $client], $this->ids('status=failed'));
        $this->assertSame([$server], $this->ids('status=server'));
        $this->assertSame([$client], $this->ids('method=POST'));
        $this->assertSame([$client], $this->ids("user_id={$dev->id}"));
        $this->assertSame([$server], $this->ids('path=SITES'));
        $this->assertSame([$server], $this->ids('path='.rawurlencode('100%_')));
        $this->assertSame([], $this->ids('path='.rawurlencode('employ%s')));       // % is literal, not a wildcard
        $this->assertSame([$server], $this->ids('request_id=req-500'));
        $this->assertSame([$lateNight], $this->getJson('/api/v1/request-log?from=2026-01-11&to=2026-01-11')->json('data.*.id'));
        $this->assertSame([$server, $client, $ok], $this->getJson('/api/v1/request-log?from=2026-01-10&to=2026-01-10')->json('data.*.id'));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->signIn('developer');
        foreach (['status=client', 'method=HEAD', 'user_id=x', 'from=10-01-2026', 'from=2026-01-11&to=2026-01-10', 'per_page=101', 'path='.str_repeat('a', 201), 'request_id='.str_repeat('a', 65)] as $q) {
            $this->getJson("/api/v1/request-log?$q")->assertStatus(422);
        }
    }
}

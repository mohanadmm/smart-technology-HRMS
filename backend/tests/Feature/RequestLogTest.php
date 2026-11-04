<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Eloquent\RequestLogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RequestLogTest extends TestCase
{
    use RefreshDatabase;

    /** Found by request ID: tests without RefreshDatabase (ErrorEnvelopeTest) leave committed rows behind. */
    private function rowOf(TestResponse $response): RequestLogEntry
    {
        return RequestLogEntry::where('request_id', $response->headers->get('X-Request-Id'))->sole();
    }

    public function test_a_successful_request_is_logged_without_bodies(): void
    {
        $dev = $this->signIn('developer');
        $res = $this->getJson('/api/v1/auth/me')->assertOk();

        $row = $this->rowOf($res);
        $this->assertSame('GET', $row->method);
        $this->assertSame('api/v1/auth/me', $row->path);
        $this->assertSame('api/v1/auth/me', $row->route);
        $this->assertSame(200, $row->status);
        $this->assertSame($dev->id, $row->user_id);
        $this->assertGreaterThanOrEqual(0, $row->duration_ms);
        $this->assertEqualsWithDelta(now()->getTimestamp(), $row->occurred_at->getTimestamp(), 60);
        $this->assertNull($row->query);
        $this->assertNull($row->request_body);
        $this->assertNull($row->response_body);
    }

    public function test_a_national_id_in_search_is_masked(): void
    {
        $this->signIn('hr');
        $res = $this->getJson('/api/v1/employees?search=29801011234567')->assertOk();

        $this->assertSame(['search' => '[masked]'], $this->rowOf($res)->query);
        // jsonb has no LIKE operator: compare its text.
        $this->assertSame(0, DB::table('request_log')->whereRaw('query::text like ?', ['%2980101%'])->count());
    }

    public function test_a_failed_allowance_save_masks_the_amount(): void
    {
        $this->signIn('hr');
        $site = $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة الدولي', 'kind' => 'work'])->assertCreated()->json('data.id');
        $res = $this->postJson("/api/v1/sites/$site/allowance-rules", ['kind' => 'fixed', 'value' => '150', 'effective_from' => 'bad'])->assertStatus(422);

        $row = $this->rowOf($res);
        $this->assertSame(422, $row->status);
        $this->assertSame('[masked]', $row->request_body['value']);
        $this->assertSame('fixed', $row->request_body['kind']);
        $this->assertSame('validation_failed', $row->response_body['error']['code']);
    }

    public function test_a_failed_login_keeps_the_email_and_masks_the_password(): void
    {
        $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'hr@smart.test', 'password' => 'nope'])->assertStatus(401);

        $row = $this->rowOf($res);
        $this->assertSame('hr@smart.test', $row->request_body['email']);
        $this->assertSame('[masked]', $row->request_body['password']);
        $this->assertNull($row->user_id);
    }

    public function test_a_server_error_keeps_no_response_body(): void
    {
        Route::get('api/v1/_boom', fn () => throw new RuntimeException('x'))->middleware('api');
        $res = $this->getJson('/api/v1/_boom')->assertStatus(500);

        $row = $this->rowOf($res);
        $this->assertSame(500, $row->status);
        $this->assertSame('api/v1/_boom', $row->route);
        $this->assertNull($row->response_body);
    }

    public function test_an_uploaded_file_keeps_only_its_masked_name_and_size(): void
    {
        // jsonb reorders keys, hence assertEquals.
        Route::post('api/v1/_upload', fn () => abort(422))->middleware('api');
        $res = $this->call('POST', '/api/v1/_upload', ['note' => 'x'], [], ['doc' => UploadedFile::fake()->create('29801011234567.pdf', 1)]);

        $this->assertEquals(['note' => 'x', 'doc' => ['name' => '[masked].pdf', 'size' => 1024]], $this->rowOf($res)->request_body);
    }

    public function test_bad_bytes_still_get_a_row(): void
    {
        $res = $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_USER_AGENT' => "ua\x00\xC3\x28", 'CONTENT_TYPE' => 'application/json'], '{"email":"a\\u0000b"}');

        $row = $this->rowOf($res);
        $this->assertSame('api/v1/auth/login', $row->path);
        $this->assertStringNotContainsString("\0", (string) $row->user_agent);
        $this->assertSame('ab', $row->request_body['email']);
    }

    public function test_logging_does_not_touch_sessions_for_401_and_404(): void
    {
        $this->makeUser('hr', ['email' => 'hr@smart.test']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'hr@smart.test', 'password' => 'secret123'])->assertCreated()->json('data.token');
        $touched = fn () => DB::table('user_sessions')->where('session_id', hash('sha256', $token))->value('last_activity_at');
        $before = $touched();

        $this->travel(5)->minutes();
        $notFound = $this->withToken($token)->getJson('/api/v1/nope')->assertNotFound();
        $this->assertSame($before, $touched());
        $this->assertNull($this->rowOf($notFound)->user_id);
        $this->assertNull($this->rowOf($notFound)->route);

        $unauthenticated = $this->withoutToken()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame($before, $touched());
        $this->assertNull($this->rowOf($unauthenticated)->user_id);
    }

    public function test_health_and_non_api_paths_are_not_logged(): void
    {
        $before = DB::table('request_log')->count();

        $this->getJson('/api/v1/health')->assertOk();
        $this->get('/');

        $this->assertSame($before, DB::table('request_log')->count());
    }

    public function test_a_failing_log_write_does_not_change_the_response(): void
    {
        Log::spy();
        // request_id is varchar(64): 65 characters make the insert fail.
        Route::get('api/v1/_long', function (Request $request) {
            $request->attributes->set('request_id', str_repeat('x', 65));

            return response()->json(['ok' => true]);
        })->middleware('api');

        $this->getJson('/api/v1/_long')->assertOk()->assertJsonPath('ok', true);

        // The failed insert rolled back only its savepoint: the test's transaction still answers.
        $this->assertSame(0, DB::table('request_log')->where('path', 'api/v1/_long')->count());
        Log::shouldHaveReceived('warning')->with('system_log.request_failed', Mockery::on(fn (array $c) => str_contains($c['class'], 'QueryException')));
    }
}

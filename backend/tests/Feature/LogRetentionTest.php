<?php

namespace Tests\Feature;

use App\Modules\Audit\Application\PruneLogs;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Rows are backdated in SQL: under RefreshDatabase the DB clock (now()) is frozen at the transaction start, travel() cannot move it. */
class LogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_old_log_rows_and_keeps_recent_ones(): void
    {
        $oldRequest = $this->requestRow('91 days');
        $newRequest = $this->requestRow('89 days');
        $oldError = $this->errorRow('91 days');
        $newError = $this->errorRow('89 days');

        $this->artisan('logs:prune')->expectsOutput('request_log: 1, error_log: 1, audit_log: 0')->assertSuccessful();

        $this->assertSame([$newRequest], DB::table('request_log')->whereIn('id', [$oldRequest, $newRequest])->pluck('id')->all());
        $this->assertSame([$newError], DB::table('error_log')->whereIn('id', [$oldError, $newError])->pluck('id')->all());
        $this->assertSame(0, DB::table('audit_log')->where('event', 'PRUNED')->count());
    }

    public function test_prune_deletes_audit_rows_older_than_a_year_and_records_it(): void
    {
        $old = $this->auditRow('13 months');
        $young = $this->auditRow('11 months');

        $this->artisan('logs:prune')->expectsOutput('request_log: 0, error_log: 0, audit_log: 1')->assertSuccessful();

        $this->assertSame([$young], DB::table('audit_log')->whereIn('id', [$old, $young])->pluck('id')->all());
        $pruned = DB::table('audit_log')->where('event', 'PRUNED')->get();
        $this->assertCount(1, $pruned);
        $this->assertSame('audit_log', $pruned[0]->entity_type);
        $this->assertNull($pruned[0]->entity_id);
        $cutoff = CarbonImmutable::parse((string) DB::scalar("select now() - interval '1 year'"))->toJSON();
        $this->assertSame(['count' => 1, 'cutoff' => $cutoff], json_decode($pruned[0]->new_values, true));
    }

    public function test_a_failed_pruned_record_keeps_the_old_audit_rows(): void
    {
        $old = $this->auditRow('13 months');
        // Fails after the audit insert ran, as a dropped connection would: the prune must roll back with it.
        DB::listen(function (QueryExecuted $q): void {
            if (str_starts_with($q->sql, 'insert into "audit_log"')) {
                throw new RuntimeException('audit write failed');
            }
        });

        $this->assertThrows(fn () => app(PruneLogs::class)->run(), RuntimeException::class);

        $this->assertSame(1, DB::table('audit_log')->where('id', $old)->count());
        $this->assertSame(0, DB::table('audit_log')->where('event', 'PRUNED')->count());
    }

    public function test_the_trigger_still_refuses_young_rows(): void
    {
        $young = $this->auditRow('11 months');
        $old = $this->auditRow('13 months');
        foreach (["delete from audit_log where id = '$young'", "update audit_log set event = 'X' where id = '$old'", 'truncate audit_log'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));   // savepoint: one refusal must not abort the next statement
                $this->fail("$sql succeeded");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(2, DB::table('audit_log')->whereIn('id', [$young, $old])->where('event', 'TEST')->count());
    }

    public function test_logs_prune_is_scheduled_at_three_cairo(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'logs:prune'));
        $this->assertSame(['0 3 * * *', 'Africa/Cairo'], [$event?->expression, $event?->timezone]);
    }

    private function requestRow(string $age): string
    {
        $id = (string) Str::uuid7();
        DB::table('request_log')->insert(['id' => $id, 'request_id' => 'r-'.$id, 'method' => 'GET', 'path' => 'api/v1/employees', 'status' => 200, 'duration_ms' => 5, 'occurred_at' => DB::raw("now() - interval '$age'")]);

        return $id;
    }

    private function errorRow(string $age): string
    {
        $id = (string) Str::uuid7();
        DB::table('error_log')->insert(['id' => $id, 'class' => 'RuntimeException', 'message' => 'boom', 'trace' => '[]', 'occurred_at' => DB::raw("now() - interval '$age'")]);

        return $id;
    }

    private function auditRow(string $age): string
    {
        $id = (string) Str::uuid7();
        DB::table('audit_log')->insert(['id' => $id, 'event' => 'TEST', 'entity_type' => 'employees', 'occurred_at' => DB::raw("now() - interval '$age'")]);

        return $id;
    }
}

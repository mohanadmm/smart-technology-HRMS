<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Application\SendAnniversaryAlert;
use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The one-year alert (leave spec, Anniversary alert): HR hears the day a monthly employee hired last year completes a year of service. */
class LeaveAnniversaryTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    /** @var list<User> */
    private array $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-03-10 08:00');
        $this->hr = [$this->makeUser('hr'), $this->makeUser('hr')];
        $this->makeUser('manager');
        $this->makeUser('ceo');
    }

    public function test_each_hr_user_hears_once_about_each_monthly_employee_completing_a_year(): void
    {
        $office = $this->employee('office', '2025-03-10');
        $engineer = $this->employee('engineer', '2025-03-10');
        $this->employee('office', '2024-03-10');                                          // a second year
        $this->employee('day_laborer', '2025-03-10');                                     // no leave balance
        $this->employee('engineer', '2025-03-10', ['termination_date' => '2026-03-01']);  // gone before the day

        $this->artisan('leave:alert-anniversary', ['date' => '2026-03-10'])->assertSuccessful();
        $this->artisan('leave:alert-anniversary', ['date' => '2026-03-10'])->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [[$this->hr[0]->id, $office], [$this->hr[0]->id, $engineer], [$this->hr[1]->id, $office], [$this->hr[1]->id, $engineer]],
            Notification::all()->map(fn (Notification $n) => [$n->user_id, $n->ref])->all(),
        );
        $n = Notification::where('ref', $office)->firstOrFail();
        $this->assertSame(['leave_anniversary', '2026-03-10'], [$n->type, substr((string) $n->date, 0, 10)]);
        $this->assertEquals(['employee_id' => $office, 'hire_date' => '2025-03-10'], $n->data);
    }

    public function test_a_jan_1_hire_is_already_on_the_full_entitlement(): void
    {
        $this->employee('office', '2025-01-01');
        $this->artisan('leave:alert-anniversary', ['date' => '2026-01-01'])->assertSuccessful();
        $this->assertSame(0, Notification::count());
    }

    public function test_a_feb_29_hire_is_alerted_on_feb_28_once(): void
    {
        $worker = $this->employee('permanent_worker', '2024-02-29');

        $this->artisan('leave:alert-anniversary', ['date' => '2025-02-28'])->assertSuccessful();
        $this->artisan('leave:alert-anniversary', ['date' => '2025-03-01'])->assertSuccessful();

        $this->assertSame([['2025-02-28', $worker], ['2025-02-28', $worker]], Notification::all()
            ->map(fn (Notification $n) => [substr((string) $n->date, 0, 10), $n->ref])->all());
    }

    public function test_a_bad_date_fails(): void
    {
        $this->artisan('leave:alert-anniversary', ['date' => '2026-02-30'])->assertExitCode(1);
    }

    public function test_the_job_is_queued_on_maintenance_with_retries_daily_at_8_cairo(): void
    {
        $this->cairo('2026-03-10 00:30');   // still 03-09 in UTC: the default date must be Cairo's
        Queue::fake();
        $this->artisan('leave:alert-anniversary')->assertSuccessful();
        Queue::assertPushedOn('maintenance', SendAnniversaryAlert::class, fn ($j) => $j->date === '2026-03-10' && $j->tries === 3 && $j->backoff() === [60, 300]);

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'leave:alert-anniversary'));
        $this->assertSame(['0 8 * * *', 'Africa/Cairo'], [$event?->expression, $event?->timezone]);
    }
}

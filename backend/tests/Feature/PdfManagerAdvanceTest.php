<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\RenderPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** The site manager's advance PDF: the copy without the salary, for workers in reach on the request's date (Step 2, task 2c). */
final class PdfManagerAdvanceTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    private const API = '/api/v1/hr-form-exports';

    private const DATE = '2026-10-20';

    private const SALARY = '8765.432100';

    private const FIELDS = ['job_title' => 'Synthetic technician', 'reason' => 'Synthetic reason', 'repayment_method' => 'cash'];

    private const SALARY_PHRASES = ['راتب الموظف', 'راتب الموظفة', 'Employee Salary', 'غير متاح', 'Unavailable', '8765'];

    private User $hr;

    private User $manager;

    private string $site;

    private string $worker;

    private string $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo(self::DATE.' 12:00');
        Queue::fake();
        Storage::fake('local');
        $this->hr = $this->signIn('hr');
        $this->site = $this->workSite('Site-A');
        $this->manager = $this->managerOf($this->site);
        $this->worker = $this->worker($this->site);
        $this->request = $this->advance($this->worker);
    }

    public function test_the_migration_grants_the_manager_own_scope_generate_and_view_and_down_removes_them(): void
    {
        $this->assertSame(['hr_forms.generate.own', 'hr_forms.view.own'], $this->managerHrFormGrants());
        $migration = require database_path('migrations/2026_11_14_180000_grant_manager_advance_pdf.php');
        $migration->down();
        $this->assertSame([], $this->managerHrFormGrants());
        $this->assertSame(0, $this->ownHrFormPermissions());
        $migration->up();
        $this->assertSame(['hr_forms.generate.own', 'hr_forms.view.own'], $this->managerHrFormGrants());
        $this->assertSame(2, $this->ownHrFormPermissions());
    }

    public function test_the_migration_grants_permissions_that_already_exist(): void
    {
        $migration = require database_path('migrations/2026_11_14_180000_grant_manager_advance_pdf.php');
        $migration->up();
        $this->assertSame(['hr_forms.generate.own', 'hr_forms.view.own'], $this->managerHrFormGrants());
        $this->assertSame(2, $this->ownHrFormPermissions());

        // Made in the console before the deploy and granted to no role; a soft-deleted twin holds no place in the unique index.
        $migration->down();
        Permission::create(['resource' => 'hr_forms', 'action' => 'view', 'scope' => 'own']);
        Permission::create(['resource' => 'hr_forms', 'action' => 'generate', 'scope' => 'own'])->delete();
        $migration->up();
        $this->assertSame(['hr_forms.generate.own', 'hr_forms.view.own'], $this->managerHrFormGrants());
        $this->assertSame(2, $this->ownHrFormPermissions());
    }

    public function test_a_manager_generates_a_copy_without_salary(): void
    {
        $this->actingAs($this->manager, 'api');
        $finance = [...self::FIELDS, 'previous_advance' => true, 'finance_remarks' => 'Synthetic remarks'];
        $key = (string) Str::uuid7();
        DB::enableQueryLog();
        $id = $this->create($this->request, $finance, $key)
            ->assertAccepted()->assertJsonPath('data.shows_pay', false)->assertJsonPath('data.regenerated_from_id', null)->json('data.id');
        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'employee_pay_changes'))));
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => str_starts_with($sql, 'select * from "employees"'))),
            'the copy without the salary reads only the employee columns it keeps');
        $this->create($this->request, $finance, $key)->assertAccepted()->assertJsonPath('data.id', $id);

        $snapshot = $this->snapshot($id);
        $this->assertArrayNotHasKey('salary', $snapshot);
        $this->assertFalse($snapshot['shows_pay']);
        $this->assertSame([null, ''], [$snapshot['previous_advance'], $snapshot['finance_remarks']]);
        $audit = (string) DB::table('audit_log')->where(['event' => 'CREATED', 'entity_type' => 'hr_form_exports', 'entity_id' => $id])->sole()->new_values;
        $this->assertStringNotContainsString('salary', $audit);
        $this->assertStringNotContainsString('8765', $audit);
        $this->assertStringNotContainsString('Synthetic remarks', $audit);

        $html = $this->html($id);
        foreach (self::SALARY_PHRASES as $phrase) {
            $this->assertStringNotContainsString($phrase, $html);
        }
        $this->assertStringContainsString('يخصم شهريًا من الراتب', $html);
        $this->assertStringContainsString('250.00', $html);
        $this->getJson(self::API."/{$id}")->assertOk()->assertJsonPath('data.shows_pay', false)->assertJsonPath('data.actions', ['download']);
        $this->get(self::API."/{$id}/download")->assertOk();
    }

    public function test_hr_copy_keeps_the_salary_and_is_hidden_from_the_manager(): void
    {
        $hrCopy = $this->create($this->request, [...self::FIELDS, 'previous_advance' => false])
            ->assertAccepted()->assertJsonPath('data.shows_pay', true)->json('data.id');
        $this->assertSame(self::SALARY, $this->snapshot($hrCopy)['salary']);
        $this->assertTrue($this->snapshot($hrCopy)['shows_pay']);
        $this->assertStringContainsString('8765.43', $this->html($hrCopy));

        $this->actingAs($this->manager, 'api');
        $own = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        $this->assertSame([$own], array_column($this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->json('data'), 'id'));
        $this->assertSame([$own], array_column($this->getJson(self::API."?type=advance_request&subject_id={$this->request}&employee_id={$this->worker}&q=1")->assertOk()->json('data'), 'id'));
        $this->getJson(self::API."/{$hrCopy}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get(self::API."/{$hrCopy}/download")->assertNotFound();
        DB::table('hr_form_exports')->where('id', $hrCopy)->update(['status' => 'failed', 'failure_code' => 'render_failed', 'sha256' => null, 'size_bytes' => null]);
        $this->postJson(self::API."/{$hrCopy}/retry")->assertNotFound();

        $this->actingAs($this->hr, 'api');
        $rows = $this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->json('data');
        $this->assertSame([[$own, false], [$hrCopy, true]], array_map(fn (array $row) => [$row['id'], $row['shows_pay']], $rows));
    }

    public function test_the_two_copies_supersede_separately(): void
    {
        $hrFirst = $this->create($this->request, [...self::FIELDS, 'previous_advance' => false])->assertAccepted()->json('data.id');
        $this->actingAs($this->manager, 'api');
        $managerFirst = $this->create($this->request, self::FIELDS)->assertAccepted()->assertJsonPath('data.regenerated_from_id', null)->json('data.id');
        $this->assertDatabaseMissing('hr_form_exports', ['regenerated_from_id' => $hrFirst]);
        $this->create($this->request, self::FIELDS)->assertAccepted()->assertJsonPath('data.regenerated_from_id', $managerFirst);

        $this->actingAs($this->hr, 'api');
        $this->create($this->request, [...self::FIELDS, 'previous_advance' => false])->assertAccepted()->assertJsonPath('data.regenerated_from_id', $hrFirst);
    }

    public function test_a_manager_cannot_create_the_other_three_types(): void
    {
        $leave = $this->postJson('/api/v1/leave/requests', ['employee_id' => $this->worker, 'kind' => 'annual',
            'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'note' => 'Synthetic reason'])->assertCreated()->json('data.id');
        $overtime = (string) Str::uuid7();
        DB::table('attendance_requests')->insert(['id' => $overtime, 'employee_id' => $this->worker, 'site_id' => $this->site, 'type' => 'overtime',
            'date' => self::DATE, 'minutes' => 60, 'in_time' => '09:00', 'out_time' => '17:35', 'requested_by' => $this->hr->id]);

        $this->actingAs($this->manager, 'api');
        foreach ([['experience_certificate', $this->worker, ['job_title' => 'Synthetic']], ['leave_request', $leave, ['job_title' => 'Synthetic']],
            ['overtime_request', $overtime, ['job_title' => 'Synthetic', 'reason' => 'Synthetic reason']]] as [$type, $subject, $fields]) {
            $this->postJson(self::API, ['type' => $type, 'subject_id' => $subject, 'idempotency_key' => (string) Str::uuid7(), 'fields' => $fields])
                ->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        }
        $this->assertDatabaseCount('hr_form_exports', 0);
    }

    public function test_a_manager_cannot_approve(): void
    {
        $this->actingAs($this->manager, 'api');
        $id = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        $this->html($id);

        $this->postJson(self::API."/{$id}/approve")->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->assertDatabaseMissing('hr_form_exports', ['stage' => 'signed']);
    }

    public function test_out_of_reach_archived_own_record_and_office_employees_are_not_found(): void
    {
        $outOfReach = $this->advance($this->worker($this->workSite('Site-B')));
        $archived = $this->advance($this->worker($this->site, ['termination_date' => '2026-10-10']), '2026-10-05');
        $ownRecord = $this->worker($this->site);
        DB::table('employees')->where('id', $ownRecord)->update(['user_id' => $this->manager->id]);
        $own = $this->advance($ownRecord);
        // Office staff with a dated site on the manager's site: listed there for HR, never in a manager's reach.
        $officeEmployee = $this->employee('office', '2026-01-01');
        $this->homeSite($officeEmployee, $this->site, '2026-01-01');
        $office = $this->advance($officeEmployee);

        $this->actingAs($this->manager, 'api');
        foreach ([$outOfReach, $archived, $own, $office] as $request) {
            $this->create($request, self::FIELDS)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
        $copy = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        DB::table('employees')->where('id', $this->worker)->update(['termination_date' => '2026-10-19']);
        $this->getJson(self::API."/{$copy}")->assertNotFound();
        $this->assertDatabaseCount('hr_form_exports', 1);
    }

    public function test_reach_follows_the_request_date(): void
    {
        $this->cairo('2026-10-25 12:00');
        $siteB = $this->workSite('Site-B');
        $this->homeSite($this->worker, $siteB, '2026-10-22');
        $todaysManager = $this->managerOf($siteB);

        $this->actingAs($this->manager, 'api');
        $id = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        $this->html($id);
        $this->getJson(self::API."/{$id}")->assertOk()->assertJsonPath('data.actions', ['download']);
        $this->get(self::API."/{$id}/download")->assertOk();
        $this->assertSame([$id], array_column($this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->json('data'), 'id'));

        $this->actingAs($todaysManager, 'api');
        $this->create($this->request, self::FIELDS)->assertNotFound();
        $this->getJson(self::API."/{$id}")->assertNotFound();
        $this->get(self::API."/{$id}/download")->assertNotFound();
        $this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_own_scope_list_needs_the_subject(): void
    {
        $siteB = $this->workSite('Site-B');
        $theirs = $this->advance($this->worker($siteB));
        $this->actingAs($this->managerOf($siteB), 'api');
        $hidden = $this->create($theirs, self::FIELDS)->assertAccepted()->json('data.id');

        $this->actingAs($this->manager, 'api');
        $mine = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        foreach (['', '?type=advance_request', "?subject_id={$this->request}", "?type=overtime_request&subject_id={$this->request}",
            "?employee_id={$this->worker}", '?awaiting_approval=1'] as $query) {
            $this->getJson(self::API.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        }
        $this->getJson(self::API.'?type=advance_request', ['Accept-Language' => 'ar'])->assertJsonPath('error.details.0.field', 'subject_id')
            ->assertJsonPath('error.details.0.message', 'حقل معرّف السجل مطلوب.');
        $this->assertSame([$mine], array_column($this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->json('data'), 'id'));
        $this->getJson(self::API."?type=advance_request&subject_id={$theirs}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."/{$hidden}")->assertNotFound();
    }

    public function test_view_as_a_manager_behaves_as_the_manager(): void
    {
        $hrCopy = $this->create($this->request, [...self::FIELDS, 'previous_advance' => false])->assertAccepted()->json('data.id');
        $developer = $this->makeUser('developer');
        app('auth')->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $developer->email, 'password' => 'secret123'])->assertCreated()->json('data.token');
        $view = $this->withToken($token)->postJson('/api/v1/auth/view-as', ['user_id' => $this->manager->id, 'reason' => 'Synthetic QA'])->assertCreated()->json('data.token');
        app('auth')->forgetGuards();

        $id = $this->withToken($view)->postJson(self::API, ['type' => 'advance_request', 'subject_id' => $this->request,
            'idempotency_key' => (string) Str::uuid7(), 'fields' => self::FIELDS])->assertAccepted()->assertJsonPath('data.shows_pay', false)->json('data.id');
        $this->assertArrayNotHasKey('salary', $this->snapshot($id));
        $this->withToken($view)->getJson(self::API."/{$hrCopy}")->assertNotFound();
        $this->withToken($view)->getJson(self::API)->assertUnprocessable();
    }

    public function test_previous_advance_is_optional_and_prints_blank(): void
    {
        $id = $this->create($this->request, self::FIELDS)->assertAccepted()->assertJsonPath('data.shows_pay', true)->json('data.id');
        $this->assertNull($this->snapshot($id)['previous_advance']);

        $html = $this->html($id);
        $this->assertStringContainsString('هل توجد سلفة سابقة؟', $html);
        $this->assertStringContainsString('8765.43', $html);
        $this->assertStringNotContainsString('نعم / Yes', $html);
        $this->assertStringNotContainsString('لا / No', $html);
    }

    public function test_a_manager_retries_their_own_failed_copy_and_nothing_else(): void
    {
        $certificate = $this->hrExport('experience_certificate');
        $siteB = $this->workSite('Site-B');
        $theirs = $this->advance($this->worker($siteB));
        $this->actingAs($this->managerOf($siteB), 'api');
        $outOfReach = $this->create($theirs, self::FIELDS)->assertAccepted()->json('data.id');
        $this->actingAs($this->manager, 'api');
        $own = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        array_map($this->markFailed(...), [$own, $outOfReach, $certificate]);

        $this->postJson(self::API."/{$own}/retry")->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertEquals([[$this->manager->id, ['status' => 'failed'], ['status' => 'pending']]], $this->retried($own));
        foreach ([$outOfReach, $certificate] as $id) {
            $this->postJson(self::API."/{$id}/retry")->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->assertSame([], $this->retried($id));
        }
    }

    public function test_a_manager_cannot_read_or_retry_a_certificate_leave_or_overtime_export(): void
    {
        $ids = array_map($this->hrExport(...), ['experience_certificate', 'leave_request', 'overtime_request']);

        $this->actingAs($this->manager, 'api');
        foreach ($ids as $id) {
            $this->getJson(self::API."/{$id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->get(self::API."/{$id}/download")->assertNotFound();
            $this->markFailed($id);
            $this->postJson(self::API."/{$id}/retry")->assertNotFound()->assertJsonPath('error.code', 'not_found');
            $this->assertSame([], $this->retried($id));
        }
    }

    public function test_a_manager_who_loses_the_site_cannot_read_or_retry_the_copy_they_made(): void
    {
        $this->actingAs($this->manager, 'api');
        $copy = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');
        $this->html($copy);
        $this->getJson(self::API."/{$copy}")->assertOk();
        $this->get(self::API."/{$copy}/download")->assertOk();

        DB::table('manager_sites')->where('manager_id', $this->manager->id)->delete();
        $this->getJson(self::API."/{$copy}")->assertNotFound();
        $this->get(self::API."/{$copy}/download")->assertNotFound();
        $this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->assertJsonCount(0, 'data');
        $this->markFailed($copy);
        $this->postJson(self::API."/{$copy}/retry")->assertNotFound();
        $this->assertSame([], $this->retried($copy));
    }

    public function test_rows_a_manager_receives_name_only_the_employee_in_reach_by_four_keys(): void
    {
        $this->create($this->request, [...self::FIELDS, 'previous_advance' => false])->assertAccepted();
        $worker = DB::table('employees')->where('id', $this->worker)->first(['code', 'name_ar', 'name_en']);
        $expected = ['id' => $this->worker, 'code' => (int) $worker->code, 'name_ar' => $worker->name_ar, 'name_en' => $worker->name_en];

        $this->actingAs($this->manager, 'api');
        $created = $this->create($this->request, self::FIELDS)->assertAccepted();
        $this->assertSame($expected, $created->json('data.employee'));
        $this->assertSame([$expected], array_column($this->getJson(self::API."?type=advance_request&subject_id={$this->request}")->assertOk()->json('data'), 'employee'));
        $this->assertSame($expected, $this->getJson(self::API.'/'.$created->json('data.id'))->assertOk()->json('data.employee'));
    }

    public function test_the_own_scope_list_filters_pages_and_total_never_count_the_pay_copy(): void
    {
        $hrCopy = $this->create($this->request, [...self::FIELDS, 'previous_advance' => false])->assertAccepted()->json('data.id');
        $this->markFailed($hrCopy);
        $this->actingAs($this->manager, 'api');
        $own = $this->create($this->request, self::FIELDS)->assertAccepted()->json('data.id');

        $list = self::API."?type=advance_request&subject_id={$this->request}";
        foreach (['' => [[$own], 1], '&stage=unsigned' => [[$own], 1], '&stage=signed' => [[], 0], '&status=pending' => [[$own], 1],
            '&status=failed' => [[], 0], '&per_page=1&page=1' => [[$own], 1], '&per_page=1&page=2' => [[], 1]] as $filter => [$ids, $total]) {
            $page = $this->getJson($list.$filter)->assertOk();
            $this->assertSame($ids, array_column($page->json('data'), 'id'), $filter);
            $this->assertSame($total, $page->json('meta.pagination.total'), $filter);
        }
    }

    private function managerHrFormGrants(): array
    {
        return DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where(['roles.slug' => 'manager', 'permissions.resource' => 'hr_forms'])->whereNull('permissions.deleted_at')->orderBy('permissions.action')
            ->get(['permissions.resource', 'permissions.action', 'permissions.scope'])->map(fn (object $p) => "{$p->resource}.{$p->action}.{$p->scope}")->all();
    }

    private function ownHrFormPermissions(): int
    {
        return DB::table('permissions')->where(['resource' => 'hr_forms', 'scope' => 'own'])->whereNull('deleted_at')->count();
    }

    /** A ready export of another type, made by HR for an employee in the manager's reach. */
    private function hrExport(string $type): string
    {
        $this->actingAs($this->hr, 'api');
        [$subject, $fields] = match ($type) {
            'experience_certificate' => [$this->worker($this->site, ['national_id' => '29901011234567', 'department' => 'QA']), ['job_title' => 'Synthetic']],
            'leave_request' => [$this->postJson('/api/v1/leave/requests', ['employee_id' => $this->worker, 'kind' => 'annual',
                'from_date' => '2026-11-05', 'to_date' => '2026-11-05', 'note' => 'Synthetic reason'])->assertCreated()->json('data.id'), ['job_title' => 'Synthetic']],
            'overtime_request' => [$this->overtime(), ['job_title' => 'Synthetic', 'reason' => 'Synthetic reason']],
        };
        $id = $this->postJson(self::API, ['type' => $type, 'subject_id' => $subject, 'idempotency_key' => (string) Str::uuid7(), 'fields' => $fields])
            ->assertAccepted()->json('data.id');
        $this->html($id);

        return $id;
    }

    private function overtime(): string
    {
        $id = (string) Str::uuid7();
        DB::table('attendance_requests')->insert(['id' => $id, 'employee_id' => $this->worker, 'site_id' => $this->site, 'type' => 'overtime',
            'date' => self::DATE, 'minutes' => 60, 'in_time' => '09:00', 'out_time' => '17:35', 'requested_by' => $this->hr->id]);

        return $id;
    }

    private function markFailed(string $id): void
    {
        DB::table('hr_form_exports')->where('id', $id)->update(['status' => 'failed', 'failure_code' => 'render_failed', 'sha256' => null, 'size_bytes' => null]);
    }

    /** @return list<array{0: string, 1: mixed, 2: mixed}> [user_id, old_values, new_values] of the row's RETRIED audit entries */
    private function retried(string $id): array
    {
        return DB::table('audit_log')->where(['event' => 'RETRIED', 'entity_type' => 'hr_form_exports', 'entity_id' => $id])->get()
            ->map(fn (object $row) => [$row->user_id, json_decode((string) $row->old_values, true), json_decode((string) $row->new_values, true)])->all();
    }

    /** A permanent worker homed on $site since January (male, so the salary label would read «راتب الموظف»). */
    private function worker(string $site, array $attributes = []): string
    {
        $id = $this->employee('permanent_worker', '2026-01-01', ['salary' => self::SALARY, ...$attributes]);
        $this->homeSite($id, $site, '2026-01-01');

        return $id;
    }

    /** An advance as the manager's site-day save stores it. */
    private function advance(string $employee, string $date = self::DATE): string
    {
        $id = (string) Str::uuid7();
        DB::table('attendance_requests')->insert(['id' => $id, 'employee_id' => $employee, 'site_id' => $this->site, 'type' => 'advance',
            'date' => $date, 'amount' => '250', 'requested_by' => $this->manager->id]);

        return $id;
    }

    private function create(string $subject, array $fields, ?string $key = null): TestResponse
    {
        return $this->postJson(self::API, ['type' => 'advance_request', 'subject_id' => $subject, 'idempotency_key' => $key ?? (string) Str::uuid7(), 'fields' => $fields]);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $id): array
    {
        return json_decode((string) DB::table('hr_form_exports')->where('id', $id)->value('snapshot'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Runs the render job on the row and returns the HTML it gave the renderer. */
    private function html(string $id): string
    {
        $html = '';
        $renderer = $this->createMock(PdfRendererInterface::class);
        $renderer->expects($this->once())->method('pdf')->willReturnCallback(function (string $page) use (&$html): string {
            $html = $page;

            return '%PDF-1.7 synthetic';
        });
        (new RenderPdf($id))->handle($renderer);

        return $html;
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// The leave balance's data: three leave types HR edits (so this writes their audit rows), the yearly adjustment, the `leave` source,
// the sick-pay setting and the leave permissions.
return new class extends Migration
{
    private const LEAVE_TYPES = ['annual' => 'اعتيادية', 'casual' => 'عارضة', 'unpaid' => 'بدون أجر'];

    private const GRANTS = [
        'leave.view.all' => ['developer', 'hr', 'ceo'],
        'leave.view.own' => ['manager'],
        'leave.request.all' => ['developer', 'hr'],
        'leave.request.own' => ['manager'],
        'leave.decide.all' => ['developer', 'hr'],
        'leave.decide_escalated.all' => ['developer', 'ceo'],
        'leave.adjust.all' => ['developer', 'hr'],
    ];

    private const SETTINGS = ['sick_leave_pay_percent' => '100'];

    public function up(): void
    {
        // A leave type down() kept (an attendance day used it) is reused, not a clash.
        $live = DB::table('sites')->whereNull('deleted_at')->whereIn('name_ar', self::LEAVE_TYPES)->get();
        $clashes = $live->reject(fn (object $s) => $s->kind === 'leave' && array_search($s->name_ar, self::LEAVE_TYPES, true) === $s->leave_code)->pluck('name_ar');
        if ($clashes->isNotEmpty()) {
            throw new RuntimeException('A site already has the name(s) '.$clashes->implode(', ').'; rename it, then migrate again.');
        }

        $now = now();
        foreach (self::LEAVE_TYPES as $code => $name) {
            if (! $live->contains('name_ar', $name)) {
                $row = ['id' => (string) Str::uuid7(), 'name_ar' => $name, 'kind' => 'leave', 'leave_code' => $code, 'active' => true, 'outside_alexandria' => false];
                DB::table('sites')->insert([...$row, 'created_at' => $now, 'updated_at' => $now]);
                $this->audit('CREATED', $row['id'], null, $row);
            }
        }

        $this->sourceCheck(['entered', 'imported', 'derived', 'leave']);

        Schema::create('leave_year_adjustments', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->smallInteger('year');
            $t->smallInteger('entitlement')->nullable();
            $t->smallInteger('opening_used')->default(0);
            $t->smallInteger('opening_casual_used')->default(0);
            $t->text('reason')->nullable();
            $t->integer('version')->default(1);
        });
        DB::statement('alter table leave_year_adjustments add constraint leave_year_adjustments_entitlement_check check (entitlement is null or entitlement >= 0)');
        DB::statement('alter table leave_year_adjustments add constraint leave_year_adjustments_opening_used_check check (opening_used >= 0)');
        DB::statement('alter table leave_year_adjustments add constraint leave_year_adjustments_opening_casual_used_check check (opening_casual_used >= 0)');
        DB::statement('create unique index leave_year_adjustments_employee_year_unique on leave_year_adjustments (employee_id, year) where deleted_at is null');

        DB::table('settings')->insert(array_map(
            fn (string $key, string $value) => ['id' => (string) Str::uuid7(), 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now],
            array_keys(self::SETTINGS),
            self::SETTINGS,
        ));

        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::GRANTS as $name => $slugs) {
            [$resource, $action, $scope] = explode('.', $name);
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn (string $slug) => ['role_id' => $roles[$slug], 'permission_id' => $id], $slugs));
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
        DB::table('settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
        Schema::dropIfExists('leave_year_adjustments');
        $this->sourceCheck(['entered', 'imported', 'derived']);

        // A leave type an attendance day uses stays, so the day keeps its meaning.
        $unused = DB::table('sites')->where('kind', 'leave')->whereIn('leave_code', array_keys(self::LEAVE_TYPES))
            ->whereNotExists(fn (Builder $q) => $q->from('attendance_days')->whereColumn('attendance_days.site_id', 'sites.id'))->get();
        DB::table('sites')->whereIn('id', $unused->pluck('id'))->delete();
        foreach ($unused as $site) {
            $this->audit('DELETED', $site->id, ['name_ar' => $site->name_ar, 'kind' => $site->kind, 'leave_code' => $site->leave_code], null);
        }
    }

    /** @param  list<string>  $sources */
    private function sourceCheck(array $sources): void
    {
        DB::statement('alter table attendance_days drop constraint attendance_days_source_check');
        DB::statement("alter table attendance_days add constraint attendance_days_source_check check (source in ('".implode("', '", $sources)."'))");
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(string $event, string $id, ?array $old, ?array $new): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => null, 'event' => $event, 'entity_type' => 'sites', 'entity_id' => $id,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);
    }
};

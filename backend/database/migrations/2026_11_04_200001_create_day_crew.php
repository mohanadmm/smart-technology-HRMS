<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// The site manager's day-labor crew (owner, 2026-11-04; PR #22 review):
// - a crew change holds for one date only (day_crew_changes): it puts a day laborer on a site, or on none, for that day, and leaves their
//   home-site history alone, so yesterday's records and tomorrow's roster are unchanged;
// - a day laborer a site manager adds is entered with their gender, which stays required (owner, 2026-11-05);
// - their proposed daily wage waits in employee_wage_proposals and is never a pay row until HR approves it.
return new class extends Migration
{
    private const GRANTS = [
        'employee.crew.own' => ['manager'],
        'employee.propose_laborer.own' => ['manager'],
        'employee.approve_wage.all' => ['developer', 'hr'],
    ];

    public function up(): void
    {
        Schema::create('day_crew_changes', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('date');
            $t->foreignUuid('site_id')->nullable()->constrained('sites');   // null: off every crew that day
        });
        DB::statement('create unique index day_crew_changes_one_per_day on day_crew_changes (employee_id, date) where deleted_at is null');

        Schema::create('employee_wage_proposals', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->money('daily_wage');
            $t->string('status', 10)->default('pending');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->integer('version')->default(1);
        });
        DB::statement("alter table employee_wage_proposals add constraint employee_wage_proposals_status_check check (status in ('pending', 'approved', 'refused'))");
        DB::statement('alter table employee_wage_proposals add constraint employee_wage_proposals_daily_wage_check check (daily_wage > 0)');
        DB::statement("create unique index employee_wage_proposals_one_pending on employee_wage_proposals (employee_id) where status = 'pending' and deleted_at is null");

        $now = now();
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::GRANTS as $name => $slugs) {
            [$resource, $action, $scope] = explode('.', $name);
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn (string $slug) => ['role_id' => $roles[$slug], 'permission_id' => $id], array_filter($slugs, fn (string $s) => isset($roles[$s]))));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
        Schema::dropIfExists('employee_wage_proposals');
        Schema::dropIfExists('day_crew_changes');
    }
};

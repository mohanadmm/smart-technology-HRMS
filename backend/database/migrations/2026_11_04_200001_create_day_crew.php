<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// The site manager's day-labor crew (owner, 2026-11-04):
// - a home-site row may name no site, so "removed from today's crew" is a dated row like any move, and past days keep their site;
// - a day laborer a site manager adds has no gender until HR completes the record;
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
        DB::statement('alter table employee_site_assignments alter column site_id drop not null');
        DB::statement('alter table employees alter column gender drop not null');

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
        // Refuse rather than invent data: a removed-from-crew row or a gender HR has not filled in has no value to go back to.
        if (DB::table('employee_site_assignments')->whereNull('site_id')->exists() || DB::table('employees')->whereNull('gender')->exists()) {
            throw new RuntimeException('Some home-site rows name no site, or some employees have no gender; fix them, then roll back.');
        }
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }
        Schema::dropIfExists('employee_wage_proposals');
        DB::statement('alter table employees alter column gender set not null');
        DB::statement('alter table employee_site_assignments alter column site_id set not null');
    }
};

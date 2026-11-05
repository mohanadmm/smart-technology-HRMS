<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// HR and the developer set every transport fare; team leads only read their team's (owner, 2026-11-05, option C).
// `all` goes to every role that holds site.manage.all now, so a role the developer renamed or added keeps working.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $all = (string) Str::uuid7();
        DB::table('permissions')->insert(['id' => $all, 'resource' => 'fare', 'action' => 'manage', 'scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);

        $managers = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where(['p.resource' => 'site', 'p.action' => 'manage', 'p.scope' => 'all'])->whereNull('p.deleted_at')
            ->pluck('rp.role_id');
        DB::table('role_permissions')->insert($managers->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $all])->all());
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        DB::table('permissions')->where(['resource' => 'fare', 'action' => 'manage'])->delete();
    }
};

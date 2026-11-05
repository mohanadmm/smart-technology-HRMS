<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Team leads set the transport fare for their own sites; HR and the developer for any (owner, 2026-11-05, option A).
// `all` goes to every role that holds site.manage.all now, so a role the developer renamed or added keeps working.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $all = $this->insert('all', $now);
        $own = $this->insert('own', $now);

        $managers = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where(['p.resource' => 'site', 'p.action' => 'manage', 'p.scope' => 'all'])->whereNull('p.deleted_at')
            ->pluck('rp.role_id');
        DB::table('role_permissions')->insert($managers->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $all])->all());

        $teamLead = DB::table('roles')->where('slug', 'manager')->value('id');
        if ($teamLead !== null) {
            DB::table('role_permissions')->insert(['role_id' => $teamLead, 'permission_id' => $own]);
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        DB::table('permissions')->where(['resource' => 'fare', 'action' => 'manage'])->delete();
    }

    private function insert(string $scope, mixed $now): string
    {
        $id = (string) Str::uuid7();
        DB::table('permissions')->insert(['id' => $id, 'resource' => 'fare', 'action' => 'manage', 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    // HR renumbers employee IDs (owner, 2026-11-03).
    private const GRANTS = ['employee.edit_code.all' => ['developer', 'hr']];

    public function up(): void
    {
        $now = now();
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
    }
};

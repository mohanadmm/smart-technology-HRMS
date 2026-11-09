<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $permission = (string) Str::uuid7();
        DB::table('permissions')->insert([
            'id' => $permission, 'resource' => 'user', 'action' => 'manage_staff', 'scope' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('slug', 'hr')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
    }

    public function down(): void
    {
        DB::table('permissions')->where(['resource' => 'user', 'action' => 'manage_staff', 'scope' => 'all'])->delete();
    }
};

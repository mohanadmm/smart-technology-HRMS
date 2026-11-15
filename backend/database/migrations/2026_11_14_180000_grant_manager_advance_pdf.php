<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The site manager generates and reads the advance PDF of workers in reach, as the copy without the salary (Step 2, task 2c). */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['resource' => 'hr_forms', 'action' => 'generate', 'scope' => 'own'],
        ['resource' => 'hr_forms', 'action' => 'view', 'scope' => 'own'],
    ];

    /** Roles and permissions are data: a row the Developer already made in the console is granted, not inserted again. */
    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid7(), ...$permission, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('permissions')->where($permission)->whereNull('deleted_at')->value('id');
            foreach (DB::table('roles')->where('slug', 'manager')->pluck('id') as $role) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->where($permission)->delete();
        }
    }
};

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

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, ...$permission, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('roles')->where('slug', 'manager')->pluck('id') as $role) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
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

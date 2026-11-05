<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Databases that ran the option A version of 000001 still grant fare.manage.own to team leads; option C has no `own` scope.
// role_permissions rows go with the permission (cascadeOnDelete), and the grant cache is cleared after every migrate.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where(['resource' => 'fare', 'action' => 'manage', 'scope' => 'own'])->delete();
    }

    public function down(): void
    {
        // Option A is not restored.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $t): void {
            $t->uuid('ref')->nullable();
            $t->dropUnique(['user_id', 'type', 'date']);
        });
        // NULLS NOT DISTINCT keeps a ref-less notification once per (user, type, date), as before.
        DB::statement('ALTER TABLE notifications ADD CONSTRAINT notifications_user_id_type_date_ref_unique UNIQUE NULLS NOT DISTINCT (user_id, type, date, ref)');
    }

    public function down(): void
    {
        // Fails while two notifications share (user, type, date) with different refs; delete those first.
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_user_id_type_date_ref_unique');
        Schema::table('notifications', function (Blueprint $t): void {
            $t->unique(['user_id', 'type', 'date']);
            $t->dropColumn('ref');
        });
    }
};

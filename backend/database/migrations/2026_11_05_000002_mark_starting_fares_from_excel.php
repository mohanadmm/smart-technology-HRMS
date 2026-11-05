<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A data migration. Every fare and fare override that exists now is a starting value: the Excel import (hrms:import-master
// signs in as an HR account, so its rows carry that account) or HR's setup, written before team leads could set fares.
// The Fares list reads a row with no writer as «من الإكسيل», so both writer columns are cleared. Who really wrote each row
// stays in its CREATED audit entry. A later edit sets updated_by again, so an edited row names its editor.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fares', 'employee_fare_overrides'] as $table) {
            DB::table($table)->update(['created_by' => null, 'updated_by' => null]);
        }
    }

    public function down(): void
    {
        // The cleared writers are not restored; the audit log keeps them.
    }
};

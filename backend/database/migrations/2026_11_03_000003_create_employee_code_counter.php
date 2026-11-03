<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The highest employee ID ever issued, so renumbering never makes create() reissue one (owner, 2026-11-03). A one-row table, not a
    // sequence: a sequence ignores rollbacks, so the master-data import's dry run (and every test) would burn numbers.
    public function up(): void
    {
        Schema::create('employee_code_counter', fn (Blueprint $t) => $t->unsignedInteger('last_issued'));
        DB::statement('INSERT INTO employee_code_counter (last_issued) SELECT coalesce(max(code), 0) FROM employees');
    }

    public function down(): void
    {
        Schema::drop('employee_code_counter');
    }
};

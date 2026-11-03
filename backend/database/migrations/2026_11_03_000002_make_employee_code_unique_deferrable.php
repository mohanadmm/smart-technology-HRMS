<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Deferrable so a swap of two employees' codes can check uniqueness at the end of its transaction (EmployeeService::changeCode).
    // A deferrable unique cannot be an ON CONFLICT arbiter; nothing upserts on code.
    public function up(): void
    {
        DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_code_unique, ADD CONSTRAINT employees_code_unique UNIQUE (code) DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employees DROP CONSTRAINT employees_code_unique, ADD CONSTRAINT employees_code_unique UNIQUE (code)');
    }
};

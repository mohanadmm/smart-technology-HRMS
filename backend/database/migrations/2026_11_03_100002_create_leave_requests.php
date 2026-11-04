<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $t->string('kind', 16);
            $t->date('from_date');
            $t->date('to_date');
            $t->smallInteger('days');
            $t->text('note')->nullable();
            $t->string('status', 16)->default('pending');
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->integer('version')->default(1);
            $t->index(['status', 'from_date']);
            $t->index(['employee_id', 'from_date']);
        });
        DB::statement("alter table leave_requests add constraint leave_requests_kind_check check (kind in ('annual', 'casual', 'sick', 'unpaid'))");
        DB::statement("alter table leave_requests add constraint leave_requests_status_check check (status in ('pending', 'escalated', 'approved', 'refused', 'cancelled'))");
        DB::statement('alter table leave_requests add constraint leave_requests_dates_check check (from_date <= to_date)');
    }

    public function down(): void
    {
        Schema::drop('leave_requests');
    }
};

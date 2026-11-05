<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Developer-console request and error log (not business tables: no standardColumns). Rows are masked before insert. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_log', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('request_id', 64);
            $t->uuid('user_id')->nullable();
            $t->string('method', 10);
            $t->string('path', 2048);
            $t->string('route', 255)->nullable();
            $t->jsonb('query')->nullable();
            $t->smallInteger('status');
            $t->integer('duration_ms');
            $t->ipAddress()->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->jsonb('request_body')->nullable();
            $t->jsonb('response_body')->nullable();
            $t->timestampTz('occurred_at');
        });
        DB::statement('CREATE INDEX request_log_occurred_at_index ON request_log (occurred_at DESC)');
        DB::statement('CREATE INDEX request_log_status_index ON request_log (status, occurred_at DESC)');
        DB::statement('CREATE INDEX request_log_request_id_index ON request_log (request_id)');
        DB::statement('CREATE INDEX request_log_user_index ON request_log (user_id, occurred_at DESC)');

        Schema::create('error_log', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('request_id', 64)->nullable();
            $t->uuid('user_id')->nullable();
            $t->string('class', 255);
            $t->text('message');
            $t->string('file', 1024)->nullable();
            $t->integer('line')->nullable();
            $t->text('trace');
            $t->string('sqlstate', 5)->nullable();
            $t->timestampTz('occurred_at');
        });
        DB::statement('CREATE INDEX error_log_occurred_at_index ON error_log (occurred_at DESC)');
        DB::statement('CREATE INDEX error_log_request_id_index ON error_log (request_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('error_log');
        Schema::dropIfExists('request_log');
    }
};

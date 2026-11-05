<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retention: the trigger lets DELETE through only for rows older than 1 year (the same cutoff as AuditLogEntry::prunable()).
 * TG_OP is checked before OLD is read (the TRUNCATE trigger is statement level, with no OLD); RETURN OLD, never NULL (that silently skips the row).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_is_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.occurred_at < now() - interval '1 year' THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'audit_log is append-only: % is refused', TG_OP;
            END;
            $$
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_is_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is refused', TG_OP;
            END;
            $$
        SQL);
    }
};

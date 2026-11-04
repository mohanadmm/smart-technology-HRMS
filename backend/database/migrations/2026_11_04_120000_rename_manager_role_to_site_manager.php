<?php

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A data migration: the `manager` role is shown as Site manager / مدير الموقع (owner, 2026-11-04). Only the display name
// changes; the slug, permissions and assignments stay. A name the developer already edited by hand is left alone.
return new class extends Migration
{
    private const OLD = ['name' => 'Manager', 'name_ar' => 'مدير'];

    private const NEW = ['name' => 'Site manager', 'name_ar' => 'مدير الموقع'];

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    /**
     * @param  array{name: string, name_ar: string}  $from
     * @param  array{name: string, name_ar: string}  $to
     */
    private function rename(array $from, array $to): void
    {
        $id = DB::table('roles')->where(['slug' => 'manager', ...$from])->whereNull('deleted_at')->value('id');
        if ($id !== null) {
            DB::table('roles')->where('id', $id)->update([...$to, 'updated_at' => now()]);
            AuditLog::record(IdentityAuditEvents::ROLE_UPDATED, 'roles', (string) $id, $from, $to);
        }
    }
};

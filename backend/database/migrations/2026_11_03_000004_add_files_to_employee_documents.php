<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Each document row is one uploaded file; the received date is gone (owner, 2026-11-03).
    private const GRANTS = [
        'employee_document.view.all' => ['developer', 'hr', 'ceo'],
        'employee_document.view.own' => [], // seeded for the Developer to grant to managers (D2)
        'employee_document.edit.all' => ['developer', 'hr'],
    ];

    public function up(): void
    {
        // IF EXISTS: down() does not restore the unique, so up() must still run after a rollback.
        DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_employee_id_kind_unique');
        Schema::table('employee_documents', function (Blueprint $t): void {
            $t->dropColumn('received_at');
            // NOT NULL without a default: the test server had 0 rows (D7).
            $t->string('original_name', 255);
            $t->string('mime', 50);
            $t->integer('size_bytes');
            $t->index(['employee_id', 'kind']); // the dropped unique was the only index on the foreign key
        });
        DB::statement('ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_size_bytes_positive CHECK (size_bytes > 0)');

        $now = now();
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::GRANTS as $name => $slugs) {
            [$resource, $action, $scope] = explode('.', $name);
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn (string $slug) => ['role_id' => $roles[$slug], 'permission_id' => $id], $slugs));
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }

        // received_at comes back empty: the old dates live on in the audit log's CREATED entries. The unique (employee_id, kind)
        // is not restored: it fails as soon as an employee has two files of one kind.
        Schema::table('employee_documents', function (Blueprint $t): void {
            $t->dropIndex(['employee_id', 'kind']);
            $t->dropColumn(['original_name', 'mime', 'size_bytes']); // the size check goes with its column
            $t->date('received_at')->nullable();
        });
    }
};

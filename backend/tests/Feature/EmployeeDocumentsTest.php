<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\EmployeeDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->signIn('hr');
        $this->travelTo('2026-11-03 10:00:00');
    }

    private function employee(): string
    {
        return $this->postJson('/api/v1/employees', ['name_ar' => 'موظف', 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-01-01', 'salary' => '9000'])->json('data.id');
    }

    public function test_the_table_has_the_file_columns_and_no_received_date(): void
    {
        $this->assertFalse(Schema::hasColumn('employee_documents', 'received_at'));
        $this->assertFalse(Schema::hasColumn('employee_documents', 'path'));
        $this->assertTrue(Schema::hasColumns('employee_documents', ['original_name', 'mime', 'size_bytes']));
        $this->expectException(QueryException::class);
        EmployeeDocument::create(['employee_id' => $this->employee(), 'kind' => 'other', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 0]);
    }
}

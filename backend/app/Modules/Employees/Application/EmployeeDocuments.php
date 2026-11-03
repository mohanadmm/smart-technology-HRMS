<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Modules\Employees\Infrastructure\Eloquent\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class EmployeeDocuments
{
    /**
     * Stores the file under a name built from a new id and the detected type, then inserts the row (and soft-deletes the
     * replaced one) in one transaction; a failure after the write deletes the new file.
     *
     * @param  array<string, mixed>  $data  kind, and optional expires_at, note, replaces (the id of a live document of this employee)
     * @return array<string, mixed>
     */
    public function upload(string $employeeId, UploadedFile $file, array $data, bool $files): array
    {
        $employee = Employee::findOrFail($employeeId);
        $mime = (string) $file->getMimeType();
        EmployeeDocument::EXT[$mime] ?? throw ValidationException::withMessages(['file' => __('errors.document_type')]);

        $doc = new EmployeeDocument([
            'id' => (string) Str::uuid7(),
            'employee_id' => $employee->id,
            'kind' => $data['kind'],
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? null,
            'original_name' => self::cleanName($file->getClientOriginalName()),
            'mime' => $mime,
            'size_bytes' => $file->getSize(),
        ]);

        // The disk has 'throw' => false: a failed write returns false.
        if (Storage::disk('local')->putFileAs(dirname($doc->path()), $file, basename($doc->path())) === false) {
            throw new RuntimeException("Could not store {$doc->path()}");
        }

        try {
            DB::transaction(function () use ($employee, $doc, $data): void {
                // The soft-delete scope makes this the liveness check; Postgres re-checks the row after the lock.
                $old = isset($data['replaces']) ? $employee->documents()->lockForUpdate()->findOrFail((string) $data['replaces']) : null;
                $doc->save();
                $old?->delete();
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($doc->path());
            throw $e;
        }

        return self::row($doc->load('uploader'), $files);
    }

    /** @return array<string, mixed> without the view grant only id, kind and expiry; never the path */
    public static function row(EmployeeDocument $d, bool $files): array
    {
        $row = Arr::only($d->toArray(), ['id', 'kind', 'expires_at', ...($files ? ['original_name', 'mime', 'size_bytes', 'note', 'created_at'] : [])]);

        return $files ? [...$row, 'uploader_name' => $d->uploader?->name] : $row;
    }

    /** Drops control and bidi characters (a U+202E can flip how the extension reads); null on invalid UTF-8 becomes ''. */
    private static function cleanName(string $name): string
    {
        $clean = mb_substr(preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?? '', 0, 255);

        return $clean === '' ? 'document' : $clean;
    }
}

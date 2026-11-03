<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeDocuments;
use App\Modules\Employees\Domain\DocumentKind;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EmployeeDocumentController
{
    public const MAX_KB = 10240;

    public function __construct(private readonly EmployeeDocuments $documents) {}

    public function store(Request $request, string $employee): JsonResponse
    {
        $tooLarge = __('errors.document_too_large', ['max' => self::MAX_KB / 1024]);
        $data = $request->validate([
            'file' => ['required', File::types(EmployeeDocuments::extensions())->max(self::MAX_KB)],
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string'],
            'replaces' => ['nullable', 'uuid'],
        ], [
            // `uploaded`: PHP dropped a file above its own limit before the app saw it.
            'file.uploaded' => $tooLarge,
            'file.max' => $tooLarge,
            'file.mimes' => __('errors.document_type'),
        ]);

        return ApiEnvelope::single($request, $this->documents->upload($employee, $request->file('file'), Arr::except($data, 'file')), 201);
    }

    public function update(Request $request, string $employee, string $document): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['sometimes', Rule::enum(DocumentKind::class)],
            'expires_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string'],
        ]);

        return ApiEnvelope::single($request, $this->documents->update($employee, $document, $data));
    }

    public function destroy(string $employee, string $document): Response
    {
        $this->documents->delete($employee, $document);

        return response()->noContent();
    }

    public function file(string $employee, string $document): StreamedResponse
    {
        $f = $this->documents->file($employee, $document);
        abort_unless(Storage::disk('local')->exists($f['path']), 404);

        return Storage::disk('local')->response($f['path'], $f['name'], [
            'Content-Type' => $f['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

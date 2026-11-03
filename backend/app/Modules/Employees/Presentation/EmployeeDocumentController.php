<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeDocuments;
use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

final class EmployeeDocumentController
{
    public const MAX_KB = 10240;

    public function __construct(private readonly EmployeeDocuments $documents, private readonly AuthorizeAction $authorize) {}

    public function store(Request $request, string $employee): JsonResponse
    {
        $tooLarge = __('errors.document_too_large', ['max' => self::MAX_KB / 1024]);
        $data = $request->validate([
            'file' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(self::MAX_KB)],
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

        return ApiEnvelope::single($request, $this->documents->upload($employee, $request->file('file'), Arr::except($data, 'file'), $this->seesFiles($request)), 201);
    }

    private function seesFiles(Request $request): bool
    {
        return $this->authorize->decide($request->user() ?? throw new AuthenticationException, 'employee_document.view')->granted();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Pdf\Application\PdfExports;
use App\Modules\Pdf\Application\RenderPdf;
use App\Modules\Pdf\Domain\PdfType;
use App\Support\Http\ApiEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** The one HR-form API: every PdfType through `hr-form-exports`. */
final class PdfController
{
    public function __construct(private readonly PdfExports $exports, private readonly EmployeeRoster $roster) {}

    public function index(Request $request): JsonResponse
    {
        $scope = RequestScope::ownerId($request);
        $own = $scope !== null;
        $filters = $request->validate([
            // An own-scope caller lists one subject of an own-scope type: reach is judged on that subject's request date.
            'type' => [Rule::requiredIf($own), 'nullable', 'required_with:subject_id',
                Rule::enum(PdfType::class)->when($own, fn (Enum $rule) => $rule->only(array_filter(PdfType::cases(), fn (PdfType $type) => $type->ownScope())))],
            'subject_id' => [Rule::requiredIf($own), 'nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'stage' => ['nullable', 'in:unsigned,signed'],
            'status' => ['nullable', 'in:pending,ready,failed'],
            'awaiting_approval' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [], ['type' => __('validation.attributes.kind')]);
        $actor = self::actor($request);
        $rows = $this->exports->list($filters, $actor, $scope);
        $employees = $this->roster->names(array_values(array_unique(array_filter(array_map(fn (stdClass $row) => (string) $row->employee_id, $rows->items())))));

        return ApiEnvelope::collection($request, array_values(array_map(fn (stdClass $row) => $this->row($row, $actor, $employees), $rows->items())), [
            'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(),
            'total_pages' => $rows->lastPage(), 'has_next_page' => $rows->hasMorePages(),
            'has_previous_page' => $rows->currentPage() > 1,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(PdfType::class)],
            'subject_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'uuid'],
            'fields' => ['required', 'array'],
        ], [], ['type' => __('validation.attributes.kind')]);
        $type = PdfType::from($data['type']);
        $fields = $request->validate(Arr::prependKeysWith(PdfExports::document($type)->rules(), 'fields.'), [
            'fields.paper_decision_date.missing' => __('errors.advance_paper_date_pending'),
        ])['fields'] ?? [];
        $actor = self::actor($request);
        $row = $this->exports->create($type, $data['subject_id'], $data['idempotency_key'], $fields, $actor, RequestScope::ownerId($request));

        return ApiEnvelope::single($request, $this->queued($row, $actor), 202);
    }

    public function show(Request $request, string $export): JsonResponse
    {
        $actor = self::actor($request);

        return ApiEnvelope::single($request, $this->row($this->exports->visible($export, $actor, RequestScope::ownerId($request)), $actor));
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $row = $this->exports->visible($export, self::actor($request), RequestScope::ownerId($request));
        $disk = Storage::disk('local');
        $path = (string) $row->storage_path;
        abort_unless($row->status === 'ready' && $disk->exists($path), 404);

        return $disk->download($path, self::fileName($row), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, string $export): JsonResponse
    {
        $actor = self::actor($request);

        return ApiEnvelope::single($request, $this->queued($this->exports->approve($export, $actor), $actor), 202);
    }

    public function retry(Request $request, string $export): JsonResponse
    {
        $actor = self::actor($request);

        return ApiEnvelope::single($request, $this->queued($this->exports->retry($export, $actor), $actor), 202);
    }

    /** @return array<string, mixed> */
    private function queued(stdClass $row, Authenticatable $actor): array
    {
        if ($row->status === 'pending') {
            try {
                Bus::dispatch(new RenderPdf((string) $row->id));
            } catch (Throwable $e) {
                report($e);
                $row = $this->exports->dispatchFailed((string) $row->id);
            }
        }

        return $this->row($row, $actor);
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $employees  the page's employees by id (one query for a list); null reads the row's own
     * @return array<string, mixed> never the snapshot; `employee` is the four naming keys only, as a manager receives this row too
     */
    private function row(stdClass $row, Authenticatable $actor, ?array $employees = null): array
    {
        $employeeId = (string) $row->employee_id;
        $employees ??= $this->roster->names($employeeId === '' ? [] : [$employeeId]);

        return [
            'id' => $row->id, 'type' => $row->form_type,
            ...array_intersect_key((array) $row, array_flip([
                'serial_number', 'stage', 'status', 'employee_id', 'unsigned_export_id', 'regenerated_from_id',
                'approved_by', 'approved_at', 'impersonator_id', 'failure_code', 'sha256', 'size_bytes', 'created_at',
            ])),
            'file_name' => self::fileName($row),
            'shows_pay' => PdfExports::showsPay($row),
            'employee' => $employees[$employeeId] ?? null,
            'actions' => $this->exports->actions($row, $actor),
        ];
    }

    private static function fileName(stdClass $row): string
    {
        return PdfType::from((string) $row->form_type)->fileName((int) $row->serial_number, (string) $row->stage);
    }

    private static function actor(Request $request): Authenticatable
    {
        return $request->user() ?? throw new AuthenticationException;
    }
}

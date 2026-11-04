<?php

declare(strict_types=1);

namespace App\Modules\Leave\Presentation;

use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Leave\Application\LeaveRequests;
use App\Modules\Leave\Domain\LeaveKind;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LeaveRequestController
{
    public function __construct(private readonly LeaveRequests $requests) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', Rule::enum(LeaveStatus::class)],
            'employee_id' => ['sometimes', 'uuid'],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $list = $this->requests->list(
            $v['status'] ?? null, $v['employee_id'] ?? null, isset($v['year']) ? (int) $v['year'] : null,
            (int) ($v['per_page'] ?? 20), (int) ($v['page'] ?? 1), RequestScope::ownerId($request),
        );

        return ApiEnvelope::collection($request, $list['data'], $list['pagination']);
    }

    public function preview(Request $request): JsonResponse
    {
        $v = $request->validate(self::range());

        return ApiEnvelope::single($request, $this->requests->preview(
            $v['employee_id'], LeaveKind::from($v['kind']), $v['from_date'], $v['to_date'], RequestScope::ownerId($request),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{employee_id: string, kind: string, from_date: string, to_date: string, note?: ?string} $v */
        $v = $request->validate([...self::range(), 'note' => ['nullable', 'string', 'max:1000']]);

        return ApiEnvelope::single($request, $this->requests->submit($v, (string) $request->user()?->getAuthIdentifier(), RequestScope::ownerId($request)), 201);
    }

    /** @return array<string, list<mixed>> */
    private static function range(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::enum(LeaveKind::class)],
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date', 'before_or_equal:2100-12-31'],
        ];
    }
}

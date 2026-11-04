<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Domain\Gender;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HR's review of the daily wages site managers proposed for the day laborers they added (`employee.approve_wage.all`). */
final class WageProposalController
{
    public function __construct(private readonly DayCrew $crew) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = $this->crew->pending((int) ($q['per_page'] ?? 20), (int) ($q['page'] ?? 1));

        return ApiEnvelope::collection($request, $page['data'], $page['pagination']);
    }

    public function approve(Request $request, string $proposal): JsonResponse
    {
        $q = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'daily_wage' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/D', 'not_regex:/^0+(\.0+)?$/D'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
        ]);
        $this->crew->decide($proposal, (int) $q['version'], true, $q['daily_wage'] ?? null, $q['gender'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return ApiEnvelope::single($request, ['id' => $proposal, 'status' => 'approved']);
    }

    public function refuse(Request $request, string $proposal): JsonResponse
    {
        $q = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->crew->decide($proposal, (int) $q['version'], false, null, null, (string) $request->user()?->getAuthIdentifier());

        return ApiEnvelope::single($request, ['id' => $proposal, 'status' => 'refused']);
    }
}

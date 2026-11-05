<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\DayCrew;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Domain\WageProposalStatus;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'daily_wage' => ['nullable', 'string', EmployeeRules::MONEY, EmployeeRules::POSITIVE],
        ]);
        $this->crew->decide($proposal, (int) $q['version'], true, $q['daily_wage'] ?? null, $this->hr($request), self::today());

        return ApiEnvelope::single($request, ['id' => $proposal, 'status' => WageProposalStatus::Approved->value]);
    }

    public function refuse(Request $request, string $proposal): JsonResponse
    {
        $q = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->crew->decide($proposal, (int) $q['version'], false, null, $this->hr($request), self::today());

        return ApiEnvelope::single($request, ['id' => $proposal, 'status' => WageProposalStatus::Refused->value]);
    }

    private function hr(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /** Cairo's date: a refused laborer's last day */
    private static function today(): string
    {
        return now('Africa/Cairo')->toDateString();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sites\Presentation;

use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Sites\Application\FareService;
use App\Modules\Sites\Application\SiteRules;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FareController
{
    public function __construct(private readonly FareService $fares) {}

    public function index(Request $request): JsonResponse
    {
        $r = $this->fares->listFares($request->validate([
            'residence_id' => ['sometimes', 'uuid'],
            'site_id' => ['sometimes', 'uuid'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'manager_id' => ['sometimes', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]), RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(SiteRules::fare());

        return ApiEnvelope::single($request, $this->fares->saveFare($data), 201);
    }

    public function override(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate(SiteRules::fareOverride());

        return ApiEnvelope::single($request, $this->fares->saveOverride($employee, $data), 201);
    }

    public function show(Request $request, string $employee): JsonResponse
    {
        $q = $request->validate(['site_id' => SiteRules::fareOverride()['site_id'], 'date' => ['sometimes', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->fares->describe($employee, $q['site_id'], $q['date'] ?? now('Africa/Cairo')->toDateString(), RequestScope::ownerId($request)));
    }

    public function team(Request $request): JsonResponse
    {
        $ownerId = RequestScope::ownerId($request);
        $q = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'manager_id' => [Rule::requiredIf($ownerId === null), 'uuid'],
        ]);

        // An own-scope user always gets their own team, whatever manager_id says.
        return ApiEnvelope::single($request, $this->fares->team($ownerId ?? $q['manager_id'], $q['date'] ?? now('Africa/Cairo')->toDateString()));
    }
}

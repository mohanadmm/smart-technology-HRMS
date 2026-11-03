<?php

declare(strict_types=1);

namespace App\Modules\Leave\Presentation;

use App\Modules\Leave\Application\LeaveBalances;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveBalanceController
{
    public function __construct(private readonly LeaveBalances $balances) {}

    public function show(Request $request, string $employee): JsonResponse
    {
        $q = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,2100']]);

        return ApiEnvelope::single($request, $this->balances->forEmployee($employee, (int) ($q['year'] ?? now('Africa/Cairo')->year)));
    }
}

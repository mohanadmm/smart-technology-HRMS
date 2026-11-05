<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation;

use App\Modules\Audit\Application\RequestLogQuery;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RequestLogController
{
    public function index(Request $request, RequestLogQuery $log): JsonResponse
    {
        $f = $request->validate([
            'status' => ['sometimes', 'in:failed,server'],
            'method' => ['sometimes', 'in:GET,POST,PUT,PATCH,DELETE'],
            'user_id' => ['sometimes', 'uuid'],
            'path' => ['sometimes', 'string', 'max:200'],
            'request_id' => ['sometimes', 'string', 'max:64'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $r = $log->list($f);

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }
}

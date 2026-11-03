<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\DocumentKind;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Presentation\RequestScope;
use App\Modules\Sites\Domain\SiteKind;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class EmployeeController
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'type' => ['sometimes', Rule::enum(EmployeeType::class)],
            'site_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'archived' => ['sometimes', 'in:true,false,1,0'],
        ]);
        $r = $this->employees->list([...$f, 'archived' => $request->boolean('archived')], RequestScope::ownerId($request));

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }

    public function summary(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, $this->employees->countsByType(RequestScope::ownerId($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(EmployeeRules::store());

        return ApiEnvelope::single($request, $this->employees->create($data), 201);
    }

    public function show(Request $request, string $employee): JsonResponse
    {
        return ApiEnvelope::single($request, $this->employees->show($employee, RequestScope::ownerId($request)));
    }

    public function update(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate(EmployeeRules::identity(true, $employee));

        return ApiEnvelope::single($request, $this->employees->update($employee, $data));
    }

    public function changeCode(Request $request, string $employee): JsonResponse
    {
        // A swap touches the holder, who may be outside an own-scope caller's reach, and the 409 names them: only `.all` renumbers.
        if (RequestScope::ownerId($request) !== null) {
            throw AuthorizationRefused::of('employee.edit_code');
        }
        $data = $request->validate([
            'code' => ['required', 'integer', 'min:0'],
            'swap' => ['sometimes', 'boolean'],
        ]);

        return ApiEnvelope::single($request, $this->employees->changeCode($employee, (int) $data['code'], $request->boolean('swap')));
    }

    public function destroy(string $employee): Response
    {
        $this->employees->delete($employee);

        return response()->noContent();
    }

    public function typeChange(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(EmployeeType::class)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiEnvelope::single($request, $this->employees->addTypeChange($employee, $data), 201);
    }

    public function payChange(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([...EmployeeRules::pay(), 'effective_from' => ['required', 'date_format:Y-m-d']]);

        return ApiEnvelope::single($request, $this->employees->addPayChange($employee, $data), 201);
    }

    public function assignManager(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        return ApiEnvelope::single($request, $this->employees->assignManager($employee, $data), 201);
    }

    public function assignSite(Request $request, string $employee): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'uuid', Rule::exists('sites', 'id')->where('kind', SiteKind::Work->value)->where('active', true)->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'returns_on' => ['nullable', 'date_format:Y-m-d', 'after:effective_from'],
        ]);

        return ApiEnvelope::single($request, $this->employees->assignSite($employee, $data), 201);
    }

    public function document(Request $request, string $employee, string $kind): JsonResponse
    {
        $data = $request->validate([
            'received_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string'],
        ]);

        return ApiEnvelope::single($request, $this->employees->upsertDocument($employee, DocumentKind::from($kind), $data));
    }
}

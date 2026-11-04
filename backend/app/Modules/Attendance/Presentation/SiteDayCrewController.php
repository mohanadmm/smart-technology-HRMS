<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Presentation;

use App\Modules\Attendance\Application\SiteDayCrew;
use App\Modules\Employees\Application\EmployeeRules;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Today's day-labor crew on one of the site manager's sites: find, add, remove, and a new day laborer with a proposed wage. */
final class SiteDayCrewController
{
    public function __construct(private readonly SiteDayCrew $crew) {}

    public function search(Request $request): JsonResponse
    {
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'search' => ['required', 'string', 'min:2', 'max:100']]);

        return ApiEnvelope::single($request, $this->crew->search($q['site_id'], $q['search'], $this->manager($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $q = $request->validate(['site_id' => ['required', 'uuid'], 'employee_id' => ['required', 'uuid'], 'action' => ['required', 'in:add,remove']]);
        $q['action'] === 'add'
            ? $this->crew->add($q['site_id'], $q['employee_id'], $this->manager($request))
            : $this->crew->remove($q['site_id'], $q['employee_id'], $this->manager($request));

        return ApiEnvelope::single($request, ['employee_id' => $q['employee_id'], 'action' => $q['action']]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{site_id: string, name_ar: string, phone?: ?string, daily_wage: string} $data */
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'name_ar' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', EmployeeRules::MOBILE],
            'daily_wage' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/D', 'not_regex:/^0+(\.0+)?$/D'],
        ]);

        return ApiEnvelope::single($request, $this->crew->propose($data['site_id'], $data, $this->manager($request)))->setStatusCode(201);
    }

    private function manager(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}

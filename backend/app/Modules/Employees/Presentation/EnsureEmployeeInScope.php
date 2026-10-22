<?php

declare(strict_types=1);

namespace App\Modules\Employees\Presentation;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Presentation\RequestScope;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `employee.scope` after `permission:`: an own-scope user reaches only employees in their reach on the route's date (else today); 404 so ids are not confirmed. */
final class EnsureEmployeeInScope
{
    public function __construct(private readonly EmployeeService $employees) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $ownerId = RequestScope::ownerId($request);
        if ($ownerId !== null && ! $this->employees->isManagedBy((string) $request->route('employee'), $ownerId, self::date($request))) {
            throw new ModelNotFoundException;
        }

        return $next($request);
    }

    /** The route only checks the date's shape; an impossible one (2026-02-30) reaches as today, and the controller refuses it. */
    private static function date(Request $request): ?string
    {
        $date = $request->route('date');
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }
}

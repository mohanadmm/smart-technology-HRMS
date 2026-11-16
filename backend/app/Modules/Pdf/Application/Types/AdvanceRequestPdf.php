<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application\Types;

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Employees\Domain\EffectiveDated;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use App\Support\Database\ConcurrencyConflict;
use App\Support\Localization\ArabicFormDate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class AdvanceRequestPdf implements PdfDocument
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function rules(): array
    {
        return [
            'job_title' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'max:300'],
            'repayment_method' => ['required', 'in:receipts,cash,salary_deduction'],
            'previous_advance' => ['nullable', 'boolean'],
            'finance_remarks' => ['nullable', 'string', 'max:300'],
            'paper_decision_date' => ['missing'],
        ];
    }

    public function normalise(array $fields): array
    {
        return [...$fields, 'previous_advance' => isset($fields['previous_advance']) ? (bool) $fields['previous_advance'] : null, 'finance_remarks' => $fields['finance_remarks'] ?? ''];
    }

    /**
     * A caller without pay gets the copy without the salary, which then is never read (not read and dropped). Reach is judged on the
     * request's date, so a site manager's PDF of a past site day still works after the worker moved.
     */
    public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array
    {
        $request = DB::table('attendance_requests')->where(['id' => $subjectId, 'type' => 'advance'])->whereNull('deleted_at')
            ->lockForUpdate()->first() ?? throw new ModelNotFoundException;
        $employeeId = (string) $request->employee_id;
        $date = (string) $request->date;
        if ($scope !== null && ! $this->employees->isManagedBy($employeeId, $scope, $date)) {
            throw new ModelNotFoundException;
        }
        $employee = $showsPay ? $this->employees->show($employeeId)
            : Employee::query()->findOrFail($employeeId, ['name_ar', 'name_en', 'code', 'department', 'gender', 'hire_date'])->toArray();

        return [$employeeId, [
            ...array_intersect_key($employee, array_flip(['name_ar', 'name_en', 'code', 'department', 'gender'])),
            ...$fields,
            'shows_pay' => $showsPay,
            'hire_date' => $employee['hire_date'],
            'amount' => (string) $request->amount,
            ...($showsPay ? ['salary' => EffectiveDated::at($employee['pay_changes'], $date)['salary'] ?? null] : []),
            'advance_request_id' => $subjectId, 'request_date' => $date,
        ]];
    }

    public function subjectColumn(): string
    {
        return 'snapshot->advance_request_id';
    }

    /** A snapshot older than the no-pay copy has no `shows_pay` and carries the salary. */
    public function view(array $snapshot): array
    {
        $showsPay = ($snapshot['shows_pay'] ?? true) !== false;

        return ['pdf.advance-request', [
            'plainPaper' => true,
            'title' => 'طلب سلفة',
            'requestDate' => ArabicFormDate::format($snapshot['request_date']),
            'hireDate' => $snapshot['hire_date'] === null ? null : ArabicFormDate::format($snapshot['hire_date']),
            'amount' => bcround($snapshot['amount'], 2),
            'showsPay' => $showsPay,
            'salary' => $showsPay && $snapshot['salary'] !== null ? bcround($snapshot['salary'], 2) : null,
        ]];
    }

    /** Never signed. */
    public function approval(stdClass $export, Authenticatable $actor, bool $lock): ?string
    {
        throw new ConcurrencyConflict;
    }

    /** Never signed. */
    public function signable(Builder $exports, Authenticatable $actor): Builder
    {
        return $exports->whereRaw('false');
    }
}

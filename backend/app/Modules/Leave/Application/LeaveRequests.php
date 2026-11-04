<?php

declare(strict_types=1);

namespace App\Modules\Leave\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Employees\Application\EmployeeReach;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Identity\Application\Rbac\PermissionHolders;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Domain\LeaveBalance;
use App\Modules\Leave\Domain\LeaveKind;
use App\Modules\Leave\Domain\LeaveRefusal;
use App\Modules\Leave\Domain\LeaveRefused;
use App\Modules\Leave\Domain\LeaveStatus;
use App\Modules\Leave\Infrastructure\Eloquent\LeaveRequest;
use App\Modules\Notifications\Application\Notifier;
use App\Support\Http\Paginates;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** The leave request workflow (leave spec, Workflow). Preview and submit run the same checks, so the dialog shows a refusal before submit. */
final class LeaveRequests
{
    use Paginates;

    /** Calendar days from from_date to to_date inclusive (plan, Global Constraints: a code limit, not a setting). */
    public const MAX_RANGE_DAYS = 60;

    private const ROW = ['id', 'employee_id', 'kind', 'from_date', 'to_date', 'days', 'status', 'note', 'requested_by', 'created_at', 'version'];

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly LeaveDays $days,
        private readonly LeaveBalances $balances,
        private readonly Notifier $notifier,
        private readonly PermissionHolders $holders,
    ) {}

    /**
     * The counted dates and what they do to the year's balance. $ownerId (own scope) must reach the employee on every counted date.
     *
     * @return array{dates: list<string>, days: int, balance: ?array<string, ?int>, keep: int, unpaid: int, remaining_after: ?int}
     */
    public function preview(string $employeeId, LeaveKind $kind, string $from, string $to, ?string $ownerId): array
    {
        $dates = $this->check($employeeId, $from, $to, $ownerId);
        $balance = $this->balances->forEmployee($employeeId, (int) substr($from, 0, 4))['balance'];
        $split = LeaveBalance::split($kind, count($dates), $balance);
        $remaining = $balance['remaining'] ?? null;
        $deducted = in_array($kind, [LeaveKind::Annual, LeaveKind::Casual], true) ? $split['keep'] : 0;

        return [
            'dates' => $dates, 'days' => count($dates), 'balance' => $balance, ...$split,
            'remaining_after' => $remaining === null ? null : $remaining - $deducted,
        ];
    }

    /**
     * Files a pending request. A manager's (own-scope) request notifies each holder of leave.decide.all at once; HR's notifies nobody.
     *
     * @param  array{employee_id: string, kind: string, from_date: string, to_date: string, note?: ?string}  $input  validated
     * @return array<string, mixed> the list row
     */
    public function submit(array $input, string $by, ?string $ownerId): array
    {
        $request = DB::transaction(function () use ($input, $by, $ownerId): LeaveRequest {
            // Serializes one employee's submissions, so two racing requests cannot both pass the overlap check.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['leave_request:'.$input['employee_id']]);
            $dates = $this->check($input['employee_id'], $input['from_date'], $input['to_date'], $ownerId);
            $request = LeaveRequest::create([
                'employee_id' => $input['employee_id'], 'kind' => $input['kind'], 'from_date' => $input['from_date'], 'to_date' => $input['to_date'],
                'days' => count($dates), 'note' => $input['note'] ?? null, 'status' => LeaveStatus::Pending->value, 'requested_by' => $by, 'version' => 1,
            ]);
            if ($ownerId !== null) {
                $data = ['employee_id' => $input['employee_id'], 'request_id' => $request->id, 'kind' => $input['kind'], 'from_date' => $input['from_date'], 'to_date' => $input['to_date'], 'days' => count($dates)];
                foreach ($this->holders->userIds('leave.decide.all') as $id) {
                    $this->notifier->notifyOnce($id, 'leave_requested', now('Africa/Cairo')->toDateString(), $data, (string) $request->id);
                }
            }

            return $request;
        });

        return $this->named([$this->row($request)])[0];
    }

    /**
     * Pending and escalated first, then newest. $managerId narrows to the employees in their reach today.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}}
     */
    public function list(?string $status, ?string $employeeId, ?int $year, int $perPage, int $page, ?string $managerId): array
    {
        $query = LeaveRequest::query()
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            // A request never crosses Dec 31, so its from_date names its year.
            ->when($year !== null, fn (Builder $q) => $q->whereBetween('from_date', ["{$year}-01-01", "{$year}-12-31"]))
            ->when($managerId !== null, fn (Builder $q) => $q->whereIn('employee_id', EmployeeReach::ids((string) $managerId, now('Africa/Cairo')->toDateString())))
            ->orderByRaw("status in ('pending', 'escalated') desc")->orderByDesc('created_at')->orderByDesc('id');
        $result = $this->page($query, $perPage, $page);

        return [...$result, 'data' => $this->named($result['data'])];
    }

    /**
     * The request's counted dates, or a refusal. Input first, then reach, so a manager learns nothing about an employee out of reach.
     *
     * @return non-empty-list<string>
     */
    private function check(string $employeeId, string $from, string $to, ?string $ownerId): array
    {
        if (substr($from, 0, 4) !== substr($to, 0, 4)) {
            throw new LeaveRefused(LeaveRefusal::CrossesYear);
        }
        if ((int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1 > self::MAX_RANGE_DAYS) {
            throw new LeaveRefused(LeaveRefusal::TooLong, ['max' => (string) self::MAX_RANGE_DAYS]);
        }
        $employee = $this->roster->find($employeeId) ?? throw new ModelNotFoundException;
        $dates = $this->days->dates($employee, $from, $to);
        if ($ownerId !== null) {
            // With no counted date, reach on from_date decides, so the refusals below never describe an employee out of reach.
            // ponytail: one reach query per counted date (at most 60); a dated reach range if this shows up in a profile.
            foreach ($dates === [] ? [$from] : $dates as $date) {
                if (! EmployeeReach::reaches($employeeId, $ownerId, $date)) {
                    throw AttendanceRefused::because(AttendanceRefusal::SiteNotInReach);
                }
            }
        }
        if (EmployeeRoster::typeInYear($employee, (int) substr($from, 0, 4)) === EmployeeType::DayLaborer->value) {
            throw new LeaveRefused(LeaveRefusal::DayLaborer);
        }
        if ($dates === []) {
            throw new LeaveRefused(LeaveRefusal::NoDays);
        }
        $overlaps = LeaveRequest::where('employee_id', $employeeId)->whereIn('status', array_map(fn (LeaveStatus $s) => $s->value, LeaveStatus::LIVE))
            ->where('from_date', '<=', $to)->where('to_date', '>=', $from)->exists();
        if ($overlaps) {
            throw new LeaveRefused(LeaveRefusal::Overlap);
        }

        return $dates;
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        return array_intersect_key($m->toArray(), array_flip(self::ROW));
    }

    /**
     * @param  list<array<string, mixed>>  $rows  row() results
     * @return list<array<string, mixed>> in the API row shape, employee and requester named
     */
    private function named(array $rows): array
    {
        $employees = $this->roster->names(array_values(array_unique(array_column($rows, 'employee_id'))));
        $users = User::withTrashed()->whereIn('id', array_unique(array_column($rows, 'requested_by')))->pluck('name', 'id');

        return array_map(fn (array $r) => [
            'id' => $r['id'], 'employee' => $employees[$r['employee_id']] ?? null, 'kind' => $r['kind'], 'from_date' => $r['from_date'], 'to_date' => $r['to_date'],
            'days' => $r['days'], 'status' => $r['status'], 'note' => $r['note'] ?? null,
            'requested_by' => ['id' => $r['requested_by'], 'name' => $users[$r['requested_by']] ?? null], 'created_at' => $r['created_at'], 'version' => $r['version'],
        ], $rows);
    }
}

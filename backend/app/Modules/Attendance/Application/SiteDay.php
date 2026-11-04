<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\AttendanceRefusal;
use App\Modules\Attendance\Domain\AttendanceRefused;
use App\Modules\Attendance\Domain\DayRules;
use App\Modules\Attendance\Domain\Employment;
use App\Modules\Attendance\Domain\ManagerWindow;
use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Settings\Application\BusinessRules;
use App\Modules\Sites\Application\SiteService;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The site manager's day at one of their sites (design Part 2): the site's workers on that date, then the manager's engineers,
 * who float between the manager's sites and so show the site they were stored at. No pay figures: transport, outside_* and
 * advance_amount stay out of the read, and the save reads only a site, two times and a note per row (Review Focus 5).
 */
final class SiteDay
{
    /** The save body; nothing else in a row is read. */
    public const FORMAT = [
        'site_id' => ['required', 'uuid'],
        'date' => ['required', 'date_format:Y-m-d'],
        'rows' => ['required', 'list', 'min:1', 'max:'.AttendanceDays::BULK_MAX_ROWS],
        'rows.*.employee_id' => ['required', 'uuid', 'distinct'],
        'rows.*.clear' => ['sometimes', 'accepted'],
        'rows.*.version' => ['nullable', 'integer', 'min:1', 'required_with:rows.*.clear'],
        'rows.*.site_id' => ['present_unless:rows.*.clear,true,1,yes,on', 'nullable', 'uuid'],
        'rows.*.in_time' => ['nullable', 'date_format:H:i'],
        'rows.*.out_time' => ['nullable', 'date_format:H:i'],
        'rows.*.note' => ['nullable', 'string', 'max:500'],
    ];

    /** The leave types a site manager may store (P11); annual, casual and unpaid go through a leave request. */
    public const MANAGER_LEAVE = [LeaveCode::Absent, LeaveCode::Sick, LeaveCode::Rest, LeaveCode::Other];

    private const DAY = ['state', 'version', 'site_id', 'leave_code', 'in_time', 'out_time', 'note'];

    public function __construct(
        private readonly EmployeeRoster $roster,
        private readonly EffectiveDays $days,
        private readonly SiteService $sites,
        private readonly AttendanceDays $attendance,
        private readonly BusinessRules $rules,
    ) {}

    /**
     * Each row carries the person's scheduled shift (their pay-row override, else the field shift setting) for the bulk fills,
     * and whether the site manager may change the day now: `why_not` is locked, window, hr_day (HR recorded it, or it came from an
     * approved request: only HR changes it, owner 2026-11-04) or open_day (an earlier day has an arrival and no departure; `open_date`
     * names it; `open_editable` says whether the site manager can still complete it). `previous` is the day «زي امبارح» copies (P10).
     * `leave_sites` are the leave types the site manager may store (P11), by code.
     *
     * @return array{site: array{id: string, name_ar: string, name_en: ?string}, date: string, today: string, leave_sites: array<string, string>, rows: list<array<string, mixed>>}
     */
    public function read(string $siteId, string $date, string $managerId): array
    {
        [$site, $listed, $days] = $this->load($siteId, $date, $managerId);
        $settings = $this->rules->all();
        $window = $this->windowAllows($date, $settings);
        $locked = [];
        $ids = array_map(fn (array $l) => (string) $l[0]['id'], $listed);
        $open = $this->openDays($ids, $date);
        $previous = $this->previousDays($ids, $date);

        return [
            'site' => ['id' => (string) $site->getKey(), 'name_ar' => (string) $site->name_ar, 'name_en' => $site->name_en],
            'date' => $date,
            'today' => EffectiveDays::today(),
            'leave_sites' => $this->managerLeaveSites(),
            'rows' => array_map(function (array $l) use ($days, $date, $settings, $window, $open, $previous, &$locked): array {
                [$e, $section] = $l;
                $day = $days[$e['id']][$date];
                $type = (string) $day['type'];
                $locked[$type] ??= PeriodGuard::isLocked(Period::containing(EmployeeType::from($type), $date));
                $whyNot = match (true) {
                    $locked[$type] => 'locked',
                    ! $window => 'window',
                    self::hrDay($day) => 'hr_day',
                    isset($open[$e['id']]) => 'open_day',
                    default => null,
                };

                return [
                    'employee' => ['id' => $e['id'], 'code' => $e['code'], 'name_ar' => $e['name_ar'], 'name_en' => $e['name_en'], 'type' => $type],
                    'section' => $section,
                    'shift' => EffectiveDays::fieldShift($e, $date, $settings),
                    'day' => array_intersect_key($day, array_flip(self::DAY)),
                    'can_edit' => $whyNot === null,
                    'why_not' => $whyNot,
                    'open_date' => $open[$e['id']] ?? null,
                    // whether the site manager can still complete it, or only HR (after the yesterday window)
                    'open_editable' => isset($open[$e['id']]) && $this->windowAllows($open[$e['id']], $settings),
                    'previous' => $previous[$e['id']] ?? null,
                ];
            }, $listed),
        ];
    }

    /**
     * The site manager's save, all or nothing (owner, 2026-11-04). A row outside the day's list is 404. Then, per row:
     * - a day HR recorded (or an approved request wrote) is HR's to change or clear: `hr_day`;
     * - an earlier day of the same person with an arrival and no departure must be completed first: `open_earlier_day`
     *   (after the yesterday window only HR can complete it);
     * - a present day needs its arrival (`rows.N.in_time`); a clear removes only the site manager's own day here or on one of their leave types;
     * - the leave types are absent, sick, rest and other (P11).
     * AttendanceDays::bulk writes with source `manager` and the manager's reach. Late minutes are raw (calculations §2b) and are
     * recomputed only when the arrival changes; overtime is set to 0 (held until HR approves it) only when a time changes, so an
     * unchanged time keeps what is stored.
     *
     * @param  array{site_id: string, date: string, rows: list<array<string, mixed>>}  $body  validated against FORMAT
     * @return array<string, mixed> the day after the save, as read() returns it
     */
    public function save(array $body, string $managerId): array
    {
        ['site_id' => $siteId, 'date' => $date, 'rows' => $rows] = $body;
        [, $listed, $days] = $this->load($siteId, $date, $managerId);
        $byId = [];
        foreach ($listed as [$e]) {
            $byId[$e['id']] = $e;
        }
        $settings = $this->rules->all();
        if (! $this->windowAllows($date, $settings)) {
            throw AttendanceRefused::because(AttendanceRefusal::OutsideManagerWindow, ['until' => $settings['manager_yesterday_until']], [$this->detail(0, AttendanceRefusal::OutsideManagerWindow, $settings)]);
        }
        $allowedLeave = array_values($this->managerLeaveSites());
        $open = $this->openDays(array_map(fn (array $r) => (string) $r['employee_id'], $rows), $date);

        $errors = [];
        $out = [];
        foreach ($rows as $i => $row) {
            $e = $byId[(string) $row['employee_id']] ?? throw new ModelNotFoundException;
            $stored = $days[$e['id']][$date];
            $clear = isset($row['clear']);
            $target = $clear ? null : ($row['site_id'] === null ? null : (string) $row['site_id']);
            if (self::hrDay($stored)) {
                throw AttendanceRefused::because(AttendanceRefusal::HrDay, [], [$this->detail($i, AttendanceRefusal::HrDay)]);
            }
            if (isset($open[$e['id']])) {
                $params = ['date' => $open[$e['id']]];
                throw AttendanceRefused::because(AttendanceRefusal::OpenEarlierDay, $params, [$this->detail($i, AttendanceRefusal::OpenEarlierDay, $settings, $params)]);
            }
            if ($clear) {
                if ($stored['state'] !== 'stored' || ($stored['site_id'] !== $siteId && ! in_array($stored['site_id'], $allowedLeave, true))) {
                    $errors["rows.{$i}.site_id"] = [__('attendance.rule.clear_not_allowed_for_manager')];

                    continue;
                }
                $out[] = ['employee_id' => $e['id'], 'version' => $row['version'], 'clear' => true];

                continue;
            }
            if ($target !== $siteId && ! in_array($target, $allowedLeave, true)) {
                $errors["rows.{$i}.site_id"] = [__('attendance.rule.site_not_allowed_for_manager')];

                continue;
            }
            if ($target === $siteId && ($row['in_time'] ?? null) === null) {
                $errors["rows.{$i}.in_time"] = [__('attendance.rule.arrival_required')];

                continue;
            }
            $out[] = ['employee_id' => $e['id'], 'version' => $row['version'] ?? null, 'site_id' => $target, ...$this->values($e, $date, $row, $stored, $target === $siteId, $settings)];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->attendance->bulk($date, $out, $managerId, 'manager');

        return $this->read($siteId, $date, $managerId);
    }

    /** @return array<string, string> leave code => the active leave site, for the leave types a site manager may store */
    private function managerLeaveSites(): array
    {
        $sites = Site::where(['kind' => SiteKind::Leave->value, 'active' => true])
            ->whereIn('leave_code', array_map(fn (LeaveCode $c) => $c->value, self::MANAGER_LEAVE))->get(['id', 'leave_code']);

        return $sites->mapWithKeys(fn (Site $s) => [(string) $s->leave_code => (string) $s->id])->all();
    }

    /**
     * A day HR recorded, imported or materialized, or that an approved leave request wrote (sick included): only HR changes it
     * (owner, 2026-11-04). The site manager's own days have source `manager`.
     *
     * @param  array<string, mixed>  $day
     */
    private static function hrDay(array $day): bool
    {
        return $day['state'] === 'stored' && $day['source'] !== 'manager';
    }

    /**
     * «زي امبارح» (P10): per employee, the latest stored day in the 7 before $date, skipping Friday, Saturday and official holidays.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, array{date: string, site_id: ?string, leave_code: ?string, in_time: ?string, out_time: ?string}>
     */
    private function previousDays(array $employeeIds, string $date): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $from = (new \DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d');
        $off = [LeaveCode::Friday->value, LeaveCode::Saturday->value, LeaveCode::OfficialHoliday->value];
        $rows = DB::table('attendance_days as d')->leftJoin('sites as s', 's.id', '=', 'd.site_id')
            ->whereNull('d.deleted_at')->whereIn('d.employee_id', $employeeIds)->where('d.date', '>=', $from)->where('d.date', '<', $date)
            ->where(fn ($q) => $q->whereNull('s.leave_code')->orWhereNotIn('s.leave_code', $off))
            ->selectRaw('distinct on (d.employee_id) d.employee_id, d.date, d.site_id, s.leave_code, d.in_time, d.out_time')
            ->orderBy('d.employee_id')->orderByDesc('d.date')->get();
        $hhmm = fn (?string $t) => $t === null ? null : substr($t, 0, 5);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->employee_id] = [
                'date' => substr((string) $r->date, 0, 10), 'site_id' => $r->site_id === null ? null : (string) $r->site_id,
                'leave_code' => $r->leave_code, 'in_time' => $hhmm($r->in_time), 'out_time' => $hhmm($r->out_time),
            ];
        }

        return $out;
    }

    /**
     * Per employee, the latest day before $date with an arrival and no departure (any source): it is completed before a later day is saved.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, string> employee id → that date
     */
    private function openDays(array $employeeIds, string $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return AttendanceDay::whereIn('employee_id', $employeeIds)->where('date', '<', $date)
            ->whereNotNull('in_time')->whereNull('out_time')
            ->selectRaw('employee_id, max(date) as date')->groupBy('employee_id')
            ->pluck('date', 'employee_id')->map(fn ($d) => (string) $d)->all();
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $stored  the day as read
     * @param  array<string, string>  $settings
     * @return array<string, mixed> what the row stores besides the site. A note is written only when the row sends one, so HR's note
     *                              stays (P28 keeps an unsent field); a leave day sends no times.
     */
    private function values(array $e, string $date, array $row, array $stored, bool $work, array $settings): array
    {
        $note = array_key_exists('note', $row) ? ['note' => $row['note']] : [];
        if (! $work) {
            return $note;
        }
        $in = $row['in_time'] ?? null;
        $outTime = $row['out_time'] ?? null;
        $values = ['in_time' => $in, 'out_time' => $outTime, ...$note];
        $type = Employment::typeOn($e, $date);
        if ($type === EmployeeType::Engineer || $type === EmployeeType::DayLaborer) {
            $was = $stored['state'] === 'stored' && $stored['site_id'] !== null && $stored['leave_code'] === null;
            if (! $was || $stored['in_time'] !== $in) {
                $start = EffectiveDays::fieldShift($e, $date, $settings)['start'];
                $values['late_minutes'] = $in === null ? null : max(0, DayRules::minutes($in) - DayRules::minutes($start));
            }
            if (! $was || $stored['in_time'] !== $in || $stored['out_time'] !== $outTime) {
                $values['ot_minutes'] = $in !== null && $outTime !== null ? 0 : null;
            }
        }

        return $values;
    }

    /** @param  array<string, string>  $settings */
    private function windowAllows(string $date, array $settings): bool
    {
        return ManagerWindow::allows($date, EffectiveDays::today(), now('Africa/Cairo')->format('H:i'), $settings['manager_yesterday_until'], false);
    }

    /**
     * @param  array<string, string>  $settings
     * @param  array<string, string>  $params
     * @return array{field: string, code: string, message: string}
     */
    private function detail(int $i, AttendanceRefusal $reason, array $settings = [], array $params = []): array
    {
        return ['field' => "rows.{$i}", 'code' => $reason->value, 'message' => __('attendance.refusal.'.$reason->value, ['until' => $settings['manager_yesterday_until'] ?? '', ...$params])];
    }

    /** @return array{0: Site, 1: list<array{0: array<string, mixed>, 1: string}>, 2: array<string, array<string, array<string, mixed>>>} */
    private function load(string $siteId, string $date, string $managerId): array
    {
        if (! in_array($siteId, $this->sites->idsManagedBy($managerId), true)) {
            throw new ModelNotFoundException;
        }
        if ($date > EffectiveDays::today()) {
            throw AttendanceRefused::because(AttendanceRefusal::FutureDate);
        }
        $site = Site::findOrFail($siteId);

        $listed = [
            ...array_map(fn (array $e) => [$e, 'workers'], $this->roster->onSite($siteId, $date)),
            ...array_map(fn (array $e) => [$e, 'engineers'], $this->roster->engineersOf($managerId, $date)),
        ];
        // Both lists hold only those employed on $date, so each has a type on it; one period read per type present.
        $byType = [];
        foreach ($listed as [$e]) {
            $byType[Employment::typeOn($e, $date)?->value][] = $e;
        }
        $days = [];
        foreach ($byType as $type => $employees) {
            $days += $this->days->forPeriod(Period::containing(EmployeeType::from((string) $type), $date), $employees);
        }

        return [$site, $listed, $days];
    }
}

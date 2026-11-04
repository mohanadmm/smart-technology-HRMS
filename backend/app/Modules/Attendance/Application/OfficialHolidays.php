<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use App\Modules\Attendance\Domain\Period;
use App\Modules\Attendance\Infrastructure\Eloquent\AttendanceDay;
use App\Modules\Attendance\Infrastructure\Eloquent\OfficialHoliday;
use App\Modules\Attendance\Infrastructure\PeriodGuard;
use App\Modules\Employees\Domain\EmployeeType;
use App\Modules\Sites\Domain\LeaveCode;
use App\Modules\Sites\Domain\SiteKind;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use App\Support\Http\Paginates;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The official-holiday list. A holiday derives a day for every group, so a write needs each group's period on that date open. Deleting or
 * moving one clears the holiday days a lock stored on its old date (a reopened period), so they are derived again or missing.
 */
final class OfficialHolidays
{
    use Paginates;

    /** @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function list(?int $year, int $perPage): array
    {
        $query = OfficialHoliday::query()->orderBy('date');
        if ($year !== null) {
            $query->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"]);
        }

        return $this->page($query, $perPage);
    }

    /**
     * @param  array{date: string, name: string}  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->write(function () use ($data): OfficialHoliday {
            $this->assertOpen($data['date']);
            $this->assertFree($data['date']);

            return OfficialHoliday::create($data);
        });
    }

    /**
     * @param  array{date?: string, name?: string}  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->write(function () use ($id, $data): OfficialHoliday {
            $holiday = OfficialHoliday::lockForUpdate()->findOrFail($id);
            $this->assertOpen((string) $holiday->date);
            if (isset($data['date']) && $data['date'] !== $holiday->date) {
                $this->assertOpen($data['date']);
                $this->assertFree($data['date']);
            }
            $old = (string) $holiday->date;
            $holiday->update($data);
            if ($holiday->date !== $old) {
                $this->clearStored($old);
            }

            return $holiday;
        });
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $holiday = OfficialHoliday::lockForUpdate()->findOrFail($id);
            $this->assertOpen((string) $holiday->date);
            $holiday->delete();
            $this->clearStored((string) $holiday->date);
        });
    }

    /** Soft-deletes the lock's stored holiday days on $date, as a clear does (version+1, audited); HR's, imported and leave days stay. */
    private function clearStored(string $date): void
    {
        $types = Site::withTrashed()->where(['kind' => SiteKind::Leave->value, 'leave_code' => LeaveCode::OfficialHoliday->value])->select('id');
        foreach (AttendanceDay::where(['date' => $date, 'source' => 'derived'])->whereIn('site_id', $types)->lockForUpdate()->get() as $row) {
            $row->version = $row->version + 1;
            $row->save();
            $row->delete();
        }
    }

    /**
     * @param  Closure(): OfficialHoliday  $write
     * @return array<string, mixed>
     */
    private function write(Closure $write): array
    {
        try {
            return $this->row(DB::transaction($write));
        } catch (UniqueConstraintViolationException) {
            // A racing write of the same date won.
            throw ValidationException::withMessages(['date' => __('errors.duplicate_date')]);
        }
    }

    private function assertOpen(string $date): void
    {
        foreach (EmployeeType::cases() as $type) {
            PeriodGuard::assertOpen(Period::containing($type, $date));
        }
    }

    private function assertFree(string $date): void
    {
        if (OfficialHoliday::where('date', $date)->exists()) {
            throw ValidationException::withMessages(['date' => __('errors.duplicate_date')]);
        }
    }
}

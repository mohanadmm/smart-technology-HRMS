<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use App\Support\Http\Paginates;
use Illuminate\Database\Eloquent\Model;

/**
 * The signed-in user's own notifications; another user's id is a 404, never a 403. A row whose data names an employee carries
 * that employee's code and names, read now: the stored data holds ids only (§9).
 */
final class NotificationInbox
{
    use Paginates;

    public function __construct(private readonly EmployeeRoster $roster) {}

    /** @return array{data: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}} */
    public function list(string $userId, int $perPage): array
    {
        $page = $this->page(Notification::where('user_id', $userId)->orderByDesc('date')->orderByDesc('id'), $perPage);

        return [...$page, 'data' => $this->named($page['data'])];
    }

    /** @return array<string, mixed> the row; a second call keeps the first read_at */
    public function markRead(string $userId, string $id): array
    {
        $n = Notification::where('user_id', $userId)->findOrFail($id);
        if ($n->read_at === null) {
            $n->read_at = now();
            $n->save();
        }

        return $this->named([$this->row($n)])[0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> each row with `employee` ({id, code, name_ar, name_en}, or null)
     */
    private function named(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (array $r) => $r['data']['employee_id'] ?? null, $rows))));
        $names = $this->roster->names($ids);

        return array_map(fn (array $r) => [...$r, 'employee' => $names[$r['data']['employee_id'] ?? ''] ?? null], $rows);
    }

    /** @return array<string, mixed> */
    private function row(Model $m): array
    {
        return array_intersect_key($m->toArray(), array_flip(['id', 'type', 'date', 'ref', 'data', 'read_at']));
    }
}

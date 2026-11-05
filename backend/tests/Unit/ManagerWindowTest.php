<?php

namespace Tests\Unit;

use App\Modules\Attendance\Domain\ManagerWindow;
use PHPUnit\Framework\TestCase;

class ManagerWindowTest extends TestCase
{
    public function test_yesterday_closes_at_noon_cairo(): void
    {
        $this->assertTrue(ManagerWindow::allows('2026-10-22', '2026-10-22', '23:59', '12:00'));
        $this->assertTrue(ManagerWindow::allows('2026-10-21', '2026-10-22', '11:59', '12:00'));
        $this->assertFalse(ManagerWindow::allows('2026-10-21', '2026-10-22', '12:00', '12:00'));
        $this->assertFalse(ManagerWindow::allows('2026-10-20', '2026-10-22', '09:00', '12:00'));
        $this->assertFalse(ManagerWindow::allows('2026-10-23', '2026-10-22', '09:00', '12:00'));

        $this->assertFalse(ManagerWindow::isPastDayEdit('2026-10-21', '2026-10-22', '11:00', '12:00'));
        $this->assertTrue(ManagerWindow::isPastDayEdit('2026-10-21', '2026-10-22', '13:00', '12:00'));
        $this->assertFalse(ManagerWindow::isPastDayEdit('2026-10-22', '2026-10-22', '13:00', '12:00'));
    }

    public function test_yesterday_crosses_a_month_end(): void
    {
        $this->assertTrue(ManagerWindow::allows('2026-10-31', '2026-11-01', '08:00', '12:00'));
    }
}

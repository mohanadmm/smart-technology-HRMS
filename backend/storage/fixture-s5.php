<?php
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Application\LeaveBalances;
use App\Modules\Leave\Application\LeaveRequests;
$emp = fn ($code) => (string) DB::table('employees')->where('code', $code)->value('id');
$user = fn ($e) => User::where('email', $e)->firstOrFail();
$mgr = (string) $user('manager@smart.test')->id; $hr = (string) $user('hr@smart.test')->id;
app(LeaveBalances::class)->adjust($emp('3'), 2026, ['entitlement' => null, 'opening_used' => 8, 'opening_casual_used' => 0, 'reason' => 'Fixture: days used before the system'], null);
$r = app(LeaveRequests::class);
$a = $r->submit(['employee_id' => $emp('3'), 'kind' => 'annual', 'from_date' => '2026-11-19', 'to_date' => '2026-11-24', 'note' => 'Family visit'], $mgr, $mgr);
$b = $r->submit(['employee_id' => $emp('6'), 'kind' => 'casual', 'from_date' => '2026-11-25', 'to_date' => '2026-11-26'], $mgr, $mgr);
$c = $r->submit(['employee_id' => $emp('3'), 'kind' => 'annual', 'from_date' => '2026-10-04', 'to_date' => '2026-10-05'], $hr, null);
$d = $r->submit(['employee_id' => $emp('4'), 'kind' => 'sick', 'from_date' => '2026-11-10', 'to_date' => '2026-11-11'], $mgr, $mgr);
echo json_encode(['split' => $a['id'], 'escalate' => $b['id'], 'blocked' => $c['id'], 'sick' => $d['id']]), "\n";

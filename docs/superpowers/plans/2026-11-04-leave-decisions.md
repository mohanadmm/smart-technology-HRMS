# Leave decisions D2, D4, A3 and holiday re-lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the four leave and attendance decisions the owner accepted on 2026-11-04.

**Architecture:** D2 is a doc and test check only. D4 replaces the `Employee::deleted` auto-cancel with a `deleting` refusal owned by Leave. A3 adds a `replaced` list to the approval preview and `replace_dates` on approve, rechecked under the row lock. A holiday delete or move soft-deletes that date's stored `derived` holiday days (the period is open by rule), the re-lock stores the current holidays as today, and its reply lists the changes.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 17, PHPUnit; React 19 + Vite + TS, vitest, react-i18next.

**Spec:** `docs/superpowers/specs/2026-11-03-leave-design.md` (Workflow), `docs/calculations.md` §2a and §7, and the research note with the owner's decisions (`side-session-notes/local_75e7320c…/leave-open-questions.md`, summarized in each task below).

## Global Constraints
- Every UI string and API message has an Arabic and an English key.
- A refusal that lists dates uses the existing `LeaveRefused($reason, $params, $dates)` → `error.dates` envelope.
- Nothing deletes a day row that holds an advance or a note. Refusal copy for A3 says "change the day's type", never "clear".
- The Employees module imports nothing from Leave (deptrac). The listener lives in `AppServiceProvider`.
- Weekly-off rows are never refreshed (the setting has no effective date). Rows with source `entered`, `imported` or `leave` are never touched by the lock.
- Every changed row is audited by the `Audited` model events (before and after).
- Out of bounds: `frontend/src/pages/site-day.tsx`, `frontend/src/lib/site-day.ts`, `frontend/src/pages/team/*`, role wording in `CLAUDE.md` and `CONTEXT.md`.
- Leave entitlements stay 10/21 + 6 casual.

## Review Focus
1. A request date holding a stale stored `derived` row: overwriting it loses nothing, so it is not listed in `replaced`. Test in Task 2.
2. A day changes to another leave type after HR read the preview: approve refuses (listed dates) or the write 409s (row-lock recheck). Tests in Task 2.
3. A removed holiday that falls on the group's weekly off becomes the weekly-off row, not missing. Test in Task 3.
4. A first lock (no earlier lock) reports no holiday changes. Test in Task 3.
5. An employee with only decided requests can still be deleted, and an approved request of a deleted employee still cancels. Tests in Task 1.

---

### Task 1: D4 refuse the delete; D2 recorded

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`, `backend/app/Modules/Leave/Application/LeaveRequests.php` (remove `cancelOpenOf`), `backend/app/Modules/Leave/Domain/LeaveRefusal.php`, `backend/lang/{ar,en}/leave.php`
- Test: `backend/tests/Feature/LeaveCancelTest.php`

**Interfaces:**
- Produces: `LeaveRequests::assertNoOpenRequests(string $employeeId): void` (takes `LeaveRequestLock::forEmployee`, then throws `LeaveRefused(LeaveRefusal::EmployeeHasOpenRequests, ['count' => n])` when pending or escalated requests exist). `LeaveRefusal::EmployeeHasOpenRequests = 'leave_employee_has_open_requests'`, status 409.
- Copy: en `This employee has open leave requests: :count. Cancel or decide them first.` ar `لدى هذا الموظف طلبات إجازة مفتوحة: :count. ألغِها أو قرّر فيها أولًا.`

- [ ] **Step 1: Failing tests.** Replace `test_deleting_an_employee_cancels_their_undecided_requests_only` with:
  - `test_deleting_an_employee_with_an_undecided_request_is_refused`: one pending and one escalated request; `DELETE /employees/{e}` → 409, `error.code` `leave_employee_has_open_requests`, message contains `2`; employee not soft-deleted; both requests keep status and version.
  - `test_an_employee_with_only_decided_requests_can_be_deleted`: an approved and a refused request; delete → 2xx; statuses unchanged.
  - D2: keep `test_a_leave_day_holding_a_note_or_an_advance_blocks_the_cancel_and_nothing_changes` (already asserts 409 `leave_cancel_blocked` with the dates).
- [ ] **Step 2:** `docker compose exec app php artisan test --filter=LeaveCancelTest` → the two new tests FAIL.
- [ ] **Step 3:** `Employee::deleting(fn (Employee $e) => app(LeaveRequests::class)->assertNoOpenRequests((string) $e->getKey()))`; delete `cancelOpenOf` and the now unused `Auth` import if unused. `Audited::delete` wraps the delete, so the advisory lock is inside a transaction.
- [ ] **Step 4:** Tests PASS; `deptrac` clean.
- [ ] **Step 5:** Commit `Leave: refuse deleting an employee with open requests (D4)`.

### Task 2: A3 approval over another leave type

**Files:**
- Modify: `backend/app/Modules/Attendance/Application/AttendanceDays.php` (`writeLeaveDay`, `write`, new `leaveCodesOn`), `backend/app/Modules/Leave/Application/LeaveRequests.php` (`approval`, `approve`), `backend/app/Modules/Leave/Presentation/LeaveRequestController.php` (`decide`), `LeaveRefusal.php`, `lang/{ar,en}/leave.php`, `frontend/src/pages/leave-dialogs.tsx`, `frontend/src/locales/{ar,en}.json`
- Test: `backend/tests/Feature/LeaveRequestsTest.php`, `frontend/src/pages/leave-dialogs.test.tsx`

**Interfaces:**
- `AttendanceDays::leaveCodesOn(string $employeeId, array $dates): array<string, string>`, read only: date → leave code of each live row on a leave-kind site (sites `withTrashed`), skipping rows with source `derived` (a derived row holds no note or advance and is stale on a counted date).
- Approval preview (`approval` in show) gains `replaced: list<array{date: string, leave_code: string}>`: each counted date whose stored code differs from its **target** code (index < `keep` → the request's kind, else `unpaid`). Compare **codes**, not site ids.
- `LeaveRequests::approve(string $id, int $version, Authenticatable $by, array $replaceDates = [])`. After the `blocked` check: when any date of `replaced` is not in `$replaceDates` → `LeaveRefused(LeaveRefusal::DaysReplaced, ['count' => n], <those dates>)`. HR's tick replaces only the dates HR saw. `LeaveRefusal::DaysReplaced = 'leave_days_replaced'`, status 409.
- `AttendanceDays::writeLeaveDay(string $employeeId, string $date, string $leaveTypeId, bool $replace = false)`. In `write()`'s `$leaveDay` branch, under the row lock: a live row on a leave type whose code differs from the target's, with source not `derived`, and `! $replace` → `ConcurrencyConflict` (the day changed between the preview and the write). `approve` passes `$replace = in_array($date, $replaceDates)`.
- `write()` keeps the live source when the live row's leave **code** equals the target's (today it compares site ids, so HR's day on a since-deactivated type of the same code becomes `leave` and a later cancel deletes it).
- Controller: `'replace_dates' => $action === 'approve' ? ['sometimes', 'array', 'max:'.LeaveRequests::MAX_RANGE_DAYS] : ['prohibited']`, `'replace_dates.*' => ['date_format:Y-m-d']`.
- Copy: en `Requested days that already hold another leave type: :count. Tick "Replace these days", or change the day's type in attendance first.` ar `عدد الأيام المسجّلة بنوع إجازة آخر: :count. اختر «استبدال هذه الأيام»، أو غيّر نوع اليوم في الحضور أولًا.`
- Frontend keys under `leave.decide`: `replaced` (en `These days already hold another leave type. Approving replaces them; their note and advance stay.` ar `هذه الأيام مسجّل عليها نوع إجازة آخر. الموافقة تستبدلها، وتبقى الملاحظة والسلفة.`), `replace` (en `Replace these days`, ar `استبدال هذه الأيام`).

- [ ] **Step 1: Failing backend tests** (HR approves; seed days with the existing helpers):
  - `test_approval_lists_days_of_another_leave_type_and_refuses_them_by_default`: a sick day on one request date; `show` → `approval.replaced == [{date, leave_code: 'sick'}]`; approve → 409 `leave_days_replaced`, `error.dates == [date]`; request still pending; the day still sick with its source.
  - `test_replace_overwrites_the_dates_hr_saw_keeping_note_and_advance`: same with a note and an advance, approve with `replace_dates: [date]` → 200; the day is annual, source `leave`, note and advance kept.
  - `test_a_replaced_date_hr_did_not_see_refuses`: two other-type days; approve with `replace_dates` naming one → 409 listing the other only; nothing written.
  - `test_hrs_same_type_day_is_not_listed_and_keeps_its_source`: an annual day entered by HR on a date of an annual request → `replaced` empty; approve succeeds; source `entered`. Also on a deactivated annual type (same code, other site id): not listed, source stays `entered`.
  - `test_an_annual_day_on_an_overflow_date_is_listed_against_unpaid`: balance leaves the last date beyond it (target `unpaid`), HR's annual day there → listed as `annual`; approve without it → 409; nothing written.
  - `test_a_stored_derived_day_is_not_listed`: a live row with source `derived` (official holiday type) on a request date; `replaced` empty; approve succeeds.
  - `test_replace_dates_is_only_for_approve`: refuse/escalate with `replace_dates` → 422.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Frontend.** In `DecideBody`: type `approval.replaced: { date: string; leave_code: string }[]`; when non-empty show an `Alert tone="warning"` with `leave.decide.replaced` and each date with `t('attendance.leaveName.<code>')`; when `actions` include `approve`, a checkbox labelled `leave.decide.replace` (same markup as the lock dialog's confirm checkbox, `min-h-tap`). The tick resets whenever the listed dates change (key the state on them). Approve is disabled while the list is non-empty and unticked, and sends `replace_dates` = the listed dates when ticked. Vitest in `leave-dialogs.test.tsx`: the list and checkbox render from a mocked show with `replaced`; Approve is disabled until ticked; it posts `replace_dates`.
- [ ] **Step 5:** Backend and frontend tests PASS. Commit `Leave: approval over another leave type needs replace (A3)`.

### Task 3: A holiday change refreshes the stored holiday days; the lock lists the changes

**Files:**
- Modify: `backend/app/Modules/Attendance/Application/OfficialHolidays.php` (`update`, `delete`), `backend/app/Modules/Attendance/Application/AttendanceLocks.php` (`lock`), `frontend/src/pages/attendance.tsx` (`LockDialog`), `frontend/src/locales/{ar,en}.json`
- Test: `backend/tests/Feature/AttendanceLockTest.php` (or `OfficialHolidaysTest.php` for the clearing), `frontend/src/pages/attendance.test.tsx`

**Why here and not at read or lock:** a holiday write already needs every group's period on that date open (`assertOpen`), so stored holiday days only go stale in a reopened period, at the moment HR deletes or moves the holiday. Clearing them in that transaction keeps `EffectiveDays::forPeriod` unchanged, makes the missing preview and the confirm checkbox right, and gives a stale template or open day dialog a 409 instead of turning the old holiday into an HR-entered day. The re-lock then stores the current holidays as it does today.

**Interfaces:**
- `OfficialHolidays::delete`, and `update` when the date changes: in the same transaction, every live `attendance_days` row on the old date with source `derived` on the official-holiday leave type (sites `withTrashed`, `leave_code = official_holiday`) is soft-deleted as `clearRow` does (version+1, save, delete; audited). Weekly-off rows and rows with source `entered`/`imported`/`leave` are never touched. A removed holiday on a weekly off is rebuilt as the weekly-off row by the existing lock materialize (`withTrashed()->firstOrNew`).
- `AttendanceLocks::lock` returns the period header plus (computed before materializing):
  - `holidays_removed: list<string>`: re-lock only (an `attendance_locks` row existed): distinct sorted dates of soft-deleted rows of the period's employees with source `derived` on the official-holiday type, `deleted_at` after the previous `locked_at`, whose date is not a current holiday.
  - `holidays_added: list<string>`: re-lock only: dates of current holidays in the period whose `created_at` or `updated_at` is after the previous `locked_at` (added or changed since the last lock).
  - `leave_on_holidays: list<array{employee_id, code, name_ar, name_en, date, leave_code}>`: from the `forPeriod` output, days with state `stored`, `type` = the group, a current holiday date, and code `annual`, `casual`, `sick` or `unpaid`, any source. Not changed.
  - The `PERIOD_LOCKED` audit adds `holidays_removed` and `holidays_added` (counts).
- Frontend `LockDialog`: on success with any non-empty list, render only the three lists (`attendance.relock.removed`, `attendance.relock.added`, `attendance.relock.leaveOnHoliday` with name, date, leave type) and a Close button, with no Lock button or missing list; otherwise toast and close as today.
- Copy: en `removed` `No longer official holidays (their stored days were removed):`, `added` `Official holidays added or changed since the last lock:`, `leaveOnHoliday` `Leave days on an official holiday (not changed; review them):`, `done` `Period locked`. ar `removed` `لم تعد عطلات رسمية (حُذفت أيامها المسجّلة):`, `added` `عطلات رسمية أُضيفت أو عُدّلت منذ آخر قفل:`, `leaveOnHoliday` `أيام إجازة في يوم عطلة رسمية (لم تتغير، راجعها):`, `done` `تم قفل الفترة`.

- [ ] **Step 1: Failing backend tests:**
  - `test_deleting_a_holiday_removes_its_stored_derived_days_only`: lock (holiday D1 stored), reopen, HR enters a site for one employee on D1, delete the holiday → the derived D1 rows are soft-deleted and audited (UPDATED version, DELETED); HR's entered row unchanged; the period header's `missing` now counts D1 for the others.
  - `test_moving_a_holiday_clears_the_old_date`: PATCH D1 → D2 clears D1's derived rows.
  - `test_relock_lists_removed_and_added_holidays`: lock, reopen, move the holiday D1 → D2; lock without confirm → 409 missing unconfirmed; lock with confirm → D2 stored as official holiday, source `derived`; reply `holidays_removed == [D1]`, `holidays_added == [D2]`.
  - `test_a_removed_holiday_on_a_weekly_off_becomes_the_weekly_off`: holiday on a Friday, lock, reopen, delete it, re-lock → that row is `friday`, source `derived`; `holidays_removed == [that Friday]`.
  - `test_relock_never_touches_weekly_off_rows`: change `weekly_off_field` between locks → the stored Friday rows are unchanged.
  - `test_lock_lists_leave_on_a_holiday_without_changing_it`: an approved annual request wrote D3 and HR entered casual on D4; add holidays D3 and D4; lock → `leave_on_holidays` lists both; rows unchanged.
  - `test_a_first_lock_reports_no_holiday_changes`: both lists empty.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4: Frontend.** `LockDialog` result view; vitest in `attendance.test.tsx`: a mocked lock reply with `holidays_removed` keeps the dialog open, lists the date, and shows no Lock button.
- [ ] **Step 5:** Tests PASS. Commit `Attendance: a holiday change refreshes its stored days; the lock lists the changes`.

### Task 4: Docs

**Files:** `docs/calculations.md` (§2a: replace the ⚠️ with the rule; §7: A3 replace, D2 kept, D4 refusal), `docs/superpowers/specs/2026-11-03-leave-design.md` (Workflow Approve step 1 and Cancel; decision table rows D2/D4/A3/re-lock dated 2026-11-04), `docs/hrms-module-plan.md` (attendance lock row: drop the re-lock ⚠️), `docs/client-questions.md` and `docs/superpowers/plans/2026-11-03-leave.md` (mark settled where open).

- [ ] **Step 1:** Edit. Known follow-ups written in the spec: a holiday added after an approval still consumes leave balance; the weekly-off setting has no effective date.
- [ ] **Step 2:** Commit `Docs: leave decisions of 2026-11-04`.

### Gate and screenshots (controller)
- Throwaway stack: own Postgres DB, API on 8001, Vite on 5175, `backend/.env` pointed at it. Full gate: pint, phpstan, deptrac, `php artisan test`, lint, vitest, build.
- Screens at 375/1024/1440, ar and en: A3 preview + checkbox + refusal (a day changed behind an open dialog), the holiday delete then the re-lock lists. D4 has no screen (the app has no employee delete button; the API test proves it). Owner approves, then PR.

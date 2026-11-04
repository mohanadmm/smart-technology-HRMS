# Request and error log, and log retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record every API request and every reported exception with no secrets, money or sensitive numbers in them, show both on a Developer-only console screen in Arabic and English, and prune request/error rows after 90 days and audit rows after 1 year.

**Architecture:** Everything in the `Audit` module. A pure `LogMasker` (Domain) cleans every stored value. One writer, `SystemLog` (Infrastructure), inserts `request_log` rows (from a global terminable middleware) and `error_log` rows (from the exception report callback), each inside a savepoint and never throwing. Read queries and controllers mirror `AuditLogQuery`/`AuditLogController`. One page, `/console/logs`, with two tabs, mirrors `audit.tsx`. Retention is `MassPrunable` on three models driven by a thin `logs:prune` command; a migration relaxes the `audit_log` trigger for rows older than 1 year.

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL 17, PHPUnit 12; React 19 + Vite + TS, react-i18next, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-04-request-error-log-design.md` (read it first; this plan does not repeat its reasons).

## Global Constraints

- Owner rules: every subagent on Opus; never touch the dev DB `hrms` or the VPS; tests run only on `hrms_test` (the base `TestCase` enforces it); never use dsh; never commit `CLAUDE_HANDOFF.md`; nothing is pushed, posted or merged without the owner's yes.
- Each builder loads `ponytail`, `andrej-karpathy-skills:karpathy-guidelines`, `superpowers:test-driven-development` and reads `docs/coding-standards.md`; UI tasks also load `ui-ux-pro-max:ui-ux-pro-max`.
- Backend gates: `docker compose exec app php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml` (see `backend/CLAUDE.md`; in this worktree run them against this worktree's code, as in the memory note on worktree gates). Frontend gates in `frontend/`: `npm run build`, `npm run test`, `npm run lint`.
- `Domain` imports nothing from `Illuminate\*`.
- Masked value: the string `[masked]`. Shape rule: `/\d{7,}/` → `[masked]`.
- Key rule, secrets (substring, lowercased key): `password`, `token`, `secret`, `authorization`, `cookie`.
- Key rule, money and IDs (exact key): `salary`, `raise`, `insured_basic`, `daily_wage`, `value`, `amount`, `current_pay`, `national_id`, `insurance_no`, `bank_account`, `phone`; plus any key ending in `_amount`.
- Stored body cap: 64 KB (65,536 bytes of JSON) after masking, else `{"_truncated": true, "bytes": n}`.
- Not logged: paths outside `api/*`, and `GET api/v1/health`.
- Bodies: `request_body` for status ≥ 400; `response_body` for 400–499 only.
- User on any log row: `Auth::hasUser() ? Auth::id() : null`. Never `$request->user()`.
- Retention: `request_log`/`error_log` `occurred_at < now() - interval '90 days'`; `audit_log` `occurred_at < now() - interval '1 year'`, both as SQL expressions (the DB clock), never a PHP date.
- Permission `system_log.view.all`, developer only; routes use `permission:system_log.view`.
- Schedule: `logs:prune` daily 03:00 `Africa/Cairo`.
- Every UI string is a key in both `frontend/src/locales/ar.json` and `en.json`; Arabic is formal Egyptian business Arabic. Paths, IDs, JSON and traces render `dir="ltr"` monospace.
- Slices: Slice 1 ends at Gate 1 (owner approves screenshots of the Requests tab) before Slice 2 starts. One branch, one PR at the end.

## Review Focus

1. **A national ID typed into the employee search** (`GET /employees?search=29801011234567`, a 200): the stored `query` must hold `[masked]`, not the digits. Pinned in Task 3.
2. **A failed allowance-rule save** (`POST /sites/{id}/allowance-rules` with `{"kind":"fixed","value":"150"}` → 422): `request_body.value` is `[masked]`, `kind` stays. Pinned in Task 3.
3. **A request with invalid UTF-8 or a NUL byte in the body or user agent** still gets its row. Pinned in Task 3.
4. **A token sent to a 404 route or a 401**: logging must not touch `user_sessions`. Pinned in Task 3.
5. **A duplicate `national_id` reaching the database** (unique violation): no log row and no response body holds the 14 digits. Pinned in Task 6.

---

## Slice 1: Request log, end to end

### Task 1: The masker

**Files:**
- Create: `backend/app/Modules/Audit/Domain/LogMasker.php`
- Test: `backend/tests/Unit/LogMaskerTest.php`, `backend/tests/Feature/LogMaskingRulesTest.php`

**Interfaces:**
- Produces:
  - `LogMasker::data(array $data): array` — key rule at any depth (lists too), then shape rule on every remaining string; non-string scalars untouched.
  - `LogMasker::text(string $text): string` — strips NUL bytes, scrubs invalid UTF-8 (`mb_scrub`), applies the shape rule.
  - `LogMasker::encode(?array $data): ?string` — `null` stays `null`; else `data()`, then `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`, NULs (`\u0000`) removed, and the 64 KB cap applied.
  - `LogMasker::MASK = '[masked]'`.

- [ ] **Step 1: Write the failing unit tests** (`LogMaskerTest`, plain `PHPUnit\Framework\TestCase`):
  - `test_secret_keys_are_masked_by_substring`: `['password' => 'x', 'new_password_confirmation' => 'x', 'Authorization' => 'x', 'access_token' => 'x', 'token_type' => 'Bearer']` → every value `[masked]`.
  - `test_money_and_id_keys_are_masked_exactly`: `salary, raise, insured_basic, daily_wage, value, amount, current_pay, national_id, insurance_no, bank_account, phone, advance_amount, outside_amount` all `[masked]`; `pay_method => 'bank'`, `allowance_mode => 'percent'`, `kind => 'fixed'`, `effective_from => '2026-01-01'` unchanged.
  - `test_nested_and_list_values_are_masked`: `['rows' => [['advance_amount' => '50', 'note' => 'x'], ['employee' => ['salary' => '9000']]]]` → both money values masked, `note` kept.
  - `test_digit_runs_are_masked_in_any_string`: `['search' => '29801011234567']` → `[masked]`; `['q' => 'call 01012345678 now']` → `'call [masked] now'`; `['date' => '2026-11-04', 'code' => '123456']` unchanged (no run of 7).
  - `test_encode_survives_bad_bytes`: `encode(['note' => "a\x00b\xC3\x28"])` is valid JSON that decodes, contains no `\u0000`.
  - `test_encode_truncates_large_bodies`: `encode(['note' => str_repeat('a', 70000)])` decodes to `['_truncated' => true, 'bytes' => <int > 65536>]`.
  - `test_text_masks_and_cleans`: `text("dup 29801011234567\x00")` === `'dup [masked]'`.
- [ ] **Step 2: Write the failing rule-array test** (`LogMaskingRulesTest`, extends `Tests\TestCase`, no `RefreshDatabase`): for each rule array in `EmployeeRules::store()`, `EmployeeRules::pay()`, `EmployeeRules::identity(false)`, `SiteRules::allowanceRule()`, `SiteRules::fare()`, `SiteRules::fareOverride()`, `AttendanceDays::FORMAT`: for every key whose rules (flattened, stringified) contain `\d{1,12}(\.\d{1,6})?` or `01[0125]`, assert `LogMasker::data([lastSegment($key) => '15'])[lastSegment] === '[masked]'` (`lastSegment` = text after the final `.`). Also assert `national_id` and `insurance_no` keys exist in `identity(false)` and are masked.
- [ ] **Step 3: Run both tests; expect FAIL (class not found).** `docker compose exec app php artisan test --filter=LogMask`
- [ ] **Step 4: Implement `LogMasker`** with the constants from Global Constraints.
- [ ] **Step 5: Run; expect PASS.** Then pint, phpstan, deptrac.
- [ ] **Step 6: Commit** `Audit: LogMasker for request and error logs`.

### Task 2: Tables and permission

**Files:**
- Create: `backend/database/migrations/2026_11_04_200000_create_request_and_error_log.php`, `backend/database/migrations/2026_11_04_200001_add_system_log_permission.php`, `backend/app/Modules/Audit/Infrastructure/Eloquent/RequestLogEntry.php`, `backend/app/Modules/Audit/Infrastructure/Eloquent/ErrorLogEntry.php`
- Modify: `backend/tests/Feature/RbacTest.php` (count 34 → 35; developer has `system_log.view.all`; ceo, hr, manager do not)

**Interfaces:**
- Produces: tables `request_log` and `error_log` exactly as the spec's Data section (UUIDv7 `id`; `request_id` `string(64)`; `method` `string(10)`; `path` `string(2048)`; `route` `string(255)` nullable; `user_agent` `string(512)`; `status` smallint; `duration_ms` integer; `ip` via `ipAddress()`; jsonb `query`/`request_body`/`response_body`; `error_log`: `class` `string(255)`, `message` text, `file` `string(1024)` nullable, `line` integer nullable, `trace` text, `sqlstate` `string(5)` nullable, `occurred_at` timestamptz; the spec's indexes). Read models `RequestLogEntry`, `ErrorLogEntry` (like `AuditLogEntry`: `HasUuids`, `$timestamps = false`, casts for jsonb and `occurred_at`, `user()` BelongsTo).
- The permission migration follows `2026_11_03_000001_add_employee_edit_code_permission.php` with `GRANTS = ['system_log.view.all' => ['developer']]`.

- [ ] **Step 1: Update `RbacTest::test_seeded_matrix`** (35 permissions; developer grant; not for ceo/hr/manager). Run; expect FAIL.
- [ ] **Step 2: Write both migrations and both models.** Both tables have `down()` that drops them.
- [ ] **Step 3: Run `php artisan test --filter=Rbac`; expect PASS.** Gates.
- [ ] **Step 4: Commit** `Audit: request_log and error_log tables, system_log.view permission`.

### Task 3: Record every API request

**Files:**
- Create: `backend/app/Modules/Audit/Infrastructure/SystemLog.php`, `backend/app/Modules/Audit/Presentation/RecordRequest.php`
- Modify: `backend/bootstrap/app.php` (register `RecordRequest` as global middleware, next to `AddRequestId`), `backend/tests/Feature/HotPathTest.php` (`queriesOf()` drops queries that mention `request_log` and `SAVEPOINT`, with a one-line comment why)
- Test: `backend/tests/Feature/RequestLogTest.php`

**Interfaces:**
- Consumes: `LogMasker` (Task 1), `request_log` (Task 2).
- Produces:
  - `SystemLog::request(Request $request, Response $response): void` — builds and inserts one row; whole body in `try { DB::transaction(fn () => insert) } catch (Throwable $e) { Log::warning('system_log.request_failed', ['class' => $e::class]) }`. Strings cut to column length after `LogMasker::text`. `query` = `LogMasker::encode($request->query->all())` or null when empty. `request_body` (status ≥ 400) = `$request->input()` minus the query keys, plus each uploaded file as `['name' => LogMasker::text(original name), 'size' => bytes]`. `response_body` (400–499) = the decoded JSON content if `getContent()` is a JSON string, else null.
  - `RecordRequest` (terminable): `handle()` calls `$next`, then sets request attributes `log_started_at` (from `$request->server('REQUEST_TIME_FLOAT')` or `LARAVEL_START`) and `log_duration_ms`; `terminate(Request, Response)` returns early outside `api/*` and for `GET api/v1/health`, else calls `SystemLog::request`. `occurred_at` = the start time; a missing attribute becomes now / null duration.
  - Request attribute names are class constants on `RecordRequest`.

- [ ] **Step 1: Write the failing tests** in `RequestLogTest` (`RefreshDatabase`):
  - `test_a_successful_request_is_logged_without_bodies`: developer `GET /api/v1/auth/me` → one row: method `GET`, path `api/v1/auth/me`, route `api/v1/auth/me`, status 200, `user_id` = developer id, `request_id` = the `X-Request-Id` header, `duration_ms` ≥ 0, `request_body` and `response_body` null.
  - `test_a_national_id_in_search_is_masked` (Review Focus 1): HR `GET /api/v1/employees?search=29801011234567` → row's `query` JSON has `search = "[masked]"`; `DB::table('request_log')->where('query', 'like', '%2980101%')->count() === 0`.
  - `test_a_failed_allowance_save_masks_the_amount` (Review Focus 2): create a site, post `{"kind":"fixed","value":"150","effective_from":"bad"}` to its allowance rules → 422 row; `request_body.value` = `[masked]`, `request_body.kind` = `fixed`; `response_body.error.code` = `validation_failed`.
  - `test_a_failed_login_keeps_the_email_and_masks_the_password`: `POST /api/v1/auth/login` with a wrong password → 401 (or 422) row: `request_body.email` kept, `request_body.password` = `[masked]`.
  - `test_a_server_error_keeps_no_response_body`: register a test-only route in the test (`Route::get('api/v1/_boom', fn () => throw new RuntimeException('x'))->middleware('api')`) → 500 row, `response_body` null.
  - `test_bad_bytes_still_get_a_row` (Review Focus 3): `call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_USER_AGENT' => "ua\x00\xC3\x28", 'CONTENT_TYPE' => 'application/json'], "{\"email\":\"a\\u0000b\"}")` → a row exists for that path.
  - `test_logging_does_not_touch_sessions_for_401_and_404` (Review Focus 4): log in (bearer token), record `user_sessions.last_activity_at`, `travel(5)->minutes()`, send the token to `GET /api/v1/nope` (404); assert `last_activity_at` unchanged and the row's `user_id` is null.
  - `test_health_and_non_api_paths_are_not_logged`: `GET /api/v1/health` and `GET /` → 0 rows.
  - `test_a_failing_log_write_does_not_change_the_response`: make the insert fail (e.g. set the request attribute `request_id` to 65 chars via a test route) → the response is still 200 and no exception escapes.
- [ ] **Step 2: Run; expect FAIL.** `php artisan test --filter=RequestLogTest`
- [ ] **Step 3: Implement `SystemLog::request`, `RecordRequest`, the registration, and the `HotPathTest` filter.**
- [ ] **Step 4: Run the whole suite; expect PASS** (watch `HotPathTest`, `ErrorEnvelopeTest`, `AuthenticationTest`). Gates.
- [ ] **Step 5: Commit** `Audit: record every API request (masked bodies for failures)`.

### Task 4: Request log API

**Files:**
- Create: `backend/app/Modules/Audit/Application/CairoDates.php`, `backend/app/Modules/Audit/Application/RequestLogQuery.php`, `backend/app/Modules/Audit/Presentation/RequestLogController.php`
- Modify: `backend/app/Modules/Audit/Application/AuditLogQuery.php` (use `CairoDates`), `backend/routes/api.php` (next to `audit-log`)
- Test: `backend/tests/Feature/RequestLogApiTest.php`

**Interfaces:**
- Produces:
  - `CairoDates::apply(Builder $query, array $filters, string $column = 'occurred_at'): void` — the from/to logic now in `AuditLogQuery::list`, moved, not copied.
  - `RequestLogQuery::list(array $filters): array` — same return shape as `AuditLogQuery::list`; newest first (`occurred_at desc, id desc`); filters `status` (`failed` ≥ 400, `server` ≥ 500), `method`, `user_id`, `path` (ILIKE contains, `%`/`_` escaped), `request_id`, from/to; each row carries `user: {id, name} | null`.
  - `GET /api/v1/request-log` → `RequestLogController::index`, `permission:system_log.view`; validation: `status in:failed,server`, `method in:GET,POST,PUT,PATCH,DELETE`, `user_id uuid`, `path string max:200`, `request_id string max:64`, `from/to date_format:Y-m-d` (to after_or_equal from), `per_page 1..100`.
- [ ] **Step 1: Write failing tests:** 403 for `hr`, `ceo`, `manager`; 200 for `developer`; each filter narrows (insert rows directly with `DB::table('request_log')->insert`, backdated); newest first; `AuditLogTest` still passes after the `CairoDates` move.
- [ ] **Step 2: Run; FAIL. Step 3: implement. Step 4: run whole suite; PASS. Gates.**
- [ ] **Step 5: Commit** `Audit: request log API`.

### Task 5: Logs page, Requests tab

**Files:**
- Create: `frontend/src/pages/logs.tsx`, `frontend/src/pages/logs.test.tsx`
- Modify: `frontend/src/pages/audit.tsx` (export `show`), `frontend/src/App.tsx` (lazy `LogsPage`, route `console/logs`), `frontend/src/components/layout/AppLayout.tsx` (nav item after audit: `/console/logs`, `nav.logs`, a lucide icon such as `Activity`, abilities `['system_log.view']`), `frontend/src/locales/ar.json`, `frontend/src/locales/en.json` (`nav.logs`, `page.logs`, `logs.*`, `perm.resource.system_log`, `perm.system_log.view`), `frontend/src/pages/console.test.tsx` (role editor shows the new label)

**Interfaces:**
- Consumes: `GET /api/v1/request-log` (Task 4); `useList`, `useForm`, `SelectField`, `TextField`, `ErrorBanner`, `useCairoTime`, `DataList`, `PageHeader`, `Sheet`, `Badge`, `Tabs*` as `audit.tsx` and the existing tab pages use them.
- Produces: `export function LogsPage()`; tab state in the URL search param `tab` (`requests` | `errors`) and filter `request_id` in the URL too, so Task 7 can link between tabs. Slice 1 renders only the Requests tab content; the Errors tab trigger exists but is added in Task 7.
- Columns: time, method + path (ltr mono), status badge (`success` < 400, `warning` 400–499, `danger` ≥ 500), duration (ms), account. Filters: from, to, status (all / failed / server), method, account (only with `user.view`, like the audit page), path. Details sheet (`sm:max-w-[720px]`): request ID, route, IP, user agent, then `query`, `request_body`, `response_body` printed with `show`.

- [ ] **Step 1: Write failing Vitest tests** in `logs.test.tsx` (pattern of `audit.test.tsx`): filters become the query string (`status=failed`, `path=employees`, `from=`); a 422 row shows a warning badge and its masked `request_body` in the details sheet; the page renders in Arabic by default. In `console.test.tsx`: the roles editor shows the Arabic label for `system_log.view`.
- [ ] **Step 2: Run `npm run test -- logs console`; FAIL. Step 3: implement. Step 4: `npm run build && npm run test && npm run lint`; PASS.**
- [ ] **Step 5: Commit** `Console: request log page`.

### Gate 1: owner approval of the Requests tab

- [ ] Serve this worktree against a throwaway copy of the DB (memory note "worktree gate API env"; never the `hrms` DB), generate traffic (a successful save, a 422, a search by a 14-digit number, a wrong-password login).
- [ ] Capture with the `verify-hrms` skill: `/console/logs` list and a 422 details sheet, at 375 / 1024 / 1440, Arabic and English, light and dark; plus the nav item and the role editor row.
- [ ] Report to the owner in Arabic with the screenshots; wait for approval. Slice 2 starts only after it.

## Slice 2: Errors and retention

### Task 6: Record every reported exception

**Files:**
- Modify: `backend/app/Modules/Audit/Infrastructure/SystemLog.php` (add `error`), `backend/bootstrap/app.php` (`$exceptions->report(fn (Throwable $e) => SystemLog::error($e))`, without `->stop()`), `backend/config/database.php` (pgsql: `'mask_bindings_in_exception_messages' => true`), `docker/php/Dockerfile` (append `zend.exception_ignore_args=On` to the `zz-app.ini` printf), `backend/app/Support/Money/Decimal.php` (drop `{$value}` from both messages; update `tests/Unit/DecimalTest.php` if it asserts them)
- Test: `backend/tests/Feature/ErrorLogTest.php`

**Interfaces:**
- Produces: `SystemLog::error(Throwable $e): void` — never throws (same savepoint + catch as `request`). Walks `getPrevious()`; if any link is a `PDOException` or `QueryException`: `sqlstate` = its `errorInfo[0]` (or `getCode()` when 5 chars), `message` = `"SQLSTATE {sqlstate}"` + `", constraint {name}"` when `errorInfo[2]` contains `constraint "…"` + `"\n" . $queryException->getSql()` — never the driver text. Otherwise `message` = `LogMasker::text($e->getMessage())`. `class` = top exception class; `file`/`line` from the top exception; `trace` = `getTrace()` frames rendered as `#i file(line): class type function()` with no args; `request_id` from the current request attribute when running under HTTP, else null; `user_id` by the global user rule.

- [ ] **Step 1: Write failing tests** (`ErrorLogTest`, `RefreshDatabase`):
  - `test_an_unhandled_exception_is_logged_and_linked`: test route throwing `RuntimeException('boom 29801011234567')` → one `error_log` row: class, message `boom [masked]`, file, line, `request_id` = the response's `X-Request-Id`, matching `request_log` row with status 500.
  - `test_dont_report_exceptions_are_not_logged`: a 422, a 401, a 403, a 404 → zero `error_log` rows.
  - `test_a_database_error_stores_no_values` (Review Focus 5): two employees, then inside `DB::transaction` (savepoint) `DB::table('employees')->where('id', $b)->update(['national_id' => $a_national_id])` caught, `report($e)` → `error_log.sqlstate = '23505'`, message contains the constraint name, and no row in `error_log` or `request_log` contains the 14 digits (`like '%'.$id.'%'`).
  - `test_the_trace_has_no_arguments`: a closure route calling a helper `fn (string $secret) => throw new RuntimeException('x')` with `'abc-secret-arg'` → stored `trace` lacks `abc-secret-arg`.
  - `test_query_exception_message_masks_bindings`: a raw `QueryException` from a bad insert with a string binding → `$e->getMessage()` contains no binding value (proves the config line).
- [ ] **Step 2: FAIL. Step 3: implement. Step 4: whole suite PASS; gates. Rebuild the image locally only if you need to prove the ini line (`docker compose build app` then `php -i | grep exception_ignore_args`).**
- [ ] **Step 5: Commit** `Audit: record reported exceptions without data`.

### Task 7: Error log API and Errors tab

**Files:**
- Create: `backend/app/Modules/Audit/Application/ErrorLogQuery.php`, `backend/app/Modules/Audit/Presentation/ErrorLogController.php`
- Modify: `backend/routes/api.php`, `frontend/src/pages/logs.tsx`, `frontend/src/pages/logs.test.tsx`, both locale files
- Test: `backend/tests/Feature/ErrorLogApiTest.php`

**Interfaces:**
- Produces: `GET /api/v1/error-log` (`permission:system_log.view`; filters `from`, `to`, `request_id`, `per_page`; newest first; via `CairoDates`). Errors tab: time, class (ltr mono), message (one line, truncated), request ID. Filters from/to; a `request_id` from the URL shows as a chip with a clear button. Details: file:line, SQLSTATE, full message, trace (ltr mono, scrollable). Links: request details → `?tab=errors&request_id=…`; error details → `?tab=requests&request_id=…`.
- [ ] **Step 1: Failing tests:** API permission (403 non-developers, 200 developer) and filters; Vitest: the request sheet's link switches to the Errors tab filtered by that request ID and the chip clears it; the error sheet links back.
- [ ] **Step 2: FAIL. Step 3: implement. Step 4: all gates PASS. Step 5: Commit** `Console: error log tab and request links`.

### Task 8: Retention

**Files:**
- Create: `backend/database/migrations/2026_11_04_200002_allow_pruning_old_audit_rows.php`, `backend/app/Modules/Audit/Presentation/PruneLogsCommand.php`
- Modify: `RequestLogEntry`, `ErrorLogEntry`, `AuditLogEntry` (add `MassPrunable` + `prunable()`), `backend/bootstrap/app.php` (`withCommands`), `backend/routes/console.php` (`Schedule::command('logs:prune')->dailyAt('03:00')->timezone('Africa/Cairo')`), `backend/CLAUDE.md` (one "Scheduled:" line), `docs/hrms-module-plan.md` (mark the two Release 1 item 2 bullets done, the same way other finished items are marked)
- Test: `backend/tests/Feature/LogRetentionTest.php`

**Interfaces:**
- Produces:
  - `prunable()` = `static::whereRaw("occurred_at < now() - interval '90 days'")` (request, error) and `"... interval '1 year'"` (audit).
  - `logs:prune` (`PruneLogsCommand`): calls `pruneAll()` on the three models, prints `request_log: n, error_log: n, audit_log: n`; when the audit count > 0, `AuditLog::record('PRUNED', 'audit_log', null, null, ['count' => n, 'cutoff' => <DB value of now() - interval '1 year', ISO string>])`.
  - Trigger function body (CREATE OR REPLACE), exactly: `IF TG_OP = 'DELETE' AND OLD.occurred_at < now() - interval '1 year' THEN RETURN OLD; END IF; RAISE EXCEPTION 'audit_log is append-only: % is refused', TG_OP;` — `down()` restores the current body.
- [ ] **Step 1: Failing tests** (insert backdated rows with `DB::table(...)->insert`; never `travel()` for DB time):
  - `test_prune_deletes_old_log_rows_and_keeps_recent_ones`: request/error rows at 91 and 89 days old → after `artisan logs:prune` only the 89-day rows remain.
  - `test_prune_deletes_audit_rows_older_than_a_year_and_records_it`: audit rows 13 and 11 months old → 13-month row gone, 11-month row kept, one `PRUNED` audit row with `count = 1`.
  - `test_the_trigger_still_refuses_young_rows`: inside `DB::transaction`, deleting the 11-month row raises with `append-only`; update and truncate still raise.
  - `AuditLogTest::test_audit_log_is_append_only` still passes unchanged.
  - `test_logs_prune_is_scheduled_at_three_cairo`: the schedule has `logs:prune` with cron `0 3 * * *` and timezone `Africa/Cairo` (pattern of the existing schedule tests, if any; else read `Schedule::events()`).
- [ ] **Step 2: FAIL. Step 3: implement. Step 4: whole suite and gates PASS.**
- [ ] **Step 5: Commit** `Audit: prune request/error logs at 90 days, audit at 1 year`.

### Gate 2: owner approval, review, PR

- [ ] Regenerate gate traffic including a forced 500 (a temporary local-only route is fine in the throwaway stack; do not commit it) and a database unique violation; capture the Errors tab, an error details sheet and the request↔error links at 375 / 1024 / 1440, ar/en, light/dark.
- [ ] Run the `code-review` skill (standards and spec) and the `security-review` skill on the branch; fix what they confirm.
- [ ] Report to the owner in Arabic with screenshots and review results; on approval, push the branch and open the PR into `main` (body ends with the Claude Code line); do not merge.

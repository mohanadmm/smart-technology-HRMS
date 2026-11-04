# Request and error log, and log retention: design

Release 1, item 2 (Developer console) of `docs/hrms-module-plan.md`: "Error & request/response log screen" and "Retention: 1 year for audit, 90 days for request/error logs". Foundation, owned by Youssef.

## Why

When HR says "the save didn't work" or "the screen showed an error", the developer has nothing to look at today: no record of the request, its status or the exception. The log screen answers "what did the server get, what did it answer, and what blew up", without SSH access to the server. Retention keeps the tables from growing forever on the client's on-prem server.

## Goals

- Record every `/api/*` request: time, account, method, path, route, query, status, duration, IP, user agent, request ID.
- For a failed request, also keep the request body, and for a 4xx the response body, with secrets, money and sensitive numbers masked.
- Record every reported exception (HTTP requests, queued jobs, scheduled commands) with its class, message, file, line and stack frames, linked to its request by request ID.
- A Developer console screen to browse both, in Arabic and English, behind a Developer-only permission.
- A daily prune: request and error rows older than 90 days, audit rows older than 1 year.

## Non-goals

- No log of frontend (browser) errors.
- No alerting, email or notification on errors.
- No export or download of log rows.
- No backup automation (CLAUDE.md).
- No fix for the client IP behind the TLS proxy (no `trustProxies` today; `audit_log` has the same gap). Separate follow-up.

## Anti-goals (a failure even if it works)

- A password, token, money value, national ID, insurance number, bank account or phone number stored in any log row.
- A request that fails, or slows down visibly, because logging failed. Logging must never break the request it logs.
- A Team lead, HR or CEO account seeing the screen by default.
- An audit row younger than 1 year becoming deletable or editable.

## Decided by the owner (2026-11-04)

1. **What is logged:** every API request with its metadata. Bodies only for failed requests (4xx/5xx). Successful requests keep no body.
2. **Masking beyond secrets and money:** mask sensitive numbers (national ID, insurance number, bank account, phone); keep names, addresses, notes and a failed login's email visible.
3. **Audit retention:** relax the append-only trigger on `audit_log` so the database accepts a DELETE only of rows more than 1 year old. UPDATE and TRUNCATE stay refused, and so does deleting any row younger than 1 year.

## Decided in the task brief

- The screen is behind a Developer-only permission, checked by name.
- Retention: 1 year for `audit_log`, 90 days for the request and error logs.
- The CRM (github.com/mr-h12/smart-technology-CRM) has no request or error log to mirror (checked 2026-11-04); its audit log is append-only like ours.

## Research and review (2026-11-04)

- **Laravel Telescope** records requests and exceptions, prunes, and hides named parameters. Not used: it is not installed, its dashboard is its own English LTR UI outside the console, and it records far more than this needs.
- **OWASP-style guidance:** log metadata by default; keep bodies only where needed, masked before writing; restrict who reads them. This design follows it.
- **Terminable middleware** is the usual Laravel place for request logging; under php-fpm (our image) it runs after the response is flushed.
- **Retention at our size** (about 10 accounts) is a plain DELETE; partitioning pays off only at tens of millions of rows.
- Two adversarial reviews (pstack `interrogate`, two Opus reviewers) found the leaks and traps fixed below: Postgres error `DETAIL` lines and stack-trace arguments carry values, `search=` carries national IDs, the allowance amount is posted as `value`, `$request->user()` re-runs the bearer resolver, and the trigger and prune must share one cutoff.

## Design

Everything lives in the `Audit` module (Foundation, Youssef). `deptrac.layers.yaml` enforces layers only, so no new deptrac rules.

### Data

Two new tables, not business tables (no `standardColumns`, like `audit_log`), UUIDv7 ids, `request_id` as `string(64)` like `audit_log`.

`request_log`, one row per `/api/*` request:
- `request_id`, `user_id` (nullable), `method` (string 10), `path` (string 2048, without the query string), `route` (matched route pattern, nullable for 404s), `query` (jsonb, masked, nullable), `status` (smallint), `duration_ms` (integer), `ip`, `user_agent` (512), `request_body` (jsonb, nullable), `response_body` (jsonb, nullable), `occurred_at` (timestamptz, the request's start time).
- Indexes on `occurred_at desc`, `(status, occurred_at desc)`, `request_id`.

`error_log`, one row per reported exception:
- `request_id` (nullable: jobs and commands have none), `user_id` (nullable), `class`, `message` (text, masked), `file`, `line`, `trace` (text: frames only), `sqlstate` (nullable), `occurred_at`.
- Indexes on `occurred_at desc`, `request_id`.

### Masking

One masker, used for the query, the request body, the response body, uploaded file names and error messages. Two rules:

1. **By key.** The value of a key is replaced by `"[masked]"`, at any depth (inside lists too), when:
   - its lowercased name contains `password`, `token`, `secret`, `authorization` or `cookie`; or
   - its name is exactly one of the money and ID keys: `salary`, `raise`, `insured_basic`, `daily_wage`, `value`, `amount`, `current_pay`, `national_id`, `insurance_no`, `bank_account`, `phone`; or it ends in `_amount`.
2. **By shape.** In any stored string, a run of 7 or more digits becomes `[masked]`. This catches a national ID, account or phone typed into `search=`/`q=`, an ID used as an uploaded file name, and an ID inside an error message, whatever the key.

Exact names, not fragments, for money: fragments like `pay`, `rate` or `basic` mask `pay_method`, `allowance_mode` or whole `fares` lists that are needed to debug a 422, and catch future harmless keys. The safety net is a test, not a manual check: it feeds the real validation rule arrays (`EmployeeRules`, `SiteRules`, the fare, pay-change and attendance rules) through the masker and asserts every key whose rule uses the money or mobile pattern, plus the ID keys, comes out masked. A new money field that reuses those rule constants fails the test until it is added.

Uploaded files are not stored: the request body keeps each file field's masked original name and size. The request body is the JSON or form input (`$request->input()` without the query string merged in). A stored body larger than 64 KB after masking becomes `{"_truncated": true, "bytes": n}`. Headers are not stored, except the user agent.

### Error text without data

- `config/database.php`: `'mask_bindings_in_exception_messages' => true` on the pgsql connection. One line; it also protects Laravel's file log and the debug 500 envelope.
- For any exception whose `getPrevious()` chain holds a `PDOException` or `QueryException`, `error_log` stores the SQLSTATE, the constraint name if any, and `getSql()` (placeholders). Never the driver text: its `DETAIL:` line repeats the key (`Key (national_id)=(…)`) or the whole failing row.
- Every other exception message goes through the masker's shape rule.
- `Support/Money/Decimal.php` stops putting the value in its exception messages.
- `trace` is built from `getTrace()` with only file, line, class, type and function, never `args`. `docker/php/Dockerfile`'s `zz-app.ini` also gets `zend.exception_ignore_args=On`, which protects the file log and `failed_jobs` too.

### Recording requests

A global middleware next to `AddRequestId` (so unmatched 404s and 413s are logged too). It skips any path outside `api/*` and `GET /api/v1/health` (the nginx healthcheck calls it every 10 seconds, 8,640 rows a day that no person sent).

- In `handle()`, after `$next`, it puts the start time and duration on the request attributes (from `LARAVEL_START` / `REQUEST_TIME_FLOAT`). Never on `$this`: the kernel builds a fresh instance for `terminate()`.
- In `terminate()` it writes the row, after the response is sent. Missing attributes (an earlier middleware threw) mean a row with what is known.
- The user is `Auth::hasUser() ? Auth::id() : null`, as `AuditLog` does. Never `$request->user()`: that re-runs the bearer resolver, which touches `last_activity_at` or deletes an idle session.
- Bodies: the request body for any status 400 or more; the response body for 4xx only. A 5xx body is the generic envelope in production and the raw message in debug; the error row holds the details.
- The JSON encoding uses `JSON_INVALID_UTF8_SUBSTITUTE`, NUL bytes are stripped and every string column is cut to its length, so an odd request still gets its row.
- The insert runs inside `DB::transaction()` (a savepoint when a transaction is open) and any exception is caught and sent only to Laravel's file log, never re-thrown and never to `error_log` (no loop).

### Recording errors

A report callback in `bootstrap/app.php` (`$exceptions->report(...)`): the existing `dontReport` list (validation, 401, 403, 404, attendance and leave refusals, conflicts) stays out of the error log for free, and errors from jobs and commands are caught too. The default file logging keeps running. Same user rule, savepoint and catch-all as requests. Under RefreshDatabase, a test that triggers a database error must do it inside a savepoint (`DB::transaction`), as `AuditLogTest` does, or the outer transaction is aborted and no row can be written.

### Retention

The cutoff lives in one place per table: each log model is `MassPrunable` with a `prunable()` query.
- `request_log`, `error_log`: `occurred_at < now() - interval '90 days'`.
- `audit_log` (`AuditLogEntry`): `occurred_at < now() - interval '1 year'`, as raw SQL, the same expression as the trigger, so the database clock decides both and they can never disagree (a PHP `subYear()` differs on 29 February and with clock drift, and one refused row rolls back the whole DELETE).

`Schedule::command('model:prune', ['--model' => [the three models]])->dailyAt('03:00')->timezone('Africa/Cairo')` runs it in chunks and prints counts. After an audit prune that deleted rows, one `audit_log` entry records it (event `PRUNED`, entity `audit_log`, the count and cutoff in `new_values`), so a gap in old audit rows reads as retention, not tampering. The builder picks the hook (a `ModelsPruned` listener or a thin command).

A migration replaces the `audit_log_is_append_only()` function:
`IF TG_OP = 'DELETE' AND OLD.occurred_at < now() - interval '1 year' THEN RETURN OLD; END IF;` then the existing raise. It branches on `TG_OP` before reading `OLD` (the TRUNCATE trigger is statement level, with no `OLD`); it returns `OLD`, never `NULL` (that silently skips the row); its message keeps `append-only` (`AuditLogTest` checks it). `down()` restores the strict body.

### Permission

A migration adds `system_log.view.all` and grants it to the `developer` role only. Routes check `permission:system_log.view`. The role editor gets `perm.resource.system_log` and `perm.system_log.view` in ar and en.

No extra guard stops a Developer from granting it to another role in the role editor: roles and permissions are data (CLAUDE.md), and only `role.manage` holders edit them. "Not seen by HR" means not granted by default.

### API

- `GET /api/v1/request-log`: filters `from`, `to` (Cairo dates, inclusive, like the audit log), `status` (`failed` = 400 or more, `server` = 500 or more), `method`, `user_id`, `path` (contains), `request_id`; paginated, newest first.
- `GET /api/v1/error-log`: filters `from`, `to`, `request_id`; paginated, newest first.
- The Cairo from/to filter is written once and shared by the three log queries.

### Screen

A new Developer console page, `/console/logs`, in the console nav after the audit log, shown only with `system_log.view`. It follows `frontend/src/pages/audit.tsx`: `PageHeader`, a filter card, `DataList`, a details `Sheet` (wider than the audit one, for traces and JSON), and it reuses the audit page's value printer instead of copying it.

Two tabs, Requests and Errors.
- **Requests:** time, method and path, status badge (2xx success, 4xx warning, 5xx danger), duration, account. Filters: from, to, status (all / failed / server errors), method, account, path. Details: request ID, route, IP, user agent, query, request body, response body, and a link that opens the Errors tab filtered by this request ID.
- **Errors:** time, class, message (one line), request ID. Filters: from, to; a request-ID filter set from a link shows as a chip with a clear button. Details: file and line, SQLSTATE, full message, frames, and a link to the request (Requests tab filtered by its request ID).

Paths, IDs, bodies and traces are shown left-to-right in a monospace font inside the RTL page. Every label is an i18n key in Arabic and English.

## Tests that must exist

PHPUnit:
- a request row with metadata and no body for a 200; with masked request and response bodies for a 422; no response body for a 500;
- the masker against the real rule arrays; `?search=29801011234567` and `{"kind":"fixed","value":"150"}` leave no digits or amount;
- a real duplicate `national_id` at the database level (inside a savepoint): the 14 digits appear in no log row; the row has the SQLSTATE;
- a frame argument value is absent from the stored trace;
- the user ID is recorded for an authenticated request, and logging a 401 or 404 does not touch any `user_sessions` row;
- a failing log write does not change the response;
- permission: 403 for HR, CEO and site manager, 200 for developer;
- prune: backdated rows (inserted, not time-travelled: Postgres `now()` is frozen in the test transaction) are deleted past the cutoff and kept before it; an audit row 13 months old is pruned, 11 months old is refused by the trigger; the prune audit entry exists.
- Existing tests to update: `HotPathTest` (exact query counts and "no insert" now see the log insert: exclude `request_log` there) and `RbacTest` (permission count).

Vitest: the logs page renders both tabs and the request-to-errors link; the role editor shows the new permission label.

Backend and frontend gates must pass.

## Left to the builder

- Class and migration names, the prune audit hook, exact UI components and page layout, pagination size.
- Whether the log endpoints log their own requests.
- Docs: mark the item in `docs/hrms-module-plan.md`; add terms to `CONTEXT.md` if needed.

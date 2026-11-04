# Request and error log, and log retention: design

Release 1, item 2 (Developer console) of `docs/hrms-module-plan.md`: "Error & request/response log screen" and "Retention: 1 year for audit, 90 days for request/error logs". Foundation, owned by Youssef.

## Why

When HR says "the save didn't work" or "the screen showed an error", the developer has nothing to look at today: no record of the request, its status or the exception. The log screen answers "what did the server get, what did it answer, and what blew up", without SSH access to the server. Retention keeps the tables from growing forever on the client's on-prem server.

## Goals

- Record every `/api/*` request: time, account, method, path, route, status, duration, IP, user agent, request ID.
- For a failed request (status 400 or more), also keep the request body and the response body, with secrets and wages masked.
- Record every reported exception (HTTP requests, queued jobs, scheduled commands) with its class, message, file, line and stack trace, linked to its request by request ID.
- A Developer console screen to browse both, in Arabic and English, behind a Developer-only permission.
- A daily prune: request and error rows older than 90 days, audit rows older than 1 year.

## Non-goals

- No log of frontend (browser) errors.
- No alerting, email or notification on errors.
- No export or download of log rows.
- No change to Laravel's file log (`storage/logs`); it keeps working as it does.
- No backup automation (CLAUDE.md).

## Anti-goals (a failure even if it works)

- A password, token, or wage/money value stored in any log row.
- A request that fails, or slows down visibly, because logging failed. Logging must never break the request it logs.
- A Team lead, HR or CEO account seeing the screen.
- An audit row younger than 1 year becoming deletable or editable.

## Decided by the owner (2026-11-04)

1. **What is logged:** every API request with its metadata. Request and response bodies only for failed requests (4xx/5xx). Successful requests keep no body.
2. **Masking beyond secrets and wages:** mask sensitive numbers (national ID, insurance number, bank account, phone); keep names, addresses, notes and a failed login's email visible.
3. **Audit retention:** relax the append-only trigger on `audit_log` so the database accepts a DELETE only of rows whose `occurred_at` is more than 1 year old. UPDATE and TRUNCATE stay refused, and so does deleting any row younger than 1 year.

## Decided in the task brief

- Never log passwords, tokens or wages.
- The screen is behind a Developer-only permission, checked by name.
- Retention: 1 year for `audit_log`, 90 days for the request and error logs.
- The CRM (github.com/mr-h12/smart-technology-CRM) has no request or error log to mirror (checked 2026-11-04); its audit log is append-only like ours.

## Design

### Data

Two new tables, not business tables (no `standardColumns`, like `audit_log`), UUIDv7 ids.

`request_log`, one row per `/api/*` request:
- `request_id` (the ID `AddRequestId` puts on the request and the `X-Request-Id` header), `user_id` (nullable), `method`, `path` (without the query string), `route` (the matched route pattern, nullable for 404s), `query` (jsonb, masked, nullable), `status`, `duration_ms`, `ip`, `user_agent` (512), `request_body` (jsonb, nullable), `response_body` (jsonb, nullable), `occurred_at` (timestamptz).
- Indexes on `occurred_at desc`, `(status, occurred_at desc)`, `request_id`.

`error_log`, one row per reported exception:
- `request_id` (nullable: jobs and commands have none), `user_id` (nullable), `class`, `message` (text), `file`, `line`, `trace` (text), `occurred_at`.
- Indexes on `occurred_at desc`, `request_id`.

### Masking

One masking function used for the query, the request body and the response body. It walks the array recursively and replaces the value of any key whose lowercased name contains one of these fragments with `"[masked]"`:
- secrets: `password`, `token`, `secret`, `authorization`, `cookie`;
- money: `salary`, `wage`, `basic`, `raise`, `amount`, `allowance`, `fare`, `incentive`, `deduction`, `insurance`, `tax`, `bonus`, `rate`, `net`, `gross`, `pay`;
- sensitive numbers (owner, 2026-11-04): `national_id`, `insurance_no` (already caught by `insurance`), `bank`, `phone`.

Names, addresses, HR notes, termination reasons and the email of a failed login stay visible: the owner chose to keep them because they help find why a request failed, and only the Developer sees the screen.

The list errs toward masking too much: a masked harmless field costs nothing, a leaked wage breaks the anti-goal. The builder checks the list against every validated input key under `backend/app` and adds any money or sensitive-number key it misses; a unit test pins that `password`, `token`, `salary`, `daily_wage`, `insured_basic`, `amount`, `national_id`, `bank_account`, `phone` and a nested key are masked.

Uploaded files are not stored: the request body keeps only each file field's original file name and size. A body larger than 64 KB after masking is replaced by `{"_truncated": true, "bytes": n}`.

Headers are not stored, except the user agent.

### Recording requests

A global terminable middleware (next to `AddRequestId`, so unmatched 404s and 413s are logged too) that skips any path outside `api/*`, and skips `GET /api/v1/health` (my call: the nginx healthcheck in `docker-compose.yml` calls it every 10 seconds, 8,640 rows a day that no person sent). It writes the row after the response is sent, so the user does not wait for it. The user ID is read from the request, not from the guard, after the response, because `ForgetResolvedGuards` may forget the guard; the builder confirms the user ID is still known when the row is written (a test pins it). Any exception while writing the row is swallowed and reported only to Laravel's file log, never re-thrown and never to `error_log` (no loop).

### Recording errors

A report callback in `bootstrap/app.php` (`$exceptions->report(...)`), so the existing `dontReport` list (validation, 401, 403, 404, attendance and leave refusals, conflicts) stays out of the error log for free, and errors from jobs and commands are caught too. The default file logging keeps running.

A `QueryException` message includes the SQL bindings, which can be wages. For it, store the SQL with placeholders (`getSql()`) plus the driver's message without bindings, never `getMessage()`. The trace is the string trace without arguments. Writing the row must never throw (the database may be what failed); a failure goes only to the file log.

### Retention

One command, `logs:prune`, scheduled daily at 03:00 Cairo in `routes/console.php`:
- deletes `request_log` and `error_log` rows with `occurred_at` older than 90 days;
- deletes `audit_log` rows older than 1 year;
- prints the three counts.

The retention periods are code constants, not settings: they are operational, not business rules, and the 1-year floor also lives in the database trigger.

A migration replaces the `audit_log_is_append_only()` trigger function: on DELETE it allows the row when `OLD.occurred_at < now() - interval '1 year'` and raises otherwise; UPDATE and TRUNCATE keep raising.

### Permission

A migration adds `system_log.view.all` and grants it to the `developer` role only. Routes check `permission:system_log.view`.

My call: no extra guard stops a Developer from granting it to another role in the role editor. Roles and permissions are data editable from the console (CLAUDE.md), and only `role.manage` holders (Developer) can edit them. The anti-goal means "not granted by default", not "impossible to grant".

### API

- `GET /api/v1/request-log`: filters `from`, `to` (Cairo dates, inclusive, like the audit log), `status` (`failed` = 400 or more, `server` = 500 or more), `method`, `user_id`, `path` (contains); paginated, newest first.
- `GET /api/v1/error-log`: filters `from`, `to`, `request_id`; paginated, newest first.
- Each request row says how many error rows share its request ID, so the screen can link them.

### Screen

A new Developer console page, `/console/logs`, in the console nav after the audit log, shown only with `system_log.view`. It follows the audit page (`frontend/src/pages/audit.tsx`): `PageHeader`, a filter card, `DataList`, a details `Sheet`.

Two tabs, Requests and Errors.
- **Requests:** time, method and path, status as a badge (2xx success, 4xx warning, 5xx danger), duration, account. Filters: from, to, status (all / failed / server errors), method, account, path. Details: request ID, route, IP, user agent, query, request body, response body, and a link to the errors with the same request ID.
- **Errors:** time, class, message (one line), request ID. Filters: from, to. Details: file and line, full message, trace, and a link to the request.

Paths, IDs, bodies and traces are shown left-to-right in a monospace font inside the RTL page, like the audit page shows field names. Every label is an i18n key in Arabic and English.

## Left to the builder

- Module placement (inside `Audit`, or a new module with deptrac rules), class names, migration names.
- Exact UI components (tabs, badge variants), page layout details, pagination size.
- Whether the log endpoints log their own requests.
- Tests: PHPUnit for the middleware, masking, error capture, the QueryException case, permission, the prune command and the trigger; one Vitest for the page. Backend and frontend gates must pass.
- Docs: mark the item in `docs/hrms-module-plan.md` and add the terms to `CONTEXT.md` if the glossary needs them.

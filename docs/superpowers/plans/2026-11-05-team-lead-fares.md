# Team Lead Sets the Transport Fare Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every builder also follows `ponytail`, `andrej-karpathy-skills:karpathy-guidelines` and `docs/coding-standards.md`.

**Goal:** A team lead corrects the daily transport fare of their own team (override per worker, or the residence × site default) from a new «مواصلات التيم» page; HR sees and edits every fare on a combined Settings → Fares list and is notified of each team-lead change; `GET /fares` stops leaking other sites' fares to a team lead.

**Architecture:** No new tables. `fares` and `employee_fare_overrides` stay the source of truth and `FareLookup` the one resolver. A new permission `fare.manage` (all: developer + HR; own: `manager`) gates the two write routes and a new `GET /team-fares`. The API write path gets new `FareService::saveFare/saveOverride` (rules + same-key update + notify); the importer keeps the strict `addFare/addOverride`.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 17 / PHPUnit 12; React 19 + Vite + TS, react-i18next, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-11-05-team-lead-fares-design.md` (approved; read it with this plan).

## How to run things (owner rules)

- Never run `docker compose exec app`, never touch the `hrms` database or the VPS, never use dsh. Tests use `hrms_test` (`phpunit.xml` forces it; `tests/TestCase.php` refuses any other DB).
- Backend runner, from the worktree root: `docker run --rm --network hrms_default -v "$PWD/backend":/var/www/html -w /var/www/html hrms-php <cmd>`, e.g. `php artisan test --filter=Fare`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`. Below, `RUN` stands for that prefix. If the Docker daemon is down: `open -a Docker` and wait.
- If missing in this worktree: `cp -c -R "/Users/yousefhasabo/HRMS-Smart TECH/backend/vendor" backend/`, `cp -c -R "/Users/yousefhasabo/HRMS-Smart TECH/frontend/node_modules" frontend/`, and `backend/.env` from the main checkout (gitignored; never commit).
- Frontend, in `frontend/`: `npx vitest run <files>`, `npx tsc -b`, `npm run lint`.
- Commits: each builder stages only its own paths (`git add <paths>`), retries on `index.lock`, ends messages with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never commit `CLAUDE_HANDOFF.md`, `.env`, `vendor`, `node_modules`.
- Part A (backend + docs) and Part B (frontend) run **in parallel in this worktree**. Part A owns `backend/**`, `docs/calculations.md`, `CONTEXT.md`, `docs/hrms-module-plan.md`. Part B owns `frontend/**`. Neither touches the other's paths. Part B codes against the contract below with mocked fetch and never waits on Part A.
- Out of scope (Mohanad's files, follow-ups only): `Attendance/Application/SiteDay.php`, `pages/site-day-row.tsx`, `lib/site-day.ts`, `SiteDaySaveTest`, `NotificationBell.tsx`.

## Shared API contract (both parts code to this)

All under `/api/v1`, CRM envelope. Money is a 6-decimal string. Dates `Y-m-d`.

**`GET /fares`** (`site.view`; own scope sees only rows whose `site_id` is one of the user's sites). Query: `residence_id`, `site_id`, `date`, `manager_id` (uuid; that team lead's sites), `changed_since` (Y-m-d; `updated_at` on or after that Cairo date), `per_page` (max 100). Paginated collection; each row:
```json
{ "id": "…", "residence_id": "…", "site_id": "…", "effective_from": "2026-11-06", "amount": "35.000000",
  "created_at": "…", "updated_at": "…",
  "set_by": {"id": "…", "name": "…"} | null,
  "previous_amount": "30.000000" | null }
```
`set_by` is the row's **last writer** (`updated_by`; equals `created_by` until an in-place update), null = «من الإكسيل». `previous_amount` = amount of the same pair's row with the latest earlier `effective_from`, else null.

**`POST /fares`** (`fare.manage`) body `{residence_id, site_id, effective_from, amount}` → `201 {data: <fare row without set_by/previous_amount>}`. Same live (residence, site, effective_from) → updates the amount, still 201.

**`POST /employees/{employee}/fare-overrides`** (`fare.manage` + `employee.scope`) body `{site_id, effective_from, amount}` → `201 {data: <override row>}`; same-key → update, 201.

Write refusals (both POSTs): `422` with `error.details[].field` = `site_id` (own scope, not one of the user's sites) or `effective_from` (own scope before yesterday Cairo; or any scope inside a locked attendance period). `404` when an own-scope user does not reach the employee today (middleware) or on `effective_from` (service).

**`GET /team-fares?date=&manager_id=`** (`fare.manage`). Own scope: the user's team, `manager_id` ignored. All scope: `manager_id` required (422 otherwise). `date` defaults to today (Cairo). Single envelope, unpaginated:
```json
{ "data": {
    "sites": [{"id": "…", "name": "…"}],
    "rows": [{ "employee_id": "…", "code": 123, "name": "…", "type": "permanent_worker|day_laborer|engineer",
               "residence": {"id": "…", "name": "…"} | null,
               "site": {"id": "…", "name": "…"} | null,
               "amount": "35.000000" | null,
               "source": "override|default|none|no_residence",
               "default_amount": "35.000000" | null,
               "overrides": [{"site_id": "…", "amount": "…"}] }] },
  "meta": {"request_id": "…"} }
```
`sites` and `rows` sit together in `data` (adjusted from the spec's "alongside": `useApi` returns only `data`). Names are `name_ar`. Rows by `code`. Workers: `site` = home site on the date, `amount` null unless `source` is `override` or `default`, `overrides` `[]`. Engineers: `site` null, `amount` null, `source` `none`, `default_amount` null, `overrides` = their overrides in force on the date at the manager's sites.

**`GET /employees/{employee}/fare`** (unchanged route) now answers `{amount, source, default_amount}` with the new sources.

**`GET /sites/manager-candidates`** (existing, `site.assign_manager`) → `{data: [{id, name}]}`: the team-lead list for HR's filters.

## Global Constraints

- Permission names: `fare.manage.all` (every role holding `site.manage.all` when the migration runs: developer, HR), `fare.manage.own` (`manager`). Check by name via `permission:fare.manage` + `RequestScope::ownerId`, never by role.
- "Own sites" = `SiteService::idsManagedBy($userId)` (undated `manager_sites`).
- Date floor (own scope only): `effective_from >= now('Africa/Cairo')->subDay()->toDateString()`. No ceiling.
- Notification type `fare_changed`; date = today Cairo; ref = written row id; data `{kind: 'fare'|'override', site_id, residence_id|employee_id, effective_from, old_amount, new_amount}`; only when the writer is own scope; recipients `PermissionHolders::userIds('fare.manage.all')`.
- Money stays bcmath strings; no floats. UI strings are i18n keys in `ar.json` and `en.json`; Arabic copy exactly as listed in Task B1.

## Review Focus

1. HR's «اتغيرت آخر ٧ أيام» must show a team lead's change dated tomorrow: with that chip on, the list asks `changed_since` **without** `date` (a `date=today` list hides future-dated rows). Test in B4.
2. A terminated or not-yet-hired worker still homed on the lead's site must not appear on «مواصلات التيم» (`EmployeeReach` checks type and site, not employment). Test in A6.
3. An own-scope `manager_id` on `GET /fares` must never widen the result (intersects with own sites). Test in A2.
4. A worker moved to the lead's site today: an override dated yesterday is refused (reach checked on `effective_from`, not only today). Test in A4.
5. The importer must still refuse a repeated (residence, site, date) row; only the API path updates in place. `ImportMasterDataTest::repeated_names_and_dates_are_row_errors` must stay green (A4 runs it).

## Spec statements the code contradicts (flagged, handled as stated)

- **«من الإكسيل» = `created_by` null** — false for real data: `hrms:import-master` runs `Auth::setUser($actor)` with the `--as` HR account (`ImportMasterDataCommand.php:55`), so imported rows carry that account. Plan: `set_by` null still renders «من الإكسيل», but the imported rows on the VPS will show the importing HR account's name. A one-off data fix (null `created_by/updated_by` on the import's rows) is the owner's call; not in this plan.
- **"same key updates instead of failing"** would break the importer's duplicate-row check (`MasterDataImport` calls `FareService::addFare/addOverride` and its test expects `fares row 3 --from`). Plan: new `saveFare/saveOverride` for the API; `addFare/addOverride` stay strict.
- **`set_by` from `created_by`**: an in-place update keeps `created_by` and moves `updated_by`; the plan uses `updated_by` so "who set it" names the last writer.
- **Reach on `effective_from`**: the `employee.scope` middleware checks reach **today** only; the spec's reach on `effective_from` is added in `saveOverride` on top.
- Existing tests encode the old behaviour and change in this plan: `SitesApiTest` duplicates (→ update), `test_employee_without_residence_gets_none` (day laborer → `no_residence`), `RbacTest::test_seeded_matrix` counts, and the Settings grid tests in `settings.test.tsx` (grid replaced by the combined list; the CEO, who held only `site.view.all`, loses the Fares tab).

---

# Part A — backend and docs

Test file for new behaviour: `backend/tests/Feature/TeamFaresTest.php` (`RefreshDatabase`, `AttendanceFixtures`; `cairo('2026-11-05 10:00')` in `setUp`). Build data with the fixtures (`workSite`, `employee($type, '2026-01-01', ['residence_id' => …])`, `managerOf`, `homeSite`), `Residence::create(['name_ar' => 'R-1'])`, `Fare::create([...])`, and `app(EmployeeService::class)->assignManager($id, ['manager_id' => $m->id, 'effective_from' => '2026-01-01'])` for engineers. Sign in with `$this->actingAs($user, 'api')` / `$this->signIn('hr')`.

### Task A1: `fare.manage` permission

**Files:**
- Create: `backend/database/migrations/2026_11_05_000001_add_fare_manage_permission.php`
- Modify: `backend/tests/Feature/RbacTest.php` (`test_seeded_matrix`)

**Interfaces:** Produces the triples `fare.manage.all` and `fare.manage.own` in `permissions`.

- [ ] **Step 1: Update the failing matrix test.** In `test_seeded_matrix`: `Permission::count()` 34 → 36; manager grants become `['attendance.record.own', 'employee.view.own', 'fare.manage.own', 'leave.request.own', 'leave.view.own', 'site.view.own']`; `assertCount(24, $grants('hr'))` and add `'fare.manage.all'` to the HR `assertContains` list; developer 28 → 29; the exact CEO list stays unchanged.
- [ ] **Step 2: Run** `RUN php artisan test --filter=test_seeded_matrix` → FAIL (count 34).
- [ ] **Step 3: Write the migration** after the pattern of `2026_11_03_000001_add_employee_edit_code_permission.php`: insert both permission rows; grant `fare.manage.all` to every role id that holds the live `site.manage.all` (join `role_permissions` × `permissions`, not hard-coded slugs), `fare.manage.own` to the role with slug `manager`. `down()` deletes the two permission rows (cascade drops grants).
- [ ] **Step 4: Run** `RUN php artisan test --filter=RbacTest` → PASS.
- [ ] **Step 5: Commit** `git add backend/database/migrations/2026_11_05_000001_add_fare_manage_permission.php backend/tests/Feature/RbacTest.php && git commit -m "Fares: fare.manage permission (all: developer, HR; own: team lead)"`. Deploy note for the PR: run `php artisan cache:clear` after `migrate` (role grants are cached).

### Task A2: `GET /fares` scoping, filters, `set_by`, `previous_amount`

**Files:**
- Modify: `backend/app/Modules/Sites/Application/FareService.php` (`listFares`; constructor gains `SiteService $sites`)
- Modify: `backend/app/Modules/Sites/Presentation/FareController.php` (`index`)
- Test: `backend/tests/Feature/TeamFaresTest.php`

**Interfaces:**
- Produces: `FareService::listFares(array{residence_id?: string, site_id?: string, date?: string, manager_id?: string, changed_since?: string, per_page?: int} $filters, ?string $ownerId = null): array{data: list<array<string,mixed>>, pagination: array}`; rows as in the contract.

- [ ] **Step 1: Write failing tests**
  - `test_a_team_lead_reads_only_fares_of_their_sites`: fares on s1 and s2; `managerOf(s1)` → `GET /fares` count 1, `data.0.site_id` = s1; `GET /fares?manager_id={other lead of s2}` as that same lead → count 0 (Review Focus 3).
  - `test_hr_filters_fares_by_team_lead`: HR `GET /fares?manager_id={lead of s1}` → only s1 rows.
  - `test_fares_carry_set_by_and_previous_amount`: pair (r, s1) rows 2026-10-01 amount 30 (then `DB::table('fares')->update(['created_by' => null, 'updated_by' => null])` for that id) and 2026-11-01 amount 35 created by HR → `GET /fares?date=2026-11-05`: `set_by.id` = HR id, `set_by.name` = HR name, `previous_amount` = `'30.000000'`; `GET /fares?date=2026-10-15`: `set_by` null, `previous_amount` null.
  - `test_changed_since_is_a_cairo_date`: one row created at `cairo('2026-10-20 10:00')`, another at `cairo('2026-11-05 00:30')` (22:30 UTC the day before) → `?changed_since=2026-11-05` returns only the second.
- [ ] **Step 2: Run** `RUN php artisan test --filter=TeamFaresTest` → FAIL.
- [ ] **Step 3: Implement.** Validate `manager_id` (`uuid`) and `changed_since` (`date_format:Y-m-d`) in `index`; pass `RequestScope::ownerId($request)`. In `listFares`: own scope → `whereIn('site_id', $this->sites->idsManagedBy($ownerId))`; `manager_id` → same with that id (both apply); `changed_since` → `where('updated_at', '>=', CarbonImmutable::parse($d, 'Africa/Cairo')->startOfDay()->utc())`. Select `fares.*` plus `fares.updated_by as set_by_id`, `(select u.name from users u where u.id = fares.updated_by) as set_by_name`, and `previous_amount` = `(select p.amount from fares p where p.residence_id = fares.residence_id and p.site_id = fares.site_id and p.deleted_at is null and p.effective_from < fares.effective_from order by p.effective_from desc limit 1)`. Map the page's `data`: `set_by` = `set_by_id === null ? null : ['id' => …, 'name' => set_by_name]`, drop the two helper keys.
- [ ] **Step 4: Run** `RUN php artisan test --filter='TeamFaresTest|SitesApiTest'` → PASS.
- [ ] **Step 5: Commit** `git add backend/app/Modules/Sites backend/tests/Feature/TeamFaresTest.php && git commit -m "Fares: GET /fares scoped to own sites, manager and changed-since filters, set_by and previous_amount"`.

### Task A3: `FareLookup` — engineers only by override, `no_residence`, `default_amount`

**Files:**
- Modify: `backend/app/Modules/Sites/Application/FareLookup.php`
- Modify: `backend/tests/Feature/SitesApiTest.php` (`test_employee_without_residence_gets_none` → `…_gets_no_residence`, asserts `no_residence`)
- Test: `backend/tests/Feature/TeamFaresTest.php`

**Interfaces:**
- Produces: `FareLookup::describe(string $employeeId, string $siteId, string $date): array{amount: string, source: 'override'|'default'|'none'|'no_residence', default_amount: ?string}`; `for()` unchanged.

- [ ] **Step 1: Write failing tests** (`GET /employees/{id}/fare?site_id=&date=2026-11-05` as HR):
  - `test_an_engineer_never_takes_the_residence_default`: engineer with residence r, fare (r, s1) 40 → `source` `none`, `amount` `'0.000000'`, `default_amount` null; after an override 25 → `override`, `'25.000000'`.
  - `test_a_worker_without_residence_is_no_residence`: day laborer, no residence → `no_residence`, `'0.000000'`.
  - `test_default_amount_is_the_residence_site_fare`: permanent worker with r, fare 35, override 30 → `override`, `default_amount` `'35.000000'`.
- [ ] **Step 2: Run** `RUN php artisan test --filter=TeamFaresTest` → FAIL.
- [ ] **Step 3: Implement.** Load the employee with `selectRaw(Employee::TYPE_ON.' as type_on', [$date])`. Default applies only when `type_on` is `permanent_worker` or `day_laborer` and `residence_id` is set. Source order: override → default → (`no_residence` when PW/DL without residence) → `none`. `default_amount` = the default lookup result (null when it does not apply). `amount` stays `Fare::resolve($override, $default)`.
- [ ] **Step 4: Run** `RUN php artisan test --filter='TeamFaresTest|SitesApiTest|ManagerScopeTest'` → PASS.
- [ ] **Step 5: Commit** `git add backend/app/Modules/Sites/Application/FareLookup.php backend/tests/Feature && git commit -m "Fares: engineers take only overrides; no_residence source; default_amount"`.

### Task A4: write rules, same-key update, audit (API path only)

**Files:**
- Modify: `backend/app/Modules/Sites/Application/FareService.php` (add `saveFare`, `saveOverride`; keep `addFare`, `addOverride` unchanged)
- Modify: `backend/app/Modules/Sites/Presentation/FareController.php` (`store`, `override`)
- Modify: `backend/routes/api.php` (`POST fares` → `permission:fare.manage`; `POST {employee}/fare-overrides` → `['permission:fare.manage', 'employee.scope']`)
- Modify: `backend/lang/ar/errors.php`, `backend/lang/en/errors.php`
- Modify: `backend/tests/Feature/SitesApiTest.php` (lines 123 and 165: duplicate now `assertCreated()` and the stored amount changes)
- Test: `backend/tests/Feature/TeamFaresTest.php`

**Interfaces:**
- Produces: `FareService::saveFare(array{residence_id: string, site_id: string, effective_from: string, amount: string} $data, ?string $ownerId): array<string,mixed>` and `FareService::saveOverride(string $employeeId, array{site_id: string, effective_from: string, amount: string} $data, ?string $ownerId): array<string,mixed>` (the row via `$this->row()`).
- Consumes: `SiteService::idsManagedBy`, `EmployeeReach::reaches`, `PeriodGuard::isLocked`, `Period::containing`, `Employee::TYPE_ON`.

- [ ] **Step 1: Write failing tests** (lead = `managerOf(s1)`; a permanent worker P homed on s1 from 2026-01-01):
  - `test_a_team_lead_writes_only_their_sites`: `POST /fares` on s2 → 422 `error.details.0.field` `site_id`; on s1 effective today → 201. Override for P with `site_id` s2 → 422 `site_id`.
  - `test_a_team_lead_cannot_date_before_yesterday`: `2026-11-03` → 422 `effective_from`; `2026-11-04` → 201; HR `2026-01-01` → 201.
  - `test_a_locked_period_refuses_every_scope`: HR `POST /attendance/locks {group: 'day_laborer', period_start: '2026-11-01', confirm_missing: true}`; HR `POST /fares` effective `2026-11-05` → 422 `effective_from` (either worker type locks a pair fare); an override for a day laborer on `2026-11-05` → 422; for P (permanent worker, open period) → 201.
  - `test_reach_is_checked_on_the_effective_date` (Review Focus 4): worker W homed on s2 until 2026-11-04, `homeSite(W, s1, '2026-11-05')`; lead posts W's override effective `2026-11-04` → 404; effective `2026-11-05` → 201. Employee outside reach entirely → 404.
  - `test_same_key_updates_in_place_and_is_audited`: HR posts (r, s1, 2026-11-05, 30) then (…, 35) → `Fare::count()` 1, amount `'35.000000'`; `audit_log` for that entity has `CREATED` (new_values amount 30) and `UPDATED` with `old_values.amount` `'30.000000'` and new amount equal to 35 by `bccomp(..., '35', 6) === 0`. Same for an override.
  - `test_a_manager_without_fare_manage_is_refused`: a role holding only `site.view.own` → `POST /fares` 403.
- [ ] **Step 2: Run** `RUN php artisan test --filter=TeamFaresTest` → FAIL.
- [ ] **Step 3: Implement.** One private `assertWritable(?string $ownerId, string $siteId, string $date, EmployeeType ...$types): void`: own scope → site in `idsManagedBy` else `ValidationException` `site_id` `errors.fare_site_not_yours`; own scope → date ≥ yesterday Cairo else `effective_from` `errors.fare_too_early`; every scope → any of `$types` with `PeriodGuard::isLocked(Period::containing($type, $date))` → `effective_from` `errors.fare_period_locked`. `saveFare` checks both worker types. `saveOverride`: `Employee::findOrFail`; own scope → `EmployeeReach::reaches($id, $ownerId, $date)` else `ModelNotFoundException`; type = `TYPE_ON` on the date (no type → skip the lock check). Write with `updateOrCreate($key, ['amount' => …])` inside `DB::transaction` (`Audited` records CREATED or UPDATED). `// ponytail: two first writes of one key in the same instant hit the unique index (500); lock the key if it ever happens`. Controller `store`/`override` call these with `RequestScope::ownerId($request)`. Lang keys (ar / en):
  - `fare_site_not_yours`: `هذا الموقع ليس من مواقعك.` / `This site is not one of your sites.`
  - `fare_too_early`: `لا يمكن أن يسري التعديل قبل أمس.` / `A change cannot take effect before yesterday.`
  - `fare_period_locked`: `هذا التاريخ داخل فترة حضور مقفلة.` / `This date is in a locked attendance period.`
- [ ] **Step 4: Run** `RUN php artisan test --filter='TeamFaresTest|SitesApiTest|ImportMasterDataTest|RbacTest|LocaleTest'` → PASS (Review Focus 5: the importer's duplicate-row test stays green).
- [ ] **Step 5: Commit** `git add backend/app/Modules/Sites backend/routes/api.php backend/lang backend/tests/Feature && git commit -m "Fares: team lead writes for own sites from yesterday, locked periods refused, same key updates"`.

### Task A5: `fare_changed` notification

**Files:**
- Modify: `backend/app/Modules/Sites/Application/FareService.php` (constructor gains `PermissionHolders`, `Notifier`)
- Test: `backend/tests/Feature/TeamFaresTest.php`

**Interfaces:** Consumes `Notifier::notifyOnce(string $userId, string $type, string $date, array $data, ?string $ref)`, `PermissionHolders::userIds('fare.manage.all')`.

- [ ] **Step 1: Write failing test** `test_a_team_lead_write_notifies_hr_once_per_row_per_day`: HR user H exists; fare (r, s1, 2026-10-01, 30); lead posts (r, s1, 2026-11-05, 35) → one `Notification` for H with `type` `fare_changed`, `date` `2026-11-05`, `ref` = new row id, `data` = `{kind: 'fare', site_id, residence_id, effective_from: '2026-11-05', old_amount: '30.000000', new_amount: '35.000000'}`; lead posts the same key with 40 → still one for H; HR posts another key → no new notification. Override variant: `kind` `override`, `employee_id`, `old_amount` null when no override was in force.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Before the write, read `old_amount` = amount of the row in force on `effective_from` for the same pair (fare) or same employee + site (override), else null. After the write, when `$ownerId !== null`, `notifyOnce` each `fare.manage.all` holder with today Cairo, ref = row id, the data above (`new_amount` = the saved row's amount). Inside the same transaction.
- [ ] **Step 4: Run** `RUN php artisan test --filter=TeamFaresTest` → PASS.
- [ ] **Step 5: Commit** `git add backend/app/Modules/Sites/Application/FareService.php backend/tests/Feature/TeamFaresTest.php && git commit -m "Fares: notify HR of every team-lead fare change (fare_changed)"`.

### Task A6: `GET /team-fares`

**Files:**
- Modify: `backend/app/Modules/Sites/Application/FareService.php` (add `team`; constructor gains `FareLookup`)
- Modify: `backend/app/Modules/Sites/Presentation/FareController.php` (add `team`)
- Modify: `backend/routes/api.php` (`Route::get('team-fares', [FareController::class, 'team'])->middleware('permission:fare.manage');`)
- Test: `backend/tests/Feature/TeamFaresTest.php`

**Interfaces:**
- Produces: `FareService::team(string $managerId, string $date): array{sites: list<array{id: string, name: string}>, rows: list<array<string, mixed>>}` (contract row shape); `FareController::team(Request $request): JsonResponse` → `ApiEnvelope::single($request, $result)`.

- [ ] **Step 1: Write failing test** `test_team_fares_lists_the_team`: lead of s1 (and s3); on s1: PW with r + fare 35 (`default`), DL with r + override 30 (`override`, `default_amount` per fare or null), DL with no residence (`no_residence`, `amount` null), PW with r2 and no fare (`none`, `amount` null); engineer assigned to the lead with override 50 at s1 and 60 at s2 (not the lead's); a PW homed on s2 (not in team); a PW homed on s1 with `termination_date` 2026-10-31 (Review Focus 2). Assert as lead: `data.sites` = s1, s3 by name; `data.rows` count 5 by code; engineer row `site` null, `amount` null, `source` `none`, `overrides` = `[{site_id: s1, amount: '50.000000'}]`; worker rows' `residence.name`, `site.name`, `amount`, `source`, `default_amount`. HR without `manager_id` → 422 `manager_id`; HR with `manager_id` = lead → same rows. Lead passing another lead's `manager_id` → still own team.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Controller: validate `date` (`sometimes`, `date_format:Y-m-d`) and `manager_id` (`Rule::requiredIf($ownerId === null)`, `uuid`); manager = `$ownerId ?? $data['manager_id']`. Service: `sites` from `Site::whereKey($this->sites->idsManagedBy($m))->orderBy('name_ar')`; employees = `EmployeeReach::apply(Employee::query()->select('employees.*')->selectRaw(TYPE_ON as type_on)->selectRaw(home site on date as home_site_id), $m, $date)` plus the employment window (`hire_date` null or ≤ date, `termination_date` null or ≥ date), `orderBy('code')`; residence and site names from one `whereKey` read each. Workers: `FareLookup::describe($id, $homeSite, $date)`. Engineers: overrides in force on the date per site among the manager's sites. `// ponytail: per-row lookups (~3 queries a worker); one joined query if a team passes a few hundred`.
- [ ] **Step 4: Run** `RUN php artisan test --filter='TeamFaresTest|RbacTest'` → PASS (the route-permission test covers the new route).
- [ ] **Step 5: Commit** `git add backend/app/Modules/Sites backend/routes/api.php backend/tests/Feature/TeamFaresTest.php && git commit -m "Fares: GET /team-fares lists a team lead's team with the fare in force"`.

### Task A7: docs

**Files:** `docs/calculations.md`, `CONTEXT.md`, `docs/hrms-module-plan.md`

- [ ] **Step 1: `calculations.md`.** §3 transport bullet (line ~122): append "The fare is set by the team lead for their sites or by HR (2026-11-05)." §4 Transport row: "fare: the worker's override for that site if set, else the residence × site fare, else 0 (no fare, or no residence). Engineers get transport only from an override, never the residence × site fare (owner default, 2026-11-05). The team lead sets fares for their sites; HR sets any. ⚠️ the October Excel pays 0 transport to every day laborer: the October fixture runs with no day-laborer fares, and the difference goes in the Excel-difference report." §8: add "Transport is a site-cost line from the same resolved fare (§4)."
- [ ] **Step 2: `CONTEXT.md` Fare entry:** add "Set by the team lead for their own sites, or by HR for any. The residence × site amount is the **default**; a per-worker **override** wins over it. Engineers get a fare only by override."
- [ ] **Step 3: `hrms-module-plan.md`:** under "Owner decisions of 2026-11-03 → Other decisions recorded" add a dated bullet: "**Transport fare (2026-11-05, option A):** the team lead sets fares for their sites (override per worker by default, or the residence × site default), applied at once; HR sees every change on Settings → Fares and gets `fare_changed`. The team lead never sets or sees wages. Spec: `docs/superpowers/specs/2026-11-05-team-lead-fares-design.md`."
- [ ] **Step 4: Commit** `git add docs/calculations.md CONTEXT.md docs/hrms-module-plan.md && git commit -m "Docs: team lead sets the fare; engineers only by override"`.

### Task A8: backend gate

- [ ] **Step 1:** `RUN vendor/bin/pint --test` → no files listed (fix with `vendor/bin/pint` on your files only, recommit).
- [ ] **Step 2:** `RUN vendor/bin/phpstan analyse` → `[OK] No errors`.
- [ ] **Step 3:** `RUN vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml` → 0 violations (Sites Application → Attendance Infrastructure/Domain and Employees Application are allowed layers).
- [ ] **Step 4:** `RUN php artisan test` → all green.

---

# Part B — frontend

Shared test helpers: `frontend/src/test-utils.tsx` (`mockFetch`, `json`, `renderWithAuth`, `renderApp`, `me`). Fake only the clock: `vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date('2026-11-05T10:00:00Z'))` (today `2026-11-05`, yesterday `2026-11-04`). Own scope permissions: `['fare.manage.own', 'site.view.own']`; HR: `['fare.manage.all', 'site.view.all', 'site.manage.all', 'site.assign_manager.all']`.

Types (top of `frontend/src/pages/team-fares.tsx`, exported for the tab):
```ts
export type Ref = { id: string; name: string }
export type TeamFareRow = { employee_id: string; code: number; name: string; type: 'engineer' | 'permanent_worker' | 'day_laborer'; residence: Ref | null; site: Ref | null; amount: string | null; source: 'override' | 'default' | 'none' | 'no_residence'; default_amount: string | null; overrides: { site_id: string; amount: string }[] }
export type TeamFares = { sites: Ref[]; rows: TeamFareRow[] }
```

### Task B1: locale keys

**Files:** `frontend/src/locales/ar.json`, `frontend/src/locales/en.json`

- [ ] **Step 1: Add the keys** (the existing parity test is the check). Arabic exactly:

| key | ar | en |
|---|---|---|
| `nav.teamFares` | مواصلات التيم | Team transport |
| `teamFare.title` | مواصلات التيم | Team transport |
| `teamFare.subtitle` | المبالغ متحسبة تلقائي من سكن كل عامل وموقعه. عدّل اللي محتاج بس؛ التعديل يتحسب على طول وHR بتشوفه. | Amounts come from each worker's residence and site. Edit only what needs it; a change applies at once and HR sees it. |
| `teamFare.all` | كل التيم ({{count}}) | Whole team ({{count}}) |
| `teamFare.missing` | من غير مبلغ ({{count}}) | No amount ({{count}}) |
| `teamFare.col.worker` / `.residence` / `.site` / `.fare` / `.source` | العامل / السكن / الموقع / المواصلات اليومية / المصدر | Worker / Residence / Site / Daily fare / Source |
| `teamFare.noResidence` | السكن مش متسجل | No residence recorded |
| `teamFare.byDay` | حسب اليوم | Per day |
| `teamFare.source.default` | تلقائي | Automatic |
| `teamFare.source.override` | معدّل للعامل | Set for this worker |
| `teamFare.source.none` | مفيش مبلغ للسكن ده | No fare for this residence |
| `teamFare.source.no_residence` | اكتب المبلغ أو اطلب من HR السكن | Enter the amount or ask HR for the residence |
| `teamFare.source.engineer` | المهندس بيتحدد له بالاسم | Engineers are set by name |
| `teamFare.edit` | تعديل مواصلات {{name}} | Edit transport for {{name}} |
| `teamFare.auto` | التلقائي {{amount}} | Automatic {{amount}} |
| `teamFare.amount` | المبلغ اليومي | Daily amount |
| `teamFare.from` | يسري من | Effective from |
| `teamFare.scope.worker` | للعامل ده بس | This worker only |
| `teamFare.scope.pair` | لكل اللي ساكنين في {{residence}} وبيشتغلوا في {{site}} ({{count}} عمال) | Everyone living in {{residence}} and working at {{site}} ({{count}} workers) |
| `teamFare.pickLead` | اختر مسؤول التيم | Choose a team lead |
| `teamFare.empty` | مفيش عمال في التيم النهارده. | No workers on the team today. |
| `fare.combinedTitle` | مواصلات مسؤولي التيم | Team leads' transport fares |
| `fare.combinedDesc` | كل المبالغ اللي حطها مسؤولي التيم. أي خانة تقدر تعدّلها. | Every amount the team leads set. You can edit any row. |
| `fare.site` | الموقع | Site |
| `fare.was` | كان {{amount}} | was {{amount}} |
| `fare.setBy` | حدده | Set by |
| `fare.fromExcel` | من الإكسيل | From Excel |
| `fare.allLeads` | الكل | All |
| `fare.lead` | مسؤول التيم | Team lead |
| `fare.recent` | اتغيرت آخر ٧ أيام | Changed in the last 7 days |
| `fare.add` | إضافة | Add |
| `fare.edit` | تعديل | Edit |

- [ ] **Step 2: Run** `npx vitest run src/locales/locales.test.ts` → PASS.
- [ ] **Step 3: Commit** `git add frontend/src/locales && git commit -m "Fares UI: ar/en keys for team transport and the combined fares list"`.

### Task B2: «مواصلات التيم» page — list, chips, route, guard, team-lead picker

**Files:**
- Create: `frontend/src/pages/team-fares.tsx` (exports `TeamFaresPage`, the types above)
- Modify: `frontend/src/App.tsx` (lazy `TeamFaresPage`, `<Route path="team-fares" …/>`)
- Test: `frontend/src/pages/team-fares.test.tsx`

**Interfaces:** Consumes `GET /team-fares` (contract). `TeamFaresPage(): JSX.Element`.

- [ ] **Step 1: Write failing tests** (fixture: sites s1 «العامرية», s2 «برج العرب»; rows: default 35 at s1, override 30 at s1 with `default_amount` 35, `none` at s2, `no_residence` at s1, engineer with `overrides: []`):
  - `lists the team with each source label`: all five Arabic source labels, «السكن مش متسجل», «حسب اليوم», and `—` for null amounts are on screen; the URL asked is `/api/v1/team-fares` with no `manager_id`.
  - `chips count and filter the rows`: buttons «كل التيم (5)», «العامرية», «برج العرب», «من غير مبلغ (2)» (workers with `amount === null`; engineers not counted); clicking «برج العرب» leaves one row; «من غير مبلغ (2)» leaves two; chips have `aria-pressed`.
  - `a user without fare.manage gets not found`: `renderWithAuth(<TeamFaresPage />, ['site.view.own'])` → the not-found heading, no fetch to `/team-fares`.
  - `an all-scope user picks a team lead first`: HR perms; `GET /api/v1/sites/manager-candidates` → `[{id: 'm1', name: 'م. أحمد سامي'}]`; no `/team-fares` call until the «اختر مسؤول التيم» select is set to m1, then the call carries `manager_id=m1`.
  - `the route renders the page`: `renderApp('/team-fares')` with `/auth/me` granting `fare.manage.own` shows the «مواصلات التيم» heading.
- [ ] **Step 2: Run** `npx vitest run src/pages/team-fares.test.tsx` → FAIL.
- [ ] **Step 3: Implement.** Guard like `site-day.tsx` (`can('fare.manage')` else `<NotFoundPage />`). Own scope (`!can('fare.manage.all')`): `useApi<TeamFares>('/team-fares')`; all scope: `FilterSelect` over `useApi('/sites/manager-candidates')`, path null until chosen. `PageHeader` title/subtitle; chips as native `<button aria-pressed>`; table columns worker (name + `t(`enum.type.${type}`)`), residence or `teamFare.noResidence`, site or `teamFare.byDay` for engineers, `useMoney()(amount)` (engineers: their overrides as `siteName amount`, else `—`), source label (`teamFare.source.engineer` for engineers, else `teamFare.source.<source>`). Each row has an edit button (`teamFare.edit`) wired in B3. Empty team → `EmptyState` with `teamFare.empty`.
- [ ] **Step 4: Run** `npx vitest run src/pages/team-fares.test.tsx` → PASS.
- [ ] **Step 5: Commit** `git add frontend/src/pages/team-fares.tsx frontend/src/pages/team-fares.test.tsx frontend/src/App.tsx && git commit -m "Fares UI: team transport page with source labels and site chips"`.

### Task B3: edit dialog — override by default, pair option, engineer site, date floor

**Files:**
- Modify: `frontend/src/pages/team-fares.tsx` (a `TeamFareDialog` built on `FormDialog`)
- Test: `frontend/src/pages/team-fares.test.tsx`

**Interfaces:** Consumes `POST /employees/{id}/fare-overrides`, `POST /fares` (contract). Saves through `useSave()` with invalidate `['/team-fares', '/fares']`.

- [ ] **Step 1: Write failing tests**
  - `editing a worker posts an override by default`: open the override-30 row's edit; dialog title contains «تعديل مواصلات» + name + `·` + residence + `←` + site; «التلقائي 35.00» shown; «للعامل ده بس» checked; type 32, save → `POST /api/v1/employees/{id}/fare-overrides` body `{site_id: 's1', effective_from: '2026-11-05', amount: '32'}`.
  - `the pair option posts the residence × site fare`: choose «لكل اللي ساكنين في … وبيشتغلوا في العامرية (n عمال)» (n = team rows with that residence and site) → `POST /api/v1/fares` body `{residence_id, site_id: 's1', effective_from: '2026-11-05', amount}`.
  - `engineers pick a site and have no pair option`: engineer dialog has a site select with the two sites, no pair radio; save posts an override with the chosen `site_id`.
  - `a worker with no residence has no pair option`.
  - `the date cannot go before yesterday for a team lead`: the «يسري من» input has `min="2026-11-04"` and value `2026-11-05`; HR (all scope) gets no `min`.
  - `a 422 shows the server message`: `POST` answers 422 with `details: [{field: 'effective_from', …}]` → message shown under the date field.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** with `FormDialog` (`init` `{effective_from: cairoToday(), amount: '', scope: 'worker', site_id}`; `shown` `['effective_from', 'amount', 'site_id']`); scope as two native radios; site via `SelectField` for engineers only; yesterday = `cairoToday()` minus one day (`new Date(today + 'T00:00:00Z')`, subtract a day, `toISOString().slice(0, 10)`).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `git add frontend/src/pages/team-fares.tsx frontend/src/pages/team-fares.test.tsx && git commit -m "Fares UI: team lead edits a fare for one worker or the residence and site"`.

### Task B4: Settings → Fares combined list (HR)

**Files:**
- Modify: `frontend/src/pages/sites.tsx` (`FaresTab`, `FareDialog`; drop `inForce`)
- Modify: `frontend/src/pages/settings.tsx` (fares tab `ability: 'fare.manage.all'`)
- Modify: `frontend/src/pages/settings.test.tsx` (replace the grid tests at lines ~39–90, ~128–140, ~317–345; the old-route test's `me` gains `fare.manage.all`)

**Interfaces:** Consumes `GET /fares` (contract), `SITES`, `RESIDENCES`, `GET /sites/manager-candidates`, `POST /fares`.

- [ ] **Step 1: Write failing tests** (`MANAGE` becomes `['site.view.all', 'site.manage.all', 'fare.manage.all', 'site.assign_manager.all']`):
  - `fares list shows one row per pair with who set it`: `GET /fares` rows `[{…s1, r1, amount '35.000000', previous_amount '30.000000', effective_from '2026-11-06', set_by {name: 'م. أحمد سامي'}}, {…s2, r2, set_by null}]` → row 1 shows `fmt(35)`, «كان 30.00», `2026-11-06`, «م. أحمد سامي»; row 2 shows «من الإكسيل»; the list URL has `date=2026-11-05` and `per_page=100`.
  - `the team-lead filter asks by manager_id`: selecting the lead adds `manager_id=m1` to the `/fares` URL.
  - `the 7-day filter drops the date` (Review Focus 1): toggling «اتغيرت آخر ٧ أيام» requests `changed_since=2026-10-30` and no `date=`.
  - `editing a row posts a dated fare for its pair`: row «تعديل» → amount 40, date → `POST /api/v1/fares` body `{residence_id: 'r1', site_id: 's1', effective_from, amount: '40'}`.
  - `adding picks residence and site`: «إضافة» dialog has residence and site selects; save posts the picked pair.
  - `the fares tab needs fare.manage.all`: with `['site.view.all']` the «المواصلات» tab is absent.
  - Keep `fares list shows a notice when more than 100 fares exist` (`fare.tooMany`).
- [ ] **Step 2: Run** `npx vitest run src/pages/settings.test.tsx` → FAIL.
- [ ] **Step 3: Implement.** `FaresTab` = `ListCard` titled `fare.combinedTitle`/`fare.combinedDesc`, action «إضافة», filters (`FilterSelect` of leads with `fare.allLeads`, a `<button aria-pressed>` for `fare.recent`), list path `/fares?per_page=100&date=${today}[&manager_id]` or `/fares?per_page=100&changed_since=${today−6}[&manager_id]`; names joined from `SITES` and `RESIDENCES`; columns site, residence, amount (+ `fare.was` when `previous_amount`), effective from, set by (or `fare.fromExcel`), edit. `FareDialog` takes optional `residence`/`site`; without them it shows `SelectField`s for both. Client pagination with the existing `Pagination`/`paginate(…, PAGE)`.
- [ ] **Step 4: Run** `npx vitest run src/pages/settings.test.tsx src/pages/team-fares.test.tsx` → PASS.
- [ ] **Step 5: Commit** `git add frontend/src/pages/sites.tsx frontend/src/pages/settings.tsx frontend/src/pages/settings.test.tsx && git commit -m "Fares UI: combined fares list for HR with who set each row"`.

### Task B5: frontend gate

- [ ] **Step 1:** `npx vitest run` → all green.
- [ ] **Step 2:** `npx tsc -b` → no output.
- [ ] **Step 3:** `npm run lint` → 0 errors.

### Task B6: nav entry (separate commit; likely conflicts with PR #26)

**Files:** `frontend/src/components/layout/AppLayout.tsx`

- [ ] **Step 1:** In `NAV` group `nav.group.hr`, after the `/site-day` item: `{ path: '/team-fares', key: 'nav.teamFares', icon: Bus, abilities: ['fare.manage.own'] }` (import `Bus` from `lucide-react`). Own only: HR reaches the team view from the URL and edits on Settings → Fares.
- [ ] **Step 2:** `npx vitest run src/mobile.test.tsx src/pages/pages.test.tsx && npx tsc -b` → PASS.
- [ ] **Step 3: Commit** `git add frontend/src/components/layout/AppLayout.tsx && git commit -m "Fares UI: nav entry for team transport"`.

---

## Follow-ups (not in this plan)

1. Full/half/none per day in the site-day sheet (`SiteDay.php` FORMAT, `site-day-row.tsx`, `lib/site-day.ts`, `SiteDaySaveTest`), after Mohanad's #22/#24/#26 merge.
2. `NotificationBell.tsx` text for `fare_changed`.
3. Owner decision: null `created_by`/`updated_by` on the rows `hrms:import-master` wrote, so they read «من الإكسيل».

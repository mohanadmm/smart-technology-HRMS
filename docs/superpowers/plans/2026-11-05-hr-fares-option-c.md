# HR sets the transport fare per team lead (option C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework PR #27 so HR is the only writer of transport fares, from `/team-fares`, and the team lead reads that page.

**Architecture:** Mostly deletion on top of PR #27. `fare.manage` keeps only `all`. `GET /team-fares` moves to `employee.view`, so a team lead (`employee.view.own`) gets their own team. The writes lose every own-scope branch and the `fare_changed` notification. The page gains a one-field residence dialog that reuses `PATCH /employees/{id}`.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 17 / PHPUnit 12; React 19 + Vite + TS, vitest, react-i18next.

**Spec:** `docs/superpowers/specs/2026-11-05-team-lead-fares-design.md` (option C). The option A plan `2026-11-05-team-lead-fares.md` stays as history.

**Design record (pstack architect, 2026-11-05).** Two Opus runners converged. Base c2, cross-judged. Grafts from c1: the SQL line for databases that already ran migration 000001 (no rollback, which would re-run 000002 and clear `set_by`); the CEO `POST /fares` → 403 assertion; `assertOwnSite` stays a named method. Cut in the ponytail and karpathy pass: c2's "pair option is the default when the row has no amount" (the owner set «للعامل ده بس» as the default).

## Global Constraints

- Backend runner from the worktree root: `docker run --rm --network hrms_default -v "$PWD/backend":/var/www/html -w /var/www/html hrms-php <cmd>`. Tests use `hrms_test`. Never `docker compose exec app`, never the `hrms` DB, never the VPS.
- Do not touch `SiteDay.php`, `site-day-row.tsx`, `lib/site-day.ts`, `SiteDaySaveTest`, `NotificationBell.tsx`.
- `FareLookup`, `MasterDataImport`, migration `000002` and the `App.tsx` route are unchanged. No new table, permission, route, component file or abstraction.
- Permissions are checked by name; each UI control checks the permission of the route it calls.
- Every UI string is in `ar.json` and `en.json`, in Egyptian colloquial like the existing `teamFare.*` keys.
- The `Nav:` commit stays the last commit on the branch. Never commit `CLAUDE_HANDOFF.md`.

## Review Focus

1. A team lead calls `POST /fares` or `POST /employees/{id}/fare-overrides` directly → 403 (Task 1).
2. A team lead sends another lead's `manager_id` to `GET /team-fares` → still only their own team (Task 1).
3. The CEO calls `GET /team-fares` → 422 without `manager_id`, 200 with it, and `POST /fares` → 403. The page shows Not Found to the CEO (Tasks 1 and 3).
4. HR fills a residence whose residence × site pair has no fare → the row shows `none`, not an error. A bank-paid worker with no account → the dialog shows the `bank_account` 422 (Tasks 2 and 3).
5. HR backdates a write into a locked attendance period → 422 `effective_from`, nothing written (Task 2).

---

### Task 1: Backend (permission, routes, writes)

**Files:**
- `backend/database/migrations/2026_11_05_000001_add_fare_manage_permission.php`: insert `all` only, granted to `site.manage.all` holders. No `own` row and no manager grant. The comment says option C.
- `backend/routes/api.php`: `team-fares` → `permission:employee.view`.
- `backend/app/Modules/Sites/Presentation/FareController.php`: `store` calls `saveFare($data)` and `override` calls `saveOverride($employee, $data)`. Drop the `changed_since` rule.
- `backend/app/Modules/Sites/Application/FareService.php`:
  - The constructor becomes `(SiteService $sites, FareLookup $lookup)`.
  - `saveFare(array $data): array` and `saveOverride(string $employeeId, array $data): array` keep the transaction and `updateOrCreate`.
  - `assertWritable` becomes `assertUnlocked(string $date, EmployeeType ...$types): void`, with the lock check only.
  - Delete `notifyHr`, the reach check, the yesterday floor and the `$old` reads.
  - `listFares` loses the `previous_amount` select and the `changed_since` filter.
  - Keep `assertOwnSite` and `describe`.
- `backend/app/Modules/Notifications/Application/Notifier.php`: back to `main` (delete `notifyOrRefresh`).
- `backend/lang/{ar,en}/errors.php`: delete `fare_too_early`.
- Tests:
  - `TeamFaresTest`
  - `RbacTest:24`: 36 → 35; the manager list drops `fare.manage.own`.
  - `AdministrationTest` (~104): 36 → 35.
  - `SiteManagerRoleMigrationTest` (~31): 6 → 5.

- [ ] **Step 1: Tests first** (`TeamFaresTest`)
  - **Delete:**
    - `test_a_team_lead_writes_only_their_sites`
    - `test_a_team_lead_cannot_date_before_yesterday`
    - `test_reach_is_checked_on_the_effective_date`
    - `test_a_manager_without_fare_manage_is_refused`
    - `test_every_team_lead_write_notifies_hr`
    - `test_changed_since_is_a_cairo_date`
  - **Change:**
    - `test_fares_carry_set_by_and_previous_amount` → `test_fares_carry_set_by`. No row has a `previous_amount` key.
    - `test_a_locked_period_refuses_every_scope` → `test_a_locked_period_refuses_a_write`. HR, both write routes, 422 `effective_from`, nothing written. Plus an HR write 30 days back outside a lock → 201.
    - `test_team_fares_lists_the_team`, CEO block: 422 `manager_id` without it, 200 with it, and `POST /fares` → 403.
  - **New:**
    - `test_a_team_lead_reads_their_team_and_writes_nothing`. The lead sends `GET /team-fares?manager_id={other lead}` → 200 with their own rows. `POST /fares` → 403. `POST /employees/{own worker}/fare-overrides` → 403. No row written.
    - `test_hr_fills_a_residence_and_the_pair_fare_applies`. A worker with no residence at site S, and fare (R, S) = 35. The row starts as `no_residence`. HR sends `PATCH /employees/{id}` `{residence_id: R}` → 200. The row becomes `default` with `amount` `35.000000`. A second worker set to R2, with no (R2, S) fare, shows `none` with `amount` null.
    - `test_a_fare_write_notifies_nobody`. An HR fare and an HR override leave `Notification::count()` unchanged.
  - **Keep, as HR where a lead wrote:**
    - the same-key update and its audit before/after
    - the concurrent first write
    - «من الإكسيل»
    - the engineer default
    - the own-scope `GET /fares` and `GET /employees/{id}/fare`
- [ ] **Step 2:** Run `php artisan test --filter='TeamFaresTest|RbacTest|AdministrationTest|SiteManagerRoleMigrationTest'`. The new and changed tests fail.
- [ ] **Step 3:** Make the edits above.
- [ ] **Step 4:** Run the full `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` and `vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`. All green.
- [ ] **Step 5:** Commit `Fares: HR is the only writer; team leads read their team through employee.view`.

### Task 2: Docs (same owner as Task 1)

**Files:** `docs/calculations.md` §3 and §4, `CONTEXT.md` (Fare), and `docs/hrms-module-plan.md` (the decision line).

- [ ] Write: "HR sets every fare (the residence × site default, plus per-worker overrides). The team lead reads their own team's fares. Engineers get a fare only by override. A day laborer with no fare gets 0. 2026-11-05: option C replaces option A."
- [ ] Commit `Docs: HR sets every fare (option C)`.

### Task 3: Frontend

**Files:**
- `frontend/src/pages/team-fares.tsx` and its test.
- `frontend/src/pages/sites.tsx` (`FaresTab`) and `settings.test.tsx`.
- `frontend/src/components/form.tsx` and `frontend/src/locales/locales.test.ts`: back to `main` (`daysBefore` and the two `teamFare` exceptions go).
- `frontend/src/locales/{ar,en}.json`.
- `frontend/src/components/layout/AppLayout.tsx`: as a `fixup!` commit only.

**Gates in `TeamFaresPage`:**
- `edit = can('fare.manage')`
- `fillResidence = can('employee.edit')`
- `pick = can('employee.view.all')`
- Not found unless `edit || can('employee.view.own')`.
- `TeamTable({ team, edit, fillResidence })`: the `actions` column exists only when `edit`.
- Residence cell: the name, else the «سجّل السكن» button when `fillResidence`, else the danger badge «السكن مش متسجل».

**`ResidenceDialog({ row, ...dialog }: { row: TeamFareRow } & Controlled)`:**
- A `FormDialog` titled `teamFare.residenceTitle`.
- A `SelectField name="residence_id"` over `useList(RESIDENCES)`.
- `shown={['residence_id']}`, so the `bank_account` 422 shows in the banner.
- Submit: `send(`/employees/${row.employee_id}`, { method: 'PATCH', body: { residence_id } }, ['/team-fares', '/employees'])`.

**`TeamFareDialog`:** no `useAuth`, no `min` on the date. The scope still starts on `'worker'`.

**`FaresTab`:**
- Delete the `recent` state, the «اتغيرت آخر ٧ أيام» chip, `changed_since` and the «كان X» span.
- The query is always `{ per_page: '100', date: today, ...(lead && { manager_id: lead }) }`.

**Nav:** `abilities: ['fare.manage', 'employee.view.own']`, in a `fixup! Nav: team transport entry for team leads` commit.

**Copy (ar):**

| Key | Text |
|---|---|
| `teamFare.subtitle` | اختار مسؤول التيم، سجّل السكن الناقص، وحدد المواصلات لكل سكن في كل موقع. |
| `teamFare.subtitleView` | مواصلات عمال التيم حسب السكن والموقع. |
| `teamFare.source.no_residence` | مستني السكن |
| `teamFare.setResidence` | سجّل السكن |
| `teamFare.residenceTitle` | سكن {{name}} |
| `fare.combinedTitle` | مواصلات السكن والمواقع |
| `fare.combinedDesc` | مبلغ كل سكن في كل موقع، ومين حدده. |
| `fare.combinedDescView` | مبلغ كل سكن في كل موقع. |

Delete `fare.recent` and `fare.was`. English equivalents for every key.

- [ ] **Step 1: Tests first**
  - Permission sets: `LEAD = ['employee.view.own', 'site.view.own']`. `HR = ['fare.manage.all', 'employee.view.all', 'employee.edit.all', 'site.view.all', 'site.assign_manager.all']`.
  - Replace `a user without fare.manage gets not found` with `the CEO gets not found` (`['employee.view.all', 'site.view.all']`). No `/team-fares` request is made.
  - New `a team lead sees the team read-only`. Rows render. No «تعديل» and no «سجّل السكن». The badge «السكن مش متسجل» is shown.
  - New `HR fills a missing residence`. Click «سجّل السكن», pick, save → `PATCH /api/v1/employees/{id}` with body `{residence_id}`.
  - New `a residence 422 shows in the dialog`.
  - Delete `the date cannot go before yesterday for a team lead`.
  - The other tests run as HR.
  - `settings.test.tsx`: delete the recent-filter test and the `previous_amount` assertions. The list asks for `date=today`. `fare.manage.own` → `employee.view.own`.
- [ ] **Step 2:** `npx vitest run src/pages/team-fares.test.tsx src/pages/settings.test.tsx`. The new tests fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Full `npx vitest run`, `npx tsc -b`, `npm run lint`, `npm run build`. All green.
- [ ] **Step 5:** Commit `Fares UI: HR sets fares and residences per team lead; team leads read`. Make the nav change as the separate `fixup!` commit.

### Final (controller)

1. Run `GIT_SEQUENCE_EDITOR=: git rebase -i --autosquash a6995aa`. The Nav commit stays last. Reword it to `Nav: team transport for HR and team leads`.
2. On the throwaway `hrms_fares` DB:
   - `delete from permissions where resource='fare' and action='manage' and scope='own';`
   - `cache:clear` on its Redis indexes.
3. Run every gate again.
4. Take verify-hrms screenshots: HR at 1440 and 375 in Arabic, and the team lead read-only.
5. Push with `--force-with-lease`.
6. Update PR #27's title and body, including the SQL line for databases that ran the old migration.

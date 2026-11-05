# Team lead sets the transport fare (option A)

Date: 2026-11-05. Owner decision: option A (research: `docs/research/2026-11-05-team-lead-fares-options.html`, mockups 1 and 3a).
Owner of the touched files: Youssef (Sites & rates, `routes/api.php`, `App.tsx`, locales). Mohanad's files are out of scope (see Follow-ups).

## What changes for whom

- **Team lead** (role `manager`): a new page «مواصلات التيم» lists every worker on their team with the fare that applies today, filled in automatically from residence × site. They correct only what is wrong. A correction applies at once (no HR approval). By default it is an override for that one worker; they can opt in to change the residence × site fare instead. They never see or set wages.
- **HR**: Settings → Fares becomes a combined list of every residence × site fare, showing who set it and when, filterable by team lead and "changed in the last 7 days". HR can edit any row. Every team-lead write notifies HR at once (`fare_changed`) with the old and new amounts.
- **Fix**: today `GET /fares` (behind `site.view`) returns every fare to a manager. It now returns only the manager's own sites.

## Data shape

No new tables. The two effective-dated tables stay the source of truth, and `FareLookup` stays the one resolver:

- `fares` (residence × site × effective_from → amount): the default for workers.
- `employee_fare_overrides` (employee × site × effective_from → amount): the per-worker exception.
- `updated_by` on each row says who set it last (it equals `created_by` until an in-place update). A row with no writer is a starting value and is labelled «من الإكسيل». `hrms:import-master` signs in as an HR account, so a one-time migration (`2026_11_05_000002_mark_starting_fares_from_excel`) clears `created_by` and `updated_by` on every fare and override that existed before team leads could write: the Excel import's rows and HR's setup. From then on `MasterDataImport` (through `FareService::addFare` and `addOverride`) clears both columns on the fares and overrides it writes, so a fresh install's import reads «من الإكسيل» too. The CREATED audit entry keeps the real writer.

The resolved fare of an employee at a site on a date (`FareLookup::describe`):

| Employee | Result | `source` |
|---|---|---|
| any | override in force on the date | `override` |
| permanent worker, day laborer | else residence × site fare in force | `default` |
| permanent worker, day laborer, no `residence_id` | else nothing | `no_residence` |
| anyone | else nothing (amount 0) | `none` |

Engineers never take the residence × site default (owner default, 2026-11-05): only an override. Day laborers get transport only when a fare exists, else 0 (already true of `Fare::resolve`). The full/half/none day portion (`Fare::forDay`) is unchanged.

## Permission

New permission `fare.manage`, granted by migration:

- `fare.manage.all`: every role that holds `site.manage.all` today (developer, HR).
- `fare.manage.own`: `manager`. "Own" means the sites in `manager_sites` for that user (undated, as `EmployeeReach` P1).

Checked by name through `permission:fare.manage` and `RequestScope::ownerId`, never by role.

## API

| Route | Permission | Change |
|---|---|---|
| `GET /fares` | `site.view` | Own scope: only rows whose `site_id` is one of the user's sites. New filters `manager_id` (that manager's sites) and `changed_since` (Y-m-d; rows created or updated on or after that Cairo date). Each row adds `set_by` (`{id, name}` or null = from Excel) and `previous_amount` (the amount of the row for the same pair just before this one, or null). |
| `POST /fares` | `fare.manage` (was `site.manage`) | Rules below. A write with the same (residence, site, effective_from) as a live row updates its amount instead of failing. |
| `POST /employees/{employee}/fare-overrides` | `fare.manage` + `employee.scope` (was `employee.edit`) | Rules below. Same-key write updates the amount. |
| `GET /employees/{employee}/fare?site_id=&date=` | `employee.view` + `employee.scope` | Own scope: a `site_id` outside the user's sites is refused (422 `site_id`), as on the write routes. |
| `GET /team-fares?date=` | `fare.manage` | New. Own scope: the user's team. All scope: `manager_id` is required. `date` defaults to today (Cairo). Unpaginated by decision: a team is tens of rows (`ponytail:` note in `FareService::team` with the ceiling and the upgrade). |

### Write rules (both POST routes)

1. **Own sites.** Own scope: `site_id` must be one of the user's sites, else 422 `site_id`. For an override the employee must also be in the user's reach (`EmployeeReach::reaches`) on `effective_from`, else 404 (as `employee.scope`).
2. **Date floor.** Own scope: `effective_from` ≥ yesterday (Cairo), else 422 `effective_from`. All scope: no floor. No ceiling.
3. **Closed period.** Every scope: refuse (422 `effective_from`) when `effective_from` falls in a locked attendance period (`PeriodGuard::isLocked(Period::containing(type, date))`). For an override, the type is the employee's type on that date. For a residence × site fare, both worker types (permanent worker and day laborer) are checked.
4. **Engineers.** A residence × site fare never applies to an engineer. A team-lead write for an engineer is an override only; the client sends `site_id` (one of the team lead's sites).
5. **Audit.** Both models already use `Audited`. A new row records CREATED with its values. An in-place update records UPDATED with before and after. The tests assert both.
6. **Notify.** When the writer is own-scope, each holder of `fare.manage.all` gets `fare_changed` via `Notifier::notifyOnce`: date = today (Cairo), ref = the written row's id, data = `{kind: 'fare'|'override', site_id, residence_id|employee_id, effective_from, old_amount, new_amount}`. `old_amount` is what applied on `effective_from` before the write: for a residence × site fare, the pair's row in force (or null); for an override, the employee's resolved fare (`FareLookup::describe(...)['amount']`, so the default when no override was in force, 0 when nothing applied). Every team-lead write notifies: a second edit of the same row on the same day updates that notification's `new_amount` (the first `old_amount` stays) and marks it unread again (`Notifier::notifyOrRefresh`). The audit log holds every write. HR writes notify nobody.

### `GET /team-fares` row

```json
{ "employee_id": "…", "code": 123, "name": "…", "type": "permanent_worker",
  "residence": {"id": "…", "name": "…"} | null,
  "site": {"id": "…", "name": "…"} | null,
  "amount": "35.000000" | null, "source": "override|default|none|no_residence",
  "default_amount": "35.000000" | null,
  "overrides": [{"site_id": "…", "amount": "…"}] }
```

- Team = `EmployeeReach::apply(Employee::query(), managerId, date)`: workers whose home site on the date is one of the manager's sites, and engineers assigned to the manager.
- Workers: `site` = home site on the date; `amount`, `source` from `FareLookup::describe`; `default_amount` = the residence × site amount (the dialog shows «التلقائي»).
- Engineers: `site` null, `amount` null, `source` `none`; `overrides` lists their overrides in force on the date at the manager's sites.
- `sites` (the manager's sites, `{id, name}`) is returned alongside the rows for the filter chips and the engineer site picker. The list is unpaginated (a team is tens of rows; `ponytail:` note with the ceiling).

## Screens

### «مواصلات التيم» (new page `frontend/src/pages/team-fares.tsx`, route `/team-fares`)

Matches mockup 1:
- Header «مواصلات التيم», subtitle: the amounts come from each worker's residence and site; edit only what needs it; HR sees every change.
- Filter chips: «كل التيم (N)», one per site, «من غير مبلغ (N)».
- Table: worker (name + type), residence (or «السكن مش متسجل»), site (or «حسب اليوم» for engineers), daily fare (or «—»), source label: «تلقائي» (default), «معدّل للعامل» (override), «مفيش مبلغ للسكن ده» (none), «اكتب المبلغ أو اطلب من HR السكن» (no_residence), «المهندس بيتحدد له بالاسم» (engineer).
- Edit dialog «تعديل مواصلات {name} · {residence} ← {site}»: «التلقائي {default}», amount, «يسري من» (default today, min yesterday), scope radio: «للعامل ده بس» (default) or «لكل اللي ساكنين في {residence} وبيشتغلوا في {site} ({n} عمال)». The second option is hidden for engineers and for a worker with no residence. Engineers pick a site from the manager's sites.
- The nav link shows to `fare.manage.own` only. HR (`fare.manage.all`) reaches the page by URL and picks a team lead first. Not shown: «سجل التغييرات» (skipped; the audit log covers it).

### Settings → Fares (combined, HR)

Matches mockup 3a: one row per residence × site in force today. Columns: site, residence, amount (with «كان X» when `previous_amount` is set), effective from, set by (or «من الإكسيل»), edit. Filters: team lead (the `manager-candidates` list) and «اتغيرت آخر ٧ أيام». An «إضافة» button opens the same dialog with residence and site pickers for a pair that has no fare. The tab shows to `site.view.all` (the CEO reads it); edit and «إضافة» need `fare.manage.all`. «تصدير» is skipped.

## Docs (same PR)

- `calculations.md` §3 and §4: who sets the fare, engineers only by override, day laborers 0 without a fare, and the October fixture (0 day-laborer transport) runs with no day-laborer fares; the difference goes in the Excel-difference report.
- `CONTEXT.md` Fare: set by the team lead for their sites or by HR; override vs default.
- `hrms-module-plan.md`: the decision (2026-11-05, option A); the team lead sets fares but never sets or sees wages.

## Risks

- A same-key write is last-write-wins, also between two team leads sharing a site. The owner accepted this risk: there is no optimistic locking, and the combined list shows who set each row.
- `fare_changed` has no bell rendering until Mohanad's `NotificationBell.tsx` handles it; the row is stored and listed meanwhile.

## Follow-ups (after Mohanad's PRs #22, #24, #26 merge)

1. Full/half/none column in the site-day sheet: `Attendance/Application/SiteDay.php` FORMAT, `pages/site-day-row.tsx`, `lib/site-day.ts`, and `SiteDaySaveTest`'s assertion that transport is ignored. A worker with no fare can't be given one.
2. `NotificationBell.tsx` text for `fare_changed`.
3. The nav entry in `AppLayout.tsx`, if not taken here as a one-line separate commit (it conflicts with #26).

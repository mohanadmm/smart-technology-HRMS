# Release 1: manager attendance (design)

## Context
- Release 1c gave HR the attendance screens. Managers still have no way to record their people's days: today they phone or message the site's attendance, and HR types it in.
- This design lets a manager record their field employees' days on a phone at the site. HR keeps control of everything that changes pay.
- On 2026-10-21 the owner changed how people are tied to managers. A field employee belongs to a **site**, and a manager covers sites. That contradicts CLAUDE.md:22 ("each employee has exactly one manager") and comes first.
- The owner's answers are in the session ledger `.superpowers/sdd/manager-attendance/decisions.md`. The site model was tested in `blast-radius-site-vs-manager.md`, with a probe against real code.
- **(owner)** marks the owner's answer. **(my call)** marks a decision of mine that the owner can override.

## Goals
- A manager opens the app on their phone, picks their site, marks everyone in a few taps, and saves. «زي امبارح» makes an ordinary day almost one tap.
- HR sees what managers entered and approves or refuses whatever adds money: overtime and advances.
- A manager can never see or write anyone outside their sites on that date.

## Non-goals
- Office staff on the manager screen. HR handles them: attendance from the fingerprint import and HR, leave and requests entered by HR **(owner)**.
- Leave balances and leave requests (Release 1 Leave, blocked on client Q8–10).
- The full Release 3 request workflow. Only its first two types, overtime and advance, are built here (Part 3).
- CEO escalation, the paste-site-messages parser and the scanned paper sign-off.
- Showing any pay figure to a manager.

## Anti-goals (a failure even if it works)
- A manager writes, or even sees, an employee who is not on one of their sites on that date.
- Overtime or an advance reaches pay without HR approving it.
- The manager screen is slow or fiddly on a phone at a site, so managers go back to sending messages.

---

## Part 1: Employees belong to sites (replaces "one manager per employee")

**Rules**
- **Workers have a home site.** A worker (permanent worker or day laborer) has one **home site** at a time, effective-dated. Their dated manager assignment goes away **(owner)**.
- **Engineers float between their manager's sites (owner, 2026-10-22).** An engineer can be recorded at any site of their manager without an HR move.
  - Engineers keep the existing dated manager assignment (`employee_manager_assignments`). The code and screens for it already exist **(my call)**.
  - Their write guard: the day's site must be one of their manager's sites on that date.
  - Engineers appear in an «مهندسين» section on every site list of their manager. Once marked present at one site, the row shows that site on the others **(my call)**.
  - The worker rules below (moves, loans, home site) apply to workers only.
- **Only HR moves an employee between sites, and every move has a date (owner).** The move form has an optional "returns to the previous site on" date, which records the return move too. A one-day loan is then one action **(my call)**.
- A worker who helps at another site, even for one day, must be moved there first. B's manager cannot record anyone not assigned to B on that date **(owner)**.
- A manager reaches the workers whose home site on date D is one of their sites (`manager_sites`), plus the engineers assigned to them on date D. D is the date being read or written, not today. This fixes the open problem of reads using today's link (`EmployeeRoster.php:79` uses `now()`).
- A site may have more than one manager. Both see the site's people **(my call until the client answers; added to `docs/client-questions.md`)**. Two saves of the same person's day are guarded by the existing per-day `version`: the second save is told the day changed and must reload. This replaces the "last save wins" wording in the ledger.
- Office staff keep their dated department manager, for reference only. Nothing in this design reads it **(my call)**.
- **Write guard:** every manager write checks that the day's site is one of the manager's sites, and for a worker that it is also the worker's home site on that date. Today `AttendanceDays.php:29` only checks that the site exists, which is a known gap. It is closed here with a test.

**Data**
- A new table `employee_site_assignments`: employee, site, from date, closed by the next row. It mirrors the dated pattern of `employee_manager_assignments`.
- A new permission `employee.assign_site.all`, held by HR.
- Existing field manager assignments: dev data only. The server's test copy is reloaded from the staging sheet (below).

**Migration step 1 importer** (`hrms:import-master`, on `feature/migration-step-1`, not yet merged)
- It writes a manager per employee from the staging sheet. With this design it needs a **site** column for workers. The manager column stays for engineers and office staff.
- The server's test copy may be wiped and reloaded until HR approves it, so the change is just a column, made after that branch merges.

**Screens**
- **The employee page:** the manager section becomes a "home site" section for field types. It shows the current site, its history, and HR's "move to site" dialog with a date and an optional return date.
- **The site page:** the workers currently assigned. The site-managers dialog (`sites.tsx`) stays.

**Docs in the same PR:**
- ADR 0004 (employees belong to sites);
- CLAUDE.md "Users and permissions";
- CONTEXT.md: Manager, plus the new term **Home site** (الموقع الأساسي);
- the module plan.

## Part 2: The manager's day screen

**Look:** mockup A, chosen by the owner (`.superpowers/brainstorm/59350-1792630343/content/manager-day.html`). Phone-first. On a laptop the same list gets wider, with no separate layout **(owner)**. It follows the app's demo look, tokens, 44 px targets, Arabic (default) and English, light and dark.

**Top of the screen**
- The site picker, skipped when the manager has one site.
- Today's date in Cairo time.
- The «زي امبارح» button.

**One row per employee** assigned to the site today, with:
- name and type;
- the buttons **حاضر / غياب / مرضي**;
- «⋯» for the other leave day-types a manager may pick (rest, other). Friday and official holidays are derived, as in 1c.

Rules for the row:
- **Present** stores the chosen site as the day's site.
- A row that differs from yesterday gets an orange edge.
- **Tapping the name** opens the details panel (below).

**Bottom bar** (sticky): the counts (present, absent, sick, empty) and «حفظ اليوم». A save sends the whole site's changed rows at once, through the existing bulk path and its version guard.

**«زي امبارح» (owner)**
- One tap fills every row with yesterday's status, and the manager changes only the exceptions. The arrival and leave times are copied too **(my call)**.
- "Yesterday" means the employee's last day with a record, so Fridays and holidays are skipped **(my call)**.
- It fills only rows still empty today and never overwrites what is already entered **(my call)**.
- An employee with no earlier record stays empty **(my call)**.
- Nothing is saved until «حفظ اليوم».

**Details panel**
- **Arrival and leave times** for every field type, in 12-hour form as in the rest of the app **(owner)**.
  - Lateness and overtime are computed from the times against one **field shift** (08:00–16:00 from settings, with the existing per-employee override), for engineers, permanent workers and day laborers alike **(owner, 2026-10-22)**. *(Superseded 2026-11-04: the field shift is **09:00–16:00**, set by HR in Settings (R19, `docs/calculations.md` §2b).)* Each type keeps its own lateness multiplier.
  - HR can still correct any computed value through the override rule (CLAUDE.md).
  - Engineers' and day laborers' typed minutes remain for imported or historical days, so the October fixtures do not change.
  - This changes `DayRules` and `docs/calculations.md` §2 and §4, in the same PR.
  - Overtime becomes a request (Part 3).
- **Advance request:** an amount. It goes to HR as a request (Part 3).
- **Note.**
- **Not shown to the manager:** transport and outside Alexandria. HR enters them **(owner)**, and the manager's save never changes them. Transport keeps its default of full on a work day.

**What the manager may write**
- Today (Cairo), for employees on their sites today **(owner)**.
- Yesterday too, until 12:00 Cairo time, so a manager can add a leave time known only after saving or after midnight **(owner, 2026-10-22)**. These edits do not count as past-day edits for HR alerts.
- Never a signed period, never a locked period, and never a future day.
- The exception is a returned period (Part 4).

**Audit and notify:** every save is audited with before and after values. Routine saves reach HR in the daily digest (Part 5).

## Part 3: Overtime and advances need HR's approval

**Rules (owner)**
- No employee gets overtime unless HR accepts it.
- An advance the manager records goes to HR, who approves or refuses it.

**How**
- A small `requests` table: type (`overtime` | `advance`), employee, date, the value (minutes or amount), status (`pending` | `approved` | `refused`), who asked, who decided, when, and a refusal reason. It is the start of the Release 3 request workflow, which already lists advance, so Release 3 extends it rather than adding a second approval system **(my call)**.
- **Overtime:**
  - When the saved leave time passes the shift end, the computed overtime becomes a pending request automatically. The manager does nothing extra **(my call)**.
  - It counts only once approved, and a refusal makes it zero.
  - If the times change later, a changed amount goes back to pending **(my call)**.
- **What the gate covers (my call):**
  - Overtime that HR types, or that HR imports from Excel, counts as accepted by HR. No request is needed for it.
  - Only overtime that comes from a manager's times waits for approval.
  - The day's stored overtime is the approved figure. A manager's computed overtime lives only in its request until HR approves it, and is then written to the day, as advances are.
  - For a permanent worker whose times came from a manager, payroll counts the approved overtime (zero if none), never the overtime computed from the times.
- **Advance:**
  - The manager's amount becomes a pending request.
  - On approval it is written to the day's advance amount, as HR's own entry is today. The Release 2 ledger picks it up, where HR chooses one payment or installments.
- **HR's screen:** a «الطلبات» list of pending requests, filterable by type, site and date, with approve and refuse. Refusing needs a reason. The day editor shows a request's status next to its field.
- **Permissions:** `request.create.own` (manager) and `request.decide.all` (HR).
- **Notifications:** every new request notifies HR instantly (CLAUDE.md). Every decision notifies the manager who asked.

## Part 4: «تم التوقيع» and HR return

- **Signing:** at the end of an employee's period, the manager marks that employee's period **signed**. After that, only HR edits the period **(owner)**. Signing is allowed from the period's last day on **(my call)**.
- **Return:** HR can return a signed period to the manager with a note, and the manager is notified.
  - The period's past days then reopen for that manager, for that employee only, until they sign again **(my call, given to the owner)**.
  - Every past-day edit notifies HR instantly (CLAUDE.md: edits to past days).
- **The manager's period view:** each employee's period at a glance (days marked, empty days, pending requests), with «تم التوقيع» per employee.
- **Data:** a sign-off record per employee per period, holding who signed and when, plus who returned it, when, and the note.

## Part 5: Notifications and the skipped-day alert

- **HR is notified instantly** of requests, past-day edits in a returned period, and warnings. Routine saves go into one **daily digest** (CLAUDE.md).
- **Skipped day:** the existing 21:00 `attendance:alert-missing` job also tells HR which manager's site left work days empty, grouped by site.

---

## Slices (each ends in screenshots the owner approves)
1. **Sites own people:**
   - HR moves employees between sites, with the return date;
   - manager scope by home site on the date;
   - the write guard and its test;
   - ADR 0004 and the doc updates.
   - The manager sees the site's list, read-only.
2. **The day screen:** status buttons, times with computed lateness, «زي امبارح», the bulk save and the today-only rule.
3. **Requests:** overtime and advance requests, HR's «الطلبات» list, approve and refuse, and the instant notifications.
4. **Sign and return:** the period view, «تم التوقيع», HR return, past-day edits, the skipped-day alert and the daily digest.

Each slice carries PHPUnit tests for its permission and calculation logic (CLAUDE.md). The lateness and overtime formulas update `docs/calculations.md` in the same PR.

## Merge order
- `feature/migration-step-1` goes to review as its own PR **(owner, 2026-10-22)**. Slice 1's importer change (a site column for workers) lands after it merges.

## Left to the builder
- Table and column names, and whether `source` gets a `manager` value.
- How the details panel opens (sheet or inline expand), as long as it follows mockup A's row.
- Request list paging and filters, following the existing list pages.

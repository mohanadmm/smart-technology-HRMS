# Attendance entry: times, plain search, weekday headers (design)

Status: draft for the owner, 2026-11-02. Base: `.claude/ui-refresh/attendance-research.md` (main checkout) plus the owner's 11 final
decisions below. Paths under `frontend/src/` or `backend/` in the `HRMS-ui` worktree (`feature/ui-refresh`, main @ 7be93b9).
Previews: `.claude/ui-refresh/preview/04-day-sheet-times-375.png`, `05-period-grid-1440.png` (main checkout; sources
`after-sheet-times-375.html` (`?dialog`), `after-grid-days-{1440,375}.html`, `grid-days.{css,js}`, `render.cjs` `AFTER`). Wording marked
*(guess)* is mine and needs the owner's eye.

## Problem

1. Field rows on the day sheet have no times. Engineers already store `in_time`/`out_time` (`DayRules::FIELDS`) but the sheet never
   shows them; day laborers cannot store them at all; office rows need `9` `0` `0` + ص for every in-time.
2. «الإدخال السريع» looks like a search box, but Enter on `10-20` writes up to 500 rows (`MAX_BATCH`). The keyboard shortcuts
   (`/ J K ↑ ↓ P A S E Esc Ctrl+Z`) add a document listener, a row cursor and a shortcuts list nobody on a phone can use.
3. Only permanent workers get late/OT hints from their times (`EffectiveDays::hints()`); engineers and day laborers get none, so
   HR works every minute out by hand. The rule itself stays: OT = worked − `day_hours` (8), late = in − scheduled in
   (`DayRules::workerMinutes`, as the Excel). So the default 08:00–17:00 day suggests 60 OT minutes, as a hint, every day.
4. The time action sends `site_id: null` (`lib/day-sheet.ts:73-74`). It is only reachable from office rows today, so the bug is
   latent; it wipes a field row's site the day field rows get a time box.
5. The grid and the day strip use `Intl` `weekday: 'narrow'` (one letter: ح Sun vs خ Thu differ by one dot), and Arabic `short`
   returns the full name.

## Decisions (owner, final)

| # | Decision |
|---|---|
| 1 | The command bar goes; the existing `SearchInput` takes its place. Live filter on code, `name_ar`, `name_en`, department; Enter does nothing; Arabic folding أ/إ/آ→ا, ة→ه, ى→ي. |
| 2 | Every keyboard shortcut and the shortcuts list go. «لصق أرقام» (paste IDs) stays. |
| 3 | The existing 12-hour `TimeInput`, in the row, already filled. No native time input, chips or wheels. Defaults look like normal values. Save on blur + Undo toast. A late or OT hint shows whenever its minutes are above 0 (so a default 08:00–17:00 day shows «إضافي 60 د»). |
| 4 | A field employee (engineer, permanent_worker, day_laborer) marked present gets 08:00 → 17:00, filled by the server in the shared bulk write. Values are settings. Office unchanged. |
| 5 | OT counts after **8 hours worked** (`day_hours`, a setting) for every field type, as today and as the Excel. Lateness stays max(0, in − scheduled in). So the default 08:00–17:00 day suggests 60 OT minutes as a hint every default day (client question 19). |
| 6 | Engineers and day laborers get arrival/departure; their late/OT minutes are derived from the times as hints HR confirms or overrides. Stored minutes stay as they are. |
| 7 | Bulk bar «تعديل الأوقات» → one dialog (arrival, departure). Shared value pre-filled, else "mixed values"; blank = no change; rows not present are skipped with a count toast. The bulk bar sits outside the `overflow-hidden` Card so it sticks to the screen bottom. |
| 8 | Default site = last work site in the past 7 days (as today). None → empty, the user picks. Shown as a normal chip: tap to change one person; the bar's site picker for a selection. |
| 9 | Fix the `site_id: null` time action. |
| 10 | Grid: short day name above the date (i18n keys), ~56 px columns, horizontal scroll + sticky name column, scroll to today on load, today in the accent, weekly-off and holiday columns tinted. Same on the day strip. |
| 11 | No "edited time" marker; the audit log is enough. |

## Backend shape (slice S1)

**Settings: reuse, don't add.** `worker_shift_start`/`worker_shift_end` become the *field shift* for all three field types.
They already feed the permanent worker's `scheduled_in`/`scheduled_out`; making them the default arrival/departure and the
engineer/laborer schedule gives one pair for one meaning. The per-employee shift (`Employment::shiftOn`) still wins.
- New migration `database/migrations/2026_11_02_000001_set_field_shift_end.php`: `worker_shift_end` 16:00 → **17:00** only when the
  stored value is still `16:00` (an HR edit is left alone). Still needed: OT no longer reads it, but it is the default departure
  (`fieldShift()['end']`), so without it Present would fill 16:00. Write it through the `Setting` model, which is `Audited`, so the change
  lands in the audit log; then `BusinessRules::forgetCache()`. `down()` reverses it on the same condition.
- **No schema migration.** `attendance_days` already has `in_time`/`out_time` for every type (`2026_10_20_000001`); a day laborer
  only lacks them in `DayRules::FIELDS`.
- Labels: `settings.worker_shift_start/end` «بدء وردية العاملين بالمواقع» / «انتهاء وردية العاملين بالمواقع», "Field shift
  start/end" *(guess)*. `SettingController` keys unchanged.

**`app/Modules/Attendance/Domain/DayRules.php`**
- `FIELDS['day_laborer']` gains `in_time`, `out_time` (engineers already have them). The existing `out_before_in` check covers both.
- `workerMinutes(scheduledIn, in, out, dayHours)` is unchanged: late = max(0, in − scheduledIn); OT = max(0, worked − `day_hours`
  × 60), with `out < in` → out + 1440 for a night shift.

**`app/Modules/Attendance/Application/EffectiveDays.php`**
- Split `defaults()` into `fieldShift($employee, $date, $s): array{start, end}` (shift override ?? `worker_shift_*`) and the
  permanent worker's defaults built on it.
- `hints()`: the permanent-worker branch becomes the field branch for all three types (work site, both times present):
  `workerMinutes(scheduled_in ?? shift start, in, out, day_hours)` → `late_minutes`, `ot_minutes`. Office unchanged.
  For engineers and laborers these are **hints only**: the stored `late_minutes`/`ot_minutes` are untouched by any time write and
  remain what payroll reads (decision 6). The day editor already prints a hint under each time (`attendance-day.tsx:22-23`).

**`app/Modules/Attendance/Application/AttendanceDays.php`, `write()`**: after the P28 merge and the permanent worker's defaults:
```php
// The field shift on Present (calculations §13): bulk writes only; save() (PUT) stores exactly what it is sent.
if ($keepUnsent && $type !== EmployeeType::Office && $site !== null && ! $isLeave) {
    $shift = $this->days->fieldShift($employee, $date, $settings ?? $this->rules->all());
    $values['in_time'] ??= $shift['start'];
    $values['out_time'] ??= $shift['end'];
}
```
One place, so every way of marking present gets it: row Present, cohort sweep, «تسجيل الباقين», paste, the site chip, the bar's
site picker, the attendance template import (`writeCell`, also `$keepUnsent`), and the manager's day once its marking lands, as long
as it writes through `bulk()`. A sent time wins; a kept time (same kind of day, P28) wins; a change of kind drops the old times and
gets the defaults. The historical master import (`hrms:import-master`, `feature/migration-step-1`) does not call `writeCell`, so
historical rows stay as-is.

**`app/Modules/Attendance/Application/AttendanceGrid.php`, `sheet()`**: `days[]` items gain `off: bool` = the date is a weekly off
day or an official holiday (`AttendanceGrid::offDays()`). The strip needs it; the grid marks a column off from its rows on the
client (some row's day is derived, or stored with `source = 'derived'`), so `grid()` is unchanged.

**API / request rules.** `AttendanceDays::FORMAT` and `BULK_FORMAT` already accept `in_time`/`out_time` (`date_format:H:i`) on
every row; no rule changes. Visible changes: a day laborer's `fields` lists the times; engineer and laborer days carry `hints`;
a bulk Present returns days with times; `GET /attendance/sheet` `days[].off`.

## Frontend shape (slices S2a, S2b, S3)

**`lib/day-sheet.ts`** (+20 / −4)
- `fold(s)`: `asciiDigits`, lower case, أ/إ/آ→ا, ة→ه, ى→ي. `matches(r, q)`: `fold(q)` in `fold(code name_ar name_en department)`.
  `parseBatch`'s name branch calls `matches` (the paste dialog keeps `parseBatch` for IDs).
- `SheetAction` time: `{ kind: 'time'; in_time?: string; out_time?: string }`. `bulkRows` time: an office row sends
  `site_id: null` (office present); a field row sends **`site_id: d.site_id`** (decision 9) and is skipped when it has no stored
  work site; only the given fields are sent; a row whose values already match is left out.

**`pages/attendance-sheet.tsx`** (−≈100 / +≈35)
- Delete: `MARK`, `MOVE`, `KEYS`; `cursor` state, its effect and reset; the `onKey` ref, listener effect and the 36-line handler;
  `command()`, `commandId`, `commandRef`; the ScanBarcode input; the `BatchPreview` status line; the `timeTip` paragraph; the
  shortcuts `<ul>`; `aria-keyshortcuts` on Undo; the doc-comment keyboard lines.
- Add: `<SearchInput value={query} onChange={…} placeholder={t('attendance.sheet.search')} />` in the bar's place, «لصق أرقام»
  and Undo beside it; `shown` filters with `matches`. While a query is set, a polite line «٥ من ٤٨» *(guess)*.
- The bulk bar leaves `<Card className="overflow-hidden">` and becomes UI-2's `ActionBar`, a sibling after the Card inside the
  sheet's `space-y-4` root, so `sticky bottom-0` binds to the page scroll. It keeps Present/Absent/Sick, the site picker + Apply and
  Clear, and gains outline **«تعديل الأوقات»** (Clock) on field and office sheets. «تسجيل الباقين حاضرين» stays at the top of the card.
- `apply()`'s success toast gets `action: { label: t('common.undo'), onClick: undo }` *(the Undo button stays)*.
- Day strip: the `narrow` formatter → `t('weekdayShort.<name>')` **above** the number; buttons `min-w-11` → `min-w-14` so
  «أربعاء» fits; `off` days get `bg-muted/50`. Export `showDay` for the grid.

**`pages/attendance-sheet-rows.tsx`** (−≈10 / +≈45)
- Delete `cursor`, `here()`, `aria-current` and the ring.
- `InTime` → `TimeCell({ field, was, label, … })` (same blur/Escape/Enter contract, `onFocus` selects the digits, so `845` replaces
  `8:00`); `onCommit` sends `{ kind: 'time', [field]: v }`. Office rows keep the in-time only (decision 4: office unchanged).
- A present field row shows two `TimeCell`s, labelled with the existing `attendance.day.in_time`/`out_time`, pre-filled from the
  day, with the hint under the box when the server's hint is > 0: `attendance.sheet.lateBy` «متأخر ٤٥ د» (exists) and a new
  `overBy` «إضافي ٦٠ د» *(guess)*, warning tone + text. From xl the table gets «وقت الحضور» / «وقت الانصراف» columns.
- `SiteChip`: a `DropdownMenu` trigger (normal text, chevron) showing the day's site, or on a missing row the `last_site_id`, or
  «اختر موقعًا» (`bulk.pickSite`, exists) when there is none. Items are `DropdownMenuRadioItem`s of active work sites; a pick
  calls `onApply({ kind: 'site', siteId })` (a missing row becomes present with the default times). Replaces the muted «آخر موقع: …».

**`pages/attendance-sheet-command.tsx`** (+≈70): `TimesDialog` next to `PasteDialog` (no new file). Title «تعديل الأوقات», line
«{{count}} محددون. الحقل الفارغ لا يغيّر شيئًا.» *(guess)*. Two `TimeInput`s (`attendance.day.in_time`, `out_time`), each
pre-filled when every selected present row shares the value, else blank with «قيم مختلفة» under it *(guess wording)*. Footer:
solid «تطبيق», ghost «إلغاء»; Apply is disabled while both are blank or a box needs ص/م. Apply passes only the present rows
(`statusOf` present or late) to `apply({ kind: 'time', … })`; the rest give «تم تخطي {{count}} غير حاضرين» *(guess)*.

**`pages/attendance.tsx`, `Grid`** (−4 / +≈15): header cell = `weekdayShort` (12 px, muted) above the date (14 px semibold);
`min-w-14` on head and cells; today `bg-accent text-accent-foreground` + `aria-current="date"`; a column is off when some row's day is
derived or `source === 'derived'` → `bg-muted/50` on head and cells; the table wrapper scrolls today into view once on mount
through `showDay` (a module-level ref callback, as the strip does, so a re-render never scrolls back).

**`pages/attendance-day.tsx`**: no change; a laborer's times come from `fields`, the hints sit under them already.

**`locales/{ar,en}.json`**: delete `attendance.sheet.{command, commandHint, commandHelp, commandFind, timeTip, keys.*}` (12 strings
each; `matched`, `unknown`, `capped` stay for the paste dialog). Add `attendance.sheet.{search, found, overBy, bulk.times,
times.title, times.selected, times.mixed, toast.skippedNotPresent}`, `common.undo` «تراجع» / "Undo" (new), and `weekdayShort.{sunday…saturday}`:
ar أحد · إثنين · ثلاثاء · أربعاء · خميس · جمعة · سبت; en Sun · Mon · Tue · Wed · Thu · Fri · Sat. Search placeholder «ابحث
بالاسم أو الرقم الوظيفي» / "Search by name or employee ID".

## `docs/calculations.md` diff (S1 PR)

§2 Engineers, first line → *Each day records: site, arrival and departure, OT minutes, late minutes, minutes worked outside
Alexandria, advance taken.* New bullet after it:
> - Arrival/departure default to the field shift on Present (§13). They suggest late = max(0, arrival − shift start) and
>   OT = max(0, hours worked − **8** h) as hints, as for permanent workers (§3); the shift is the per-employee shift, else
>   **08:00–17:00** (`worker_shift_start`/`worker_shift_end`), so the default day suggests 60 OT minutes (client question 19).
>   HR confirms by entering the minutes; payroll reads only the stored minutes.

§3 Permanent workers: «default **08:00–16:00**» → «default **08:00–17:00**» (twice). The late/OT bullets are unchanged.

§4 Day laborers, first line → *Each day records a site, arrival and departure (§2 defaults and hints), OT and late minutes.*

§13, new bullets before "The sheet saves through bulk marking":
> - Present on a field row at a work site, through the bulk write (the sheet, the attendance import, the manager's day), stores
>   arrival = field shift start and departure = field shift end when the row sends no time and keeps none (P28). Office rows and
>   leave days get none. Single-day entry stores what it sends.
> - A date is off (tinted on the strip and the grid) when it is a weekly off day or an official holiday.

## `docs/client-questions.md` diff (S1 PR)

Under "Release 2: payroll", after 14 (numbers are ids, so 15–18 keep theirs):
```diff
+19. اليوم الافتراضي للمهندسين والعمال المثبتين وعمال اليومية من 8:00 ص إلى 5:00 م، والإضافي يبدأ بعد 8 ساعات عمل (كما في الإكسل)،
+    فيظهر ساعة إضافي كل يوم. هل هذا صحيح، أم أن فيه ساعة راحة غير مدفوعة؟
+    *(default: الإضافي بعد 8 ساعات عمل؛ دقائق المهندسين وعمال اليومية تبقى كما يكتبها الـHR)*
```

## October fixtures

**Not affected.** There is no payroll code yet (`backend/app/Modules` has no Payroll; 315,451 and 391,948 appear only in docs).
Both fixtures are engineers and day laborers, whose OT and late minutes stay the stored, typed values; times only add hints.
The OT and lateness rules do not change, so there is no new Excel difference.

## Tests

**PHPUnit (S1)**
- `tests/Feature/AttendanceBulkTest.php`: Present gives 08:00/17:00 to an engineer, a permanent worker and a day laborer (data
  provider); office gets none; a leave day gets none; a per-employee shift wins; a changed `worker_shift_end` moves the default (no
  literal); a sent `out_time` is kept and only `in_time` filled; a status change leave → work drops old times and fills defaults; a
  field row sent with `in_time` and `site_id: null` is refused `site_required` and nothing is written (the server side of the skip);
  `test_a_permanent_worker_marked_present_gets_shift_defaults_and_full_transport` expects 17:00.
- `tests/Feature/AttendanceDaysTest.php`: `save()` (PUT) fills no times; an engineer day 08:45–18:30 has hints late 45 / OT 105, and
  08:00–17:00 late 0 / OT 60, with its stored `late_minutes`/`ot_minutes` unchanged; a day laborer accepts times and `out_before_in` refuses 17:00 → 08:00.
- `tests/Unit/DayRulesTest.php`: keep the 4 `workerMinutes` cases (night shift and 07:50 → 0 late are among them); add 08:00 on
  08:00–17:00 → late 0 / OT 60 and 08:45–18:30 → late 45 / OT 105.
- `tests/Feature/AttendanceImportTest.php`: an imported work cell gets the default times.
- `tests/Feature/AttendanceSheetTest.php`: `days[].off` is true on a Friday and an official holiday, false on a worked day.
- `tests/Feature/SettingsApiTest.php`: `worker_shift_end` = 17:00; a migration test that an HR-edited 15:30 survives.

**Vitest**
- `lib/day-sheet.test.ts`: `fold`/`matches` (احمد ↔ أحمد, ايمان ↔ إيمان, امال ↔ آمال, فاطمه ↔ فاطمة, مصطفي ↔ مصطفى, `١٢` ↔ 12, English
  case, department); `bulkRows` time keeps a field row's `site_id`, sends `null` for office, sends only `out_time`, skips a field row
  with no site, leaves out an unchanged row. Delete nothing in `parseBatch`'s ID tests.
- `pages/attendance-sheet.test.tsx`: typing filters live; Enter sends no request; P, J, `/` and Ctrl+Z do nothing; the bulk bar is not
  inside `.overflow-hidden` and has `sticky`; the times dialog pre-fills a shared departure, shows «قيم مختلفة» and leaves a blank
  arrival unsent, skips a missing row with the count toast, disables Apply while a box lacks ص/م; a field row's departure edit posts
  `out_time` with its `site_id`; the site chip posts the picked site; the strip shows «إثنين» above «2» and tints an off day.
  Delete the ~22 command/keyboard tests (≈290 lines: 352, 360, 602, 616, 680, 709, 760–1056 except the undo-stack ones, 1076,
  1166, 1297); keep the undo-stack tests, driving them through the Undo button.
- `pages/attendance.test.tsx`: the header shows «إثنين» then «2» (Arabic) and "Mon" then "2" (English); today has
  `aria-current="date"`; a Friday column's cells carry the tint; the scroller scrolls once to today and not again on re-render.

## Slices (each its own PR and screenshot gate, via verify-hrms at 375 / 1024 / 1440, ar + en, light + dark)

| Slice | Contents | Gate shots |
|---|---|---|
| **S1** backend times + defaults | migration, `DayRules`, `EffectiveDays`, `AttendanceDays::write()`, `sheet()` `off`, PHPUnit, the two doc diffs | day editor of an engineer and a day laborer marked present today: 8:00 ص / 5:00 م filled, a 8:45 edit shows its hint; Settings shows 17:00 |
| **S2a** sheet: search + shortcuts out + bar | `SearchInput`, deletions, `fold`/`matches`, `ActionBar` outside the Card | 375 / 1440: plain search with a typed «احمد» finding «أحمد»; bar stuck to the bottom with 2 selected |
| **S2b** sheet: times, bug, dialog, chip | `TimeCell`, `bulkRows` time fix, `TimesDialog`, `SiteChip`, Undo toast | the two frames of preview 04 in the real app, plus the xl table at 1440 |
| **S3** weekday headers | grid header, strip, `weekdayShort`, off tint, scroll to today | preview 05 in the real app, 375 inset, en "Mon" |

S2a and S2b need UI-2's `ActionBar`; S2b and S3's strip tint need S1 merged.

## Branch advice

- **S1 is backend in Youssef's Attendance module but not on `feature/ui-refresh`** (frontend-only so far). Cut
  `feature/attendance-times` from `main` once `feature/manager-attendance` merges, or from that branch if it is still open: it
  rewrites `AttendanceDays::write()`'s signature (`$ownerId`), `writeCell()` and `AttendanceImport.php`, so S1 written on main would
  conflict on the exact lines it edits.
- **S2a/S2b/S3 go on `feature/ui-refresh` as UI-3 work.** They rewrite the same files as UI-3's rows for `attendance-sheet.tsx`,
  `attendance-sheet-rows.tsx`, `attendance-sheet-command.tsx` and `attendance.tsx`; doing them separately means two rebases of
  the same lines. Update the UI-3 rows of `2026-11-02-ui-refresh-design.md`: the shortcut chips are deleted (not put behind
  `<details>`), and the rows keep no J/K cursor.
- **Mohanad's area:** none of these files. `pages/site-day.tsx` and `lib/site-day.ts` (manager branch) are read-only today and are
  not edited; they get the default times for free **if** the manager's marking writes through `AttendanceDays::bulk()`. Hand-over
  note for that slice: send work rows without times and let the server fill them; show the times from the day. `employees.tsx`
  and `settings.tsx` are untouched (only the two settings label strings change in the locales).

## Risks

- **A 1 h OT hint on every default day.** 08:00–17:00 is 9 h, so every default field day suggests 60 OT minutes. For permanent
  workers that hint is what payroll will pay unless HR overrides it; for engineers and laborers HR may stop trusting a hint that is
  always there. If HR answers question 19 with an unpaid break, the fix is a setting (`worker_shift_end` 16:00 or a break), not code.
- **Engineer/laborer hints move with the setting.** They have no stored schedule, so a later change of `worker_shift_*` changes past
  hints (display only; stored minutes and permanent workers' stored `scheduled_*` do not move).
- **Hints that nobody confirms.** Payroll reads the stored minutes, so an engineer's 90-minute hint pays nothing until HR enters it.
  Add a one-click «اعتماد» in the day editor if HR asks; not built now.
- **Phone width.** Two `TimeInput`s side by side need ≈ 2 × 152 px; at 375 px the card has 311 px. Below ≈ 350 px they wrap to one
  per line (`grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))]`); check at 320 px in the S2b gate.
- **Typing over a filled box.** `onFocus` select-all must survive the ص/م buttons' `onMouseDown` preventDefault (a click on ص/م must
  not re-select). Cover it in the S2b test.
- **Default fill on old rows.** A bulk write to a pre-change present row (e.g. a site change) fills its missing times, so one new
  version and audit entry per row. Intended, but the first site change after deploy writes more audit rows than usual.
- **Sticky bar inside a scrolled page.** `sticky` needs no `overflow` ancestor between the bar and the page scroller. Today
  `AppLayout`'s `<main id="page-content">` has none and the page scrolls; the Tabs content must not add one. Check the S2a gate on
  iOS Safari with the safe area.

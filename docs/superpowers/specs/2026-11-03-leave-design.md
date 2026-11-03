# Leave: balances, requests, approval

Status: **decisions approved**, 2026-11-03: the owner accepted every recommendation (L1–L7) and the builder's calls. L1–L3 stay ⚠️ in `docs/calculations.md` until the client answers questions 8–10. The owner approved the plan the same day (`docs/superpowers/plans/2026-11-03-leave.md`); building goes in two steps, each with a screenshot gate.
Scope: Release 1, item 6 in `docs/hrms-module-plan.md`: "balances and rules above, recorded by the manager, HR approves or escalates."

---

## الجزء 1 — للمالك (بالعربي)

### إيه اللي هيتعمل
**الفكرة الأساسية:** الإجازة تتسجل في الحضور نفسه، والرصيد بيتحسب من الحضور. يعني يوم الإجازة الاعتيادية بيبقى خانة في كشف الحضور زي «اجازة مرضي» بالظبط، والنظام يعدّ الأيام دي ويطرحها من الرصيد. مفيش رصيد منفصل ممكن يختلف عن الحضور.

1. **أنواع إجازات جديدة في الحضور:** «اعتيادية» و«عارضة» و«بدون أجر». الـHR تقدر تسجلها في كشف اليوم أو جدول الفترة أو ملف الإكسل زي أي نوع إجازة موجود.
2. **رصيد كل موظف للسنة:** الاستحقاق (10 أو 21)، المستخدم، المتبقي، العارضة المستخدمة من 6، وعدد أيام المرضي (للعلم فقط).
   - بيظهر في **تاب «الإجازات» في صفحة الموظف**، وفي **صفحة «الإجازات»** فيها جدول أرصدة كل الموظفين.
   - الرصيد بالسالب يظهر بالأحمر.
3. **طلب إجازة:** المدير يسجل طلب لموظف من موظفينه (من الموبايل)، والـHR تسجل لأي موظف (والموظفين المكتبيين للـHR بس).
   - الطلب: النوع، من يوم، إلى يوم، ملاحظة.
   - قبل الحفظ يبين: «4 أيام محسوبة (الجمعة والإجازات الرسمية مش محسوبة)، الرصيد بعدها 6».
4. **الموافقة:** الـHR توافق أو ترفض (بسبب) أو تحوّل الطلب للمدير التنفيذي، وهو يوافق أو يرفض.
   - عند الموافقة النظام يكتب أيام الإجازة في الحضور لوحده.
   - كل طلب من مدير يوصل للـHR إشعار فوراً، والتحويل يوصل للمدير التنفيذي، والقرار يرجع لصاحب الطلب.
5. **إلغاء إجازة موافق عليها:** الـHR بس. الأيام اللي اتكتبت في الحضور تتمسح، إلا لو الفترة مقفولة.
6. **تنبيه سنة الخدمة:** لما موظف يكمّل سنة خدمة في نص السنة، الـHR يوصلها إشعار في اليوم ده، وهي تقرر تزود استحقاقه لـ21 لباقي السنة أو لأ.
7. **عمال اليومية:** مالهمش رصيد ولا طلب إجازة. حضورهم يفضل زي ما هو (راحة، غ، مرضي).

**مش داخل في الخطوة دي:** نموذج «طلب إجازة» PDF (الإصدار 3)، والخصم الفعلي من المرتب (الإصدار 2، المرتبات). القواعد بتتكتب دلوقتي في `docs/calculations.md` عشان المرتبات تمشي عليها. وأنصاف الأيام مش مدعومة: الإجازة بالأيام الكاملة.

### الخطوات (كل خطوة تنتهي بصور للموافقة: موبايل 375 وكمبيوتر، عربي وإنجليزي)
- **الخطوة 1، الأرصدة:** أنواع الإجازة الجديدة في الحضور، حساب الرصيد، تاب «الإجازات» في صفحة الموظف، جدول الأرصدة، تعديل الـHR للاستحقاق والرصيد الافتتاحي، وتنبيه سنة الخدمة.
- **الخطوة 2، الطلبات:** طلب الإجازة من المدير والـHR، الموافقة والرفض والتحويل للمدير التنفيذي، كتابة الأيام في الحضور، الإشعارات، والإلغاء.

### القرارات (المالك، 2026-11-03: موافق على كل المقترحات)
القرارات L1–L3 أسئلة العميل (8–10): افتراضية وعليها ⚠️ لحد ما العميل يرد.

| # | السؤال | القرار |
|---|---|---|
| L1 | الإجازة المرضية: بأجر كامل ولا 75%؟ | **بأجر كامل**، زي الإكسل النهارده، ويبقى إعداد (نسبة أجر المرضي = 100) يتغير لـ75 من غير برمجة. |
| L2 | الأيام اللي فوق الرصيد؟ | الـHR تشوف تحذير وتقدر توافق. **الأيام الزيادة تتسجل «بدون أجر»** وتتخصم في المرتب زي الغياب، بس مش مكتوب عليها «غ». |
| L3 | الموظف الجديد ياخد الـ10 أيام كاملة؟ | **بالنسبة والتناسب:** 10 × الشهور الكاملة الباقية في السنة ÷ 12، مقربة لتحت. اتعين 1 يوليو → 5 أيام؛ اتعين في ديسمبر → صفر. |
| L4 | المرضي بيتخصم من الرصيد السنوي؟ | **لأ**، بيتعد لوحده للعلم ومش بيقلل الـ10/21. |
| L5 | مين يسجل الإجازة؟ | **المدير** لموظفينه (عمال ومهندسين) و**الـHR** لأي حد. المكتبيين للـHR بس. |
| L6 | الإجازة تتسجل في الحضور والرصيد يتحسب منه؟ | **أيوه** (الفكرة الأساسية فوق). |
| L7 | الرصيد الافتتاحي لسنة 2026 (الأيام اللي اتاخدت قبل النظام)؟ | خانة **«أيام مستخدمة قبل النظام»** لكل موظف لكل سنة، الـHR تكتبها لو محتاجة، وفاضية = صفر. من 2027 مش هتحتاجها. |

**قرارات فنية (وافق عليها المالك مع الباقي):**
- الجمعة (والسبت للمكتبيين) والإجازات الرسمية جوه فترة الإجازة **مش بتتحسب** من الرصيد، ومش بتتكتب في الحضور، لأنها بتطلع تلقائي.
- موظف من غير تاريخ تعيين: الرصيد يبين «سجّل تاريخ التعيين»، والموافقة مسموحة بتحذير.
- لو يوم في الإجازة متسجل فيه شغل أو في فترة مقفولة، الموافقة **تترفض** وتعرض الأيام دي، والـHR تصلحها الأول.
- طلبين متداخلين لنفس الموظف مرفوضين.
- الإجازة اللي بتعدي من ديسمبر ليناير تتسجل طلبين، واحد لكل سنة.
- الإجازة الموافق عليها بتظهر في الحضور **من قبل ميعادها** (أيام في المستقبل)، عشان الرصيد يبقى صح من لحظة الموافقة.
- المدير **مايقدرش** يكتب «اعتيادية» أو «عارضة» أو «بدون أجر» في كشف اليوم بنفسه؛ لازم طلب والـHR توافق. «اجازة مرضي» تفضل متاحة له زي النهارده.
- العارضة بتتخصم من الـ6 **ومن** الرصيد الكلي مع بعض.

---

## Part 2 — Technical design

### Today
- Leave day-types are `sites` rows with `kind=leave` and a `leave_code` (`Sites\Domain\LeaveCode`: friday, saturday, official_holiday, sick, rest, absent, other). No annual, casual or unpaid code exists.
- Settings `leave_days_under_one_year` (10), `leave_days_one_year_plus` (21), `leave_casual_days` (6) are seeded and in `SettingController::RULES`; nothing reads them yet.
- `employees.hire_date` is nullable (client question 6: many hire dates are still missing).
- Attendance writes go through `AttendanceDays::writeCell` (versioned, lock-checked by `PeriodGuard`, audited). Weekly offs and official holidays are derived by `Attendance\Domain\OffDays`.
- Reach: `EmployeeReach` (manager → workers by home site, engineers by assignment). The manager phone screen `/site-day` exists (`attendance.record.own`).
- No Requests module exists; the manager-attendance plan's slice 3 (`requests` for overtime/advance) was not built.
- `Notifier::notifyOnce(user, type, date, data)` and the `attendance:alert-missing` job are the notification pattern. `notifications` is unique on `(user_id, type, date)`, so two requests on one day would collapse into one notification.
- `AttendanceDays::guard()` refuses a date after today (`FutureDate`); the site-day read and the import refuse future dates too.
- `attendance_days.source` CHECK allows `entered`, `imported`, `derived`.
- An own-scope write (`$ownerId` set) of a leave site checks reach only, so a manager can store any leave type today.

### Core rule: attendance is the ledger
Leave used = stored attendance days with a leave code. A leave request is only the workflow that writes those days. So HR entering «اعتيادية» in the day sheet, the grid or the Excel import counts exactly like an approved request, and the balance can never drift from attendance.

### Data
- **Attendance source** `leave`: a migration widens `attendance_days_source_check` to `entered|imported|derived|leave`.
- **Future leave days:** `writeCell` gains an internal flag that lets the leave workflow (and only it) store a date after today. The sheet, the grid, the import and the manager screen keep refusing future dates. Approved future days are stored, so they count as used at once and two requests cannot overbook the year.
- **Notifications:** a nullable `ref` column joins the unique key `(user_id, type, date, ref)`, and `Notifier::notifyOnce` takes an optional `ref`. Leave notifications pass the request id; the anniversary alert passes the employee id. Existing callers pass none and keep their behaviour. (Foundation file, owner Youssef.)
- **LeaveCode** gains `annual`, `casual`, `unpaid`. A migration seeds the three `sites` rows (`kind=leave`): اعتيادية, عارضة, بدون أجر; English names in `attendance.leave_name.*`. If an active site already has one of these Arabic names, the migration refuses with a clear message instead of guessing (the test-server copy is test data; nothing live depends on it).
- **Manager rule:** an own-scope write (manager screen, own-scope bulk or import) refuses `annual`, `casual` and `unpaid` (new refusal `leave_needs_request`); the manager screen's leave picker hides them. Sick, rest and absent stay as today. HR (`.all`) may type all of them in the sheet, the grid and the import.
- **`leave_requests`** (new `Leave` module, `Audited`, `standardColumns`, UUIDv7):
  `employee_id` FK; `kind` CHECK `annual|casual|sick|unpaid`; `from_date`, `to_date` (CHECK from ≤ to, at most 60 days, a code limit); `days` smallint (counted days, recomputed at approval); `note` null; `status` CHECK `pending|escalated|approved|refused|cancelled`; `requested_by` FK users; `decided_by`, `decided_at`, `decision_reason` null; `version` default 1; index `(status, from_date)`, `(employee_id, from_date)`.
- **`leave_year_adjustments`** (`Audited`): `employee_id`, `year` smallint, `entitlement` smallint null (HR override; null = computed), `opening_used`, `opening_casual_used` smallint default 0, `reason` text (required when any value is set), `version`; unique `(employee_id, year)`.
- **Setting** `sick_leave_pay_percent` = 100 (L1), added to `SettingController::RULES`. Read by Release 2 payroll only.

### Balance (pure `Leave\Domain\LeaveBalance`, settings passed in)
For employee E and year Y, read on date D:
- No balance when E's type on D is day laborer.
- `entitlement` = the adjustment's override, else:
  - hire_date null → null (UI: "set the hire date");
  - service on Jan 1 of Y ≥ 1 year → `leave_days_one_year_plus`;
  - hired before Jan 1 of Y → `leave_days_under_one_year`;
  - hired during Y → floor(`leave_days_under_one_year` × M ÷ 12), where M = full calendar months from hire to Dec 31 (the hire month counts when hire day is the 1st) (L3).
- `casual_cap` = min(`leave_casual_days`, entitlement).
- `used` = count of stored days in Y with code annual or casual + `opening_used`.
- `casual_used` = count of casual days + `opening_casual_used`.
- `sick_days` = count of sick days (information; not deducted, L4).
- `unpaid_days` = count of unpaid days (information).
- `remaining` = entitlement − used (may be negative); `casual_remaining` = casual_cap − casual_used.
- ponytail: the type on the read date decides balance or none; a mid-year monthly ↔ daily change is not pro-rated. Revisit if a real case appears.

### Counting a request's days
For each date from..to: skip dates outside employment as a monthly type, the type's weekly off and official holidays (`OffDays`). The rest are the request's days. `days` is stored on submit and recomputed at approval (a holiday added in between changes it).

### Workflow (`Leave\Application\LeaveRequests`)
- **Submit** (`leave.request.own` within reach on every date, or `.all`): refuse day laborers, refuse an overlap with a pending, escalated or approved request of the same employee, refuse 0 counted days, refuse a range that crosses Dec 31 (HR enters one request per year, so each year's balance and split stay simple). Manager submissions notify the holders of `leave.decide.all` instantly (`leave_requested`).
- **Approve** (`leave.decide.all` on pending; `leave.decide_escalated.all` on escalated), in one transaction with a version check:
  1. Recount the days. Any date holding a stored non-leave day, or in a locked period, refuses with the list of dates (409 `leave_days_blocked`).
  2. For annual/casual, split: the first `min(remaining, casual_remaining if casual)` days keep the kind, the rest become `unpaid` (L2). The response and the dialog preview show the split. Sick and unpaid never split.
  3. Write each day through `AttendanceDays::writeCell` with that leave code and source `leave`; a stored leave day on the date is overwritten (keep rule).
  4. Notify `requested_by` (`leave_decided`).
- **Refuse:** reason required; notifies `requested_by`.
- **Escalate** (HR, pending only): status `escalated`; notifies holders of `leave.decide_escalated.all` (`leave_escalated`).
- **Cancel:** the requester or HR while pending or escalated; HR only when approved. Cancelling an approved request clears the days it wrote that still hold its leave code and source `leave`; a date in a locked period refuses the whole cancel.
- HR's own submission can be approved straight away from the same dialog (two calls, no special path).

### Anniversary alert
Job `leave:alert-anniversary`, daily 08:00 Cairo, `maintenance` queue, same retry pattern as `attendance:alert-missing`. For each monthly employee whose hire date + 1 year is today, it notifies the `leave.decide.all` holders (`leave_anniversary`), idempotent on (user, type, date). HR acts by setting the year's entitlement override in the adjustment dialog.

### Permissions (feature migration, `GRANTS` pattern)
| Permission | Developer | HR | CEO | Manager |
|---|---|---|---|---|
| `leave.view.all` | ✓ | ✓ | ✓ | |
| `leave.view.own` | | | | ✓ |
| `leave.request.all` | ✓ | ✓ | | |
| `leave.request.own` | | | | ✓ |
| `leave.decide.all` | ✓ | ✓ | | |
| `leave.decide_escalated.all` | ✓ | | ✓ | |
| `leave.adjust.all` | ✓ | ✓ | | |

### API (`/api/v1`, CRM envelope)
| Method | Path | Permission |
|---|---|---|
| GET | `leave/balances?year=&type=&q=&per_page=` | `leave.view` |
| GET | `employees/{id}/leave?year=` (balance + requests) | `leave.view` |
| PUT | `employees/{id}/leave/{year}` (adjustment, with version) | `leave.adjust.all` |
| GET | `leave/requests?status=&employee_id=&year=&per_page=` | `leave.view` |
| GET | `leave/requests/preview?employee_id=&kind=&from=&to=` (days, balance after, split) | `leave.request` |
| POST | `leave/requests` | `leave.request` |
| POST | `leave/requests/{id}/approve`, `/refuse`, `/escalate`, `/cancel` (with version) | as in Workflow |

`.own` reads return only employees in the caller's reach on the read date.

### Frontend
- Nav entry «الإجازات» → `/leave` with two tabs: **Requests** (pending and escalated first; cards on phones) and **Balances** (table with the year selector; negative remaining in `text-destructive`).
- Employee page: a **Leave** tab with the balance card, the year's requests, and «طلب إجازة».
- Request dialog: employee (fixed on the employee page), kind, from, to, note; the live preview line from `preview`; a warning when days go unpaid.
- Decide dialog: balance before and after, the split, Approve / Refuse (reason) / Escalate.
- Adjustment dialog (HR): entitlement override, opening used, opening casual used, reason.
- Managers use `/leave` too (own scope, phone layout). Keys under `leave.*` in both locales.

### Docs
Already updated with this spec: `docs/calculations.md` §7 (balance, request days, leave pay; L1–L3 ⚠️), `docs/client-questions.md` 8–10 (defaults), `docs/hrms-module-plan.md` (leave row). The build PR adds to `CONTEXT.md`: Leave request, Leave balance, Annual / Casual / Unpaid leave.

### Tests (PHPUnit before merge: leave-balance and permission logic)
- `LeaveBalanceTest` (unit): 10 / 21 / pro-rata at month edges, override, opening used, casual cap, missing hire date, day laborer.
- Feature: balance counts days entered through the sheet; submit refusals (overlap, day laborer, out of reach, 0 days); approve writes days, skips Friday and holidays, splits into unpaid; blocked dates 409; escalation path; cancel clears only its own days; locked period refuses; every action audited; notifications sent once; the anniversary job is idempotent.
- Frontend: dialogs, preview line, permission-gated buttons, locale parity.

### Builder check (2026-11-03) and the calls made
A fresh reviewer read this spec against the code. Every point was settled from the owner's decisions; none needed a new owner answer:
1. Future dates are refused by the attendance write path → the leave workflow may store future leave days (follows L6: approval writes the days).
2. A manager could type annual/casual directly → refused for own scope (follows L5: the manager requests, HR approves).
3. Notifications dedupe per user, type and date → a `ref` joins the unique key.
4. Existing data under other leave names → none live; the seed migration refuses a name clash instead of merging.
5. `source` CHECK → widened to `leave`.

### Build steps
1. **Balances:** leave codes + seed, adjustments table, `LeaveBalance`, balances and employee-leave reads, adjustment PUT, permissions, anniversary job, `/leave` Balances tab, employee Leave tab, adjustment dialog. Gate: screenshots.
2. **Requests:** `leave_requests`, the workflow, preview, notifications, Requests tab, request and decide dialogs, manager access. Gate: screenshots.

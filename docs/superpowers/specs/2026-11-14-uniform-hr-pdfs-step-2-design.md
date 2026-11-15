# Uniform HR PDFs, Step 2: one working method in the UI (design)

Date: 2026-11-14. Implements Step 2 (tasks 2a-2d) of the owner plan `docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md`.
It builds on Step 1 (`docs/superpowers/specs/2026-11-13-uniform-hr-pdfs-design.md`, merged as `fe1e3a4`): the six
`hr-form-exports` routes, and a row that carries `file_name` and server-computed `actions`. Line numbers are as of `fe1e3a4`.
Status: draft until the owner approves it.

## Intent
The owner rejected the HR form PDFs of PR #61 partly because each form worked differently and none could be viewed. Step 2
gives all four PDFs one way of working, as in the CRM: **Generate, then View (in the app) and Download PDF**. The PDFs
themselves keep their old layout until Steps 3 and 4.

## Goals
1. One panel, the same in every place: experience certificate on the employee file (Documents tab), leave request in the
   leave dialog, advance and overtime requests on Attendance requests, the advance also on the site-day row.
2. View opens the PDF in a dialog inside the app. Download saves it. No browser print anywhere.
3. The site manager gets the same advance PDF **without the salary row**, and can never read HR's copy.
4. One HR page «النماذج الصادرة» (Issued forms) lists every issued PDF, with filters and the approval queue.

## Non-goals
- No layout, font or wording change to any PDF (Steps 3 and 4). The one exception is removing the salary row from the
  manager's advance copy.
- No new form types. No change to who approves what.
- The signed leave PDF still prints the leave balance of the unsigned copy. Whether it should refresh at signing is a
  question for the owner in Step 3, when that form is rebuilt.
- Approving a never-signed type at own scope stays as it is (unreachable: no own-scope approve permission exists).

## Anti-goals
- A site manager seeing a salary, HR's pay-bearing copy, or a form of an employee outside their reach.
- A button the server would refuse. Every Approve and Retry comes from `row.actions`, every Generate from a permission
  the server also checks.
- A second way of working in any place (a print view, a per-form component).

## Approaches
1. **One panel driven by a registry of the four types (chosen).** `PdfPanel({ type, subject })` reads one table that says,
   per type, its title, its fields and whether own-scope callers may use it. The three per-form components and
   `AdvancePrint` are deleted. One place to change behaviour; this is the owner plan's design.
2. Keep the three components and add a View button to each. Rejected: it keeps three copies of polling, download and
   row markup, which is the "each form works differently" the owner rejected.
3. Open every PDF only from the issued-forms page. Rejected: owner decision 5 puts each PDF in its own place.

## Design

### 1. `components/FileViewer.tsx` (task 2a)
Moved out of `pages/employee-documents.tsx` (`Viewer` `:326-344` and `ViewerFile` `:347-402`), behaviour unchanged: fetch
with `apiBlob`, re-wrap the bytes with an allow-listed mime type, show an image, or an iframe when
`navigator.pdfViewerEnabled`, else an Open link; Download through `<a download>`; revoke the object URL on unmount; Retry
on a failed fetch. Props: `{ path, type, name, title, alt?, description?, onClose }`. The employee documents viewer uses
it; its eleven tests (`employee-documents.test.tsx:369-475`) keep passing with imports changed only.

### 2. `components/PdfPanel.tsx` (task 2b)
`PdfPanel({ type, subject })`: `type` is one of the four `PdfType` values, `subject` the subject id (employee id, leave
request id, attendance request id).

Registry (one constant in the same file; labels are i18n keys):

| Type | Own scope | Fields |
|---|---|---|
| `experience_certificate` | no | `job_title` (text, required, 160) |
| `leave_request` | no | `job_title` (text, required, 160); `notification_method` (text, 160); `manager_opinion` (textarea, 360); `substitute` (text, 160) |
| `advance_request` | yes | `job_title` (text, required, 160); `reason` (textarea, required, 300); `repayment_method` (choice of `receipts`, `cash`, `salary_deduction`, required); `previous_advance` (yes/no, **finance field**); `finance_remarks` (textarea, 300, **finance field**) |
| `overtime_request` | no | `job_title` (text, required, 160); `reason` (textarea, required, 300) |

Permissions, in the browser (the server checks the same): the panel shows when the caller holds `hr_forms.view.all`, or
holds `hr_forms.view` at any scope and the type is own-scope. The Generate form shows under the same rule for
`hr_forms.generate`. `can('x.y')` matches any scope (`lib/auth.tsx:30`), so the panel names `.all` explicitly.

Data: `GET /hr-form-exports?type=&subject_id=&per_page=100` through `useApi(..., { fresh: true })`; refetch every 3 s
while any row is `pending`, never otherwise. Generate posts `{ type, subject_id, idempotency_key, fields }`; the key is
kept while a request fails or goes unanswered, so a double click or a retry after a network error replays instead of
duplicating; a created copy or a changed value takes a new key, so «إنشاء نسخة جديدة» with the same values makes a new copy.
After a create, Generate stays disabled until the list shows the created copy, so a second click in that gap sends nothing.

What the user sees (the newest row first; the server orders signed above its unsigned twin):

```
┌ طلب السلفة ────────────────────────────────────────┐
│ رقم ١٤ · غير موقّع · جاهز · ١٤ نوفمبر ٥:٠٥ م          │
│ [ عرض ]  [ تنزيل PDF ]                              │
│ نسخ سابقة (٢) ▾                                     │
│ ─────────────────────────────────────────────────── │
│ إنشاء نسخة جديدة ▾   (الحقول، ثم زر «إنشاء»)          │
└─────────────────────────────────────────────────────┘
```

| State | Shows |
|---|---|
| Loading / load error | the app's usual skeleton and `ErrorBanner` |
| No PDF yet | «لم يُنشأ ملف بعد» and the Generate form open (when the caller may generate) |
| Pending | the row with «جارٍ الإنشاء…»; View and Download absent; Generate disabled |
| Ready | View and Download (`actions` has `download`); Approve when `actions` has `approve` |
| Failed | «تعذّر إنشاء الملف»; Retry when `actions` has `retry` |
| Signed | the signed row on top with a «موقّع» badge; its unsigned twin moves under «نسخ سابقة» |
| No-pay copy (advance) | a «بدون الراتب» badge on rows whose `shows_pay` is `false`, shown to callers who can see both kinds |
| Approved through View as | a «اعتُمدت بالعرض بحساب آخر» badge on rows whose `impersonator_id` is set (the panel row and the issued-forms page share it) |

Current rows and earlier copies: rows are grouped by their `shows_pay` value (one group for every type but the advance).
The newest row of each group is current and shows in full, the pay copy first; the rest sit under «نسخ سابقة». So when a
manager generates after HR, HR's own copy stays current beside the manager's. Generate is disabled only while the
caller's own kind of copy is `pending` (the caller's kind is `shows_pay = can('employee.view.all')`), or while a copy
just created is not yet in the list.

View opens `FileViewer` on `/hr-form-exports/{id}/download` with the row's `file_name`. Approve and Retry post to the
row's routes and show the server's error in the panel (for example the missing signature image).

Mounts:
- **Employee file** (`employees.tsx:523-525`): replaces `CertificateDocuments`. An archived employee keeps the Generate
  form (owner decision 10). New: a CEO sees Approve here, because `actions` says so.
- **Leave dialog** (`leave-dialogs.tsx:224`): replaces `LeaveRequestPdf`; only when the request has an employee.
- **Attendance requests dialog** (`attendance-requests.tsx:141-156`): replaces `AttendanceRequestPdf` for both types.
- **Site-day row** (task 2c, below).

Deleted with their tests: `pages/leave-request-pdf.tsx`, `pages/attendance-request-pdf.tsx`, `CertificateDocuments`
and the `download` helper in `pages/hr-forms.tsx`, and every i18n key left without a user.

### 3. Small backend fixes that the panel depends on (with task 2b)
- **Order.** `PdfExports.php:186` orders `stage desc`, which puts `unsigned` above `signed`. Change to `stage asc`
  (signed first). Step 1 spec lines 177 and 345 are corrected in the same commit.
- **A create whose queue dispatch fails** leaves a `pending` row with no job and no action, so a panel would poll for ever.
  `PdfController::queued()` catches a dispatch failure, reports it, and marks the row `failed` / `render_failed` if still
  `pending`; Retry then appears and takes the normal path. Measured at Gate 2.1: with Redis refusing writes the create
  answers 202 with a `failed` row and Retry recovers it; with Redis fully down every request answers 500 before the
  controller (role grants are cached on Redis), so no row is made and this guard is not reached. That outage is not
  Step 2's to fix.
- **422 label.** An unknown `type` is reported against «نوع الموظف». Pass the attribute name «النوع»
  (`validation.attributes.kind`) in `PdfController`'s two validations.

### 4. The site manager's advance copy (task 2c)
Rule: **pay shows only to a caller who may see pay** (`employee.view` at scope All). Any other caller who may generate an
advance PDF gets the copy without the salary row.

Backend:
- Migration (pattern: `2026_11_12_000003_grant_leave_pdf_approval.php`): create `hr_forms.generate.own` and
  `hr_forms.view.own`, grant both to `manager`, with a `down()`.
- `AdvanceRequestPdf::snapshot()`: drop the Step 1 refusal (`:41-43`); when `$showsPay` is false the snapshot has **no**
  `salary` key and carries `"shows_pay": false`. The employee is read without pay fields in that case.
- `AdvanceRequestPdf::view()` passes `showsPay` and reads the salary only when it shows; the Blade prints the salary row
  (`advance-request.blade.php:39`) only `@if ($showsPay)`. The repayment option «يخصم شهريًا من الراتب»
  (`:25`) stays on both copies: it is a choice on the form, not a pay amount. So the owner plan's check "the manager's
  PDF has no «راتب»" is read as "no salary row and no salary figure", not "the word never appears". **Owner to confirm.**
- **Finance fields.** `previous_advance` becomes optional (`nullable|boolean`, stored `null` when unanswered, printed
  blank); `finance_remarks` is already optional. The form does not show these two fields to a caller without pay; a
  no-pay create ignores them. This is the owner plan's Gate 4 assumption, taken now because the manager fills the form
  on a phone and the finance section is not theirs to answer. **Owner to confirm.**
- **Reach on the request's date** (owner plan assumption): create, show, download and retry check the manager's reach on
  the attendance request's date (`request_date` in the snapshot), not today, so the panel works on past site days. An
  own-scope list must name `type=advance_request` and `subject_id` (422 otherwise); it returns the subject's rows when the
  manager reaches the employee on the request's date, else none. This replaces Step 1 spec decision 18.
- **Separate chains.** A new row supersedes the latest unsigned row of the same type, subject **and** `shows_pay` value,
  so no answer to the manager names a row they cannot read.
- The row JSON gains `shows_pay` (`true` / `false` for an advance, `null` for the other types).
- Unchanged and pinned by tests: the manager cannot create a certificate, leave or overtime PDF (403), cannot read any
  row but no-pay advance rows of employees in reach (404, absent from lists), cannot approve.
- Accepted: retry of a signed certificate or leave row answers 403 rather than 404 to a manager who already holds its
  UUID (existence only, no content).

Frontend:
- **Site-day row** (`site-day-row.tsx:166`): the `AdvancePrint` button becomes a compact button «نموذج السلفة» that opens
  a dialog holding `PdfPanel type="advance_request" subject={advance.id}`. As today it is hidden while the amount is
  unsaved; the hint `attendanceRequests.saveBeforePrint` is reworded to «احفظ اليوم لتسجيل الطلب وإنشاء النموذج».
- **Attendance requests dialog**: the `AdvancePrint` mount (`:140-142`) goes.
- **The paper check.** `AdvancePrint` printed «مرجع الطلب: id / version», and HR was told to match the signed paper to
  that request revision (`attendanceRequests.paperApproval`). The PDF carries a serial instead. The hint becomes: match
  the signed paper's **amount and serial** with this request; and the panel on an advance says to generate a new copy if
  the amount changed after the file was made. **Owner to confirm** (a control changes its anchor).
- Deleted: `components/AdvancePrint.tsx`, its test (`attendance-requests.test.tsx:9-44`), keys
  `attendanceRequests.print`, `.advanceForm`, `.reference`, `.paper.*` (`hrForms.printOnly` went with task 2b). The amount-changed hint shows once a file exists.
  Guard: `git grep "window.print\|AdvancePrint" frontend/src` is empty.
- The developer console's role matrix reads permissions from data; the two new cells need no code.

### 5. «النماذج الصادرة» page (task 2d)
`IssuedFormsPage` at `/hr-forms`, nav entry «النماذج الصادرة» gated on `hr_forms.view.all`. It replaces
`FormApprovalsPage`, `ApprovedExport` and the `/hr-form-approvals` route and nav entry (which was gated on
`hr_forms.approve` while its list needs `hr_forms.view`). `SignaturePage` stays.

- Model: `pages/leave.tsx` (`Balances` `:142-218`): `SearchInput`, `FilterSelect`, `useDebounced`, `DataList` (table on
  desktop, cards on a phone), `Pagination`, `StatusBadge`, `useCairoTime`.
- Columns: serial, type, employee (name and code, linking to the employee file), stage, status, issued at, actions.
- Filters: type, stage, status, search `q` (serial or employee), and a toggle «بانتظار اعتمادي» (`awaiting_approval=1`): exactly
  the rows the caller can approve now (those whose `actions` include `approve`). The filters and the page number live in the URL.
- Order: newest issue first across all types. Below 1280 px the table hides the stage and the issue time (the phone cards keep
  both); a row's buttons sit on one line from 1440 px. The issue time prints the date and the time as two parts that never
  split (no «في» / "at" between them). Between 640 and 767 px the table's badges may wrap between words and its buttons
  drop their icons, so the English table does not scroll sideways.
- Row actions: View, Download, Approve, Retry, from `row.actions`, with the same handlers as the panel (shared, not copied).
- 3 s refetch while a listed row is `pending`.
- Backend: the row JSON gains `employee: { id, code, name_ar, name_en }` (one query per page, not per row). Old rows of
  deleted types stay filtered out.
- `CONTEXT.md` § HR forms gains **Issued form** (نموذج صادر): one generated PDF of an HR form, identified by its form
  serial and stage.

## Constraints
- `docs/coding-standards.md`: strings through i18n in both languages (the Arabic copy check must allow «PDF»), logical
  CSS properties, the existing tokens and `components/ui`, all states built, manager screen mobile-first with 44 px targets.
- Shared files (`App.tsx`, `AppLayout.tsx`, locale files, `routes/api.php`) get one line per entry, at the end of their group.
- No new dependency. No new migration except the permission grant.
- Money stays a decimal string; the panel does no arithmetic.

## Tests and checks
- Frontend (Vitest): `PdfPanel` per state and per type; buttons follow `actions`, never `status`; own-scope gating
  (a manager sees the advance panel and no other); the finance fields hidden without pay; `FileViewer` (moved tests);
  `IssuedFormsPage` filters, awaiting-approval toggle, approve from the page.
- Backend (PHPUnit), negatives first: the manager's copy has no salary in the snapshot, the `CREATED` audit or the
  rendered HTML; HR's copy is absent from every manager list and 404 on show, download and retry; the other three types
  are 403 on create; out-of-reach, archived and own-record employees are 404; reach follows the request's date; view-as a
  manager behaves as the manager; the manager's answers never name HR's row; the migration's grants and `down()`. Updated
  pins: `RbacTest`, `SiteManagerRoleMigrationTest`, the Step 1 tests that expect the 403.
- Real render: `scripts/smoke-hr-forms.sh` gains `advance-request-no-pay` (a permanent worker in a real manager's
  reach). Its text must not contain «راتب الموظف», «راتب الموظفة», "Employee Salary", «غير متاح», "Unavailable" or the
  salary digits. The four existing forms still pass.
- Full sets: backend suite, pint, PHPStan, deptrac; frontend test, lint, build; `route:list --path=hr-form` still six.

## Delivery: three slices, each approved by the owner from screenshots
| Slice | Tasks | The owner sees |
|---|---|---|
| 1 | 2a, 2b, the three small backend fixes | the same panel on the employee file and the leave dialog (HR and CEO) and on Attendance requests (HR; the CEO has no access to that page): no file yet, generating, ready, View dialog, Download, Approve, failed with Retry |
| 2 | 2c | the manager on a phone: the advance panel on the site-day row; the manager's PDF beside HR's (no salary row); HR's copy absent for the manager; no print button anywhere |
| 3 | 2d | «النماذج الصادرة»: list, filters, search, «بانتظار اعتمادي», the CEO approving from the page; phone cards |

Each slice: Arabic and English, 375 and 1440, light and dark. Then reviews (`code-review`, `security-review`), one PR
into `main`, and Gate 2.

## Calls made in this design (the owner can reverse any)
1. Pay shows only to callers with `employee.view` at All; everyone else gets the no-pay copy (not: own-scope callers only).
2. The manager's copy drops the salary row entirely (decision 4, read literally), not a blank row. The word «الراتب»
   stays in the repayment option. **Owner to confirm.**
3. Reach is checked on the request's date; own-scope lists need the subject.
4. Pay and no-pay copies supersede separately; the row says which it is (`shows_pay`).
5. Finance fields are optional and hidden from callers without pay. **Owner to confirm.**
6. The paper check anchors on amount and serial instead of the request revision. **Owner to confirm.**
7. The three Step 1 leftovers (order, Redis-down, 422 label) are fixed here; the leave balance at signing goes to Step 3.
8. No notification to HR when a manager generates a PDF. `CLAUDE.md` says every manager action notifies HR; the advance
   request itself already notifies HR instantly, generating its PDF changes no data, and the `CREATED` audit row records
   it. **Owner to confirm.**
9. `/hr-form-approvals` is removed without a redirect (the app has no stored links to it; the builder greps to confirm).
10. Every PDF's metadata title is its form name and serial («{form name} - الرقم المسلسل 14»), so the viewer names the file instead of the
    renderer's temporary name; each type's `PdfDocument::view()` returns `title`. A metadata-only change: the page layout and
    wording are untouched.

## Left to the builder
Component internals, exact class names, the wording of new i18n keys (glossary terms from `CONTEXT.md`), test structure,
and how the panel's handlers are shared with the issued-forms page.

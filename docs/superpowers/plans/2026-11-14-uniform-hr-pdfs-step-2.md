# Uniform HR PDFs, Step 2 (one working method in the UI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. The controller session dispatches one fresh Opus subagent per task, reads every diff, and commits by explicit path; a build subagent never commits.

**Goal:** Every HR form PDF works the same way in the app (Generate, then View in a dialog and Download), the site manager gets the advance PDF without the salary row, and HR gets one page «النماذج الصادرة».

**Architecture:** One React component, `PdfPanel`, driven by a registry of the four PDF types, replaces three per-form components and the browser-print view; a shared `FileViewer` shows the file. The backend keeps Step 1's six `hr-form-exports` routes and gains only the manager's own-scope grant, the no-pay advance copy, and two keys on the row.

**Tech Stack:** Laravel 13 / PHP 8.4 / PostgreSQL 17 / Redis queue `pdf` (backend); React 19 + Vite + TypeScript + Tailwind 4 + shadcn/Radix + react-i18next + Vitest (frontend).

**Spec:** `docs/superpowers/specs/2026-11-14-uniform-hr-pdfs-step-2-design.md` (read it whole before any task). Owner plan: `docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md`. Step 1 API: `docs/superpowers/specs/2026-11-13-uniform-hr-pdfs-design.md`. Verified code maps (file:line): `pdf-step-2-frontend-map.md` and `pdf-step-2-backend-map.md` in the session notes folder the brief names.

## Global Constraints

- `CLAUDE.md` and `docs/coding-standards.md` bind every task. A site manager never sees a wage.
- Every Approve and Retry button comes from `row.actions`; no button is chosen from `status` or a role name.
- Every UI string is an i18n key in both `frontend/src/locales/ar.json` and `en.json`, inside `hrForms.*` or the namespace the string already lives in. Arabic copy may contain «PDF» (allow-list it in `locales.test.ts`).
- Shared files (`App.tsx`, `components/layout/AppLayout.tsx`, locale files, `backend/routes/api.php`): one line per entry, at the end of its group. No change to `components/ui/*`, `lib/query.ts`, `lib/api.ts` props.
- No new dependency. One new migration only (the permission grant). No layout or wording change to any PDF except removing the salary row from the no-pay advance copy.
- Backend commands run only through `bash .claude/gate-pdf-2/wt-run.sh <cmd>` (throwaway Postgres `hrms-wt-pg`, database `hrms_test`), never `docker compose exec`. One backend test run at a time.
- Frontend commands run on the host in `frontend/`: `npm run test`, `npm run lint`, `npm run build`.
- Never stage or commit `CLAUDE_HANDOFF.md`. `.claude/` is git-ignored.
- Tests first (red, then green) for every behaviour in this plan; report each command with its real output.

## Review Focus

1. **A phone browser has no built-in PDF viewer** (`navigator.pdfViewerEnabled` is false): View must show the Open / Download link, never an empty frame. Pinned in Task 1 (kept test) and Task 3 (panel test opening the viewer with the flag off).
2. **Double click on Generate, or a retry after a network error:** one row, not two. Pinned in Task 3 (the same `idempotency_key` is sent again after a failed or unanswered request; Generate is disabled while one is in flight).
3. **The dialog closes while a row is pending:** polling stops; reopening shows the current state. Pinned in Task 3 (fake timers: no request after unmount).
4. **The advance amount changes after its PDF was made:** the old file still shows the old amount. The panel says to generate a new copy. Pinned in Task 5 (the hint renders on the advance panel).
5. **HR and a manager both generate for the same request:** neither copy hides the other; HR's Generate is not blocked by the manager's pending row. Pinned in Task 3 (grouping test) and Task 4 (separate supersede chains).

---

## Slice 1 (owner gate after Task 3)

### Task 1: Extract `FileViewer` (2a)

**Files:**
- Create: `frontend/src/components/FileViewer.tsx`
- Modify: `frontend/src/pages/employee-documents.tsx:326-402` (use the component; delete `Viewer` / `ViewerFile`)
- Test: `frontend/src/pages/employee-documents.test.tsx:369-475` (unchanged assertions)

**Interfaces:**
- Produces: `export function FileViewer(props: { path: string; type: string; name: string; title: string; alt?: string; description?: ReactNode; onClose: () => void })`. `path` is an API path for `apiBlob`; `type` is an allow-listed mime type (the caller allow-lists it); `name` is the download file name. Open while mounted (no `open` prop). Renders the dialog and its content (image, PDF frame when `navigator.pdfViewerEnabled`, else an Open link; Download; Retry on a failed fetch; revokes the object URL on unmount).

- [ ] **Step 1:** Run `npm run test -- employee-documents` and record the pass count (baseline).
- [ ] **Step 2:** Move the two components into `FileViewer.tsx` with the props above; keep markup, mime allow-list and i18n keys; `employee-documents.tsx` renders `<FileViewer … />`.
- [ ] **Step 3:** Run `npm run test -- employee-documents`: same count, all pass, with no assertion edited. Run `npm run lint`.

### Task 2: Three small backend fixes

**Files:**
- Modify: `backend/app/Modules/Pdf/Application/PdfExports.php:186`, `backend/app/Modules/Pdf/Presentation/PdfController.php` (`queued()`, the two `validate` calls), `docs/superpowers/specs/2026-11-13-uniform-hr-pdfs-design.md:177,345`
- Test: `backend/tests/Feature/PdfApiTest.php`

**Interfaces:**
- Produces: list order `serial_number desc, stage asc, id desc` (a signed row comes before its unsigned twin). A row whose dispatch failed is `status = failed`, `failure_code = render_failed`, and so has `retry` in `actions` for a caller who may retry.

- [ ] **Step 1: Failing tests** in `PdfApiTest`: `test_a_signed_row_lists_before_its_unsigned_twin` (approve a certificate, list by subject, assert `data[0].stage === 'signed'`, `data[1].stage === 'unsigned'`); `test_a_create_whose_dispatch_fails_leaves_a_failed_row_with_retry` (make the bus throw on `RenderPdf`, POST create, then GET the row: `status === 'failed'`, `failure_code === 'render_failed'`, `actions` contains `retry`; no `pending` row remains); `test_an_unknown_type_is_reported_as_the_type_field` (422 message for `type` uses `validation.attributes.kind`, not `validation.attributes.type`).
- [ ] **Step 2:** Run `bash .claude/gate-pdf-2/wt-run.sh php artisan test --filter=PdfApiTest`: the three fail.
- [ ] **Step 3:** Implement: `orderBy('stage')`; in `queued()` dispatch inside `try`, on `Throwable` `report()` it and set `failed` / `render_failed` where still `pending` (through `PdfExports`, not a query in the controller), then answer the row as usual; pass `['type' => __('validation.attributes.kind')]` as custom attributes. Correct the two spec lines.
- [ ] **Step 4:** Run the filter again: pass. Run `php artisan test --filter=Hr` and `vendor/bin/pint --test`.

### Task 3: `PdfPanel` on the employee file, leave dialog and Attendance requests (2b)

**Files:**
- Create: `frontend/src/components/PdfPanel.tsx`, `frontend/src/components/PdfPanel.test.tsx`
- Modify: `frontend/src/pages/employees.tsx:440,523-525`, `frontend/src/pages/leave-dialogs.tsx:224`, `frontend/src/pages/attendance-requests.tsx:156`, `frontend/src/pages/hr-forms.tsx` (delete `CertificateDocuments` and the `download` helper once nothing uses it), `frontend/src/locales/{ar,en}.json`, `frontend/src/locales/locales.test.ts` (allow «PDF»)
- Delete: `frontend/src/pages/leave-request-pdf.tsx`, `frontend/src/pages/leave-request-pdf.test.tsx`, `frontend/src/pages/attendance-request-pdf.tsx`
- Test: `PdfPanel.test.tsx`; update `hr-forms.test.tsx`, `attendance-requests.test.tsx:61-62`, the certificate cases in `employees.test.tsx`

**Interfaces:**
- Consumes: `FileViewer` (Task 1); the row of the Step 1 spec ("The row"); list order of Task 2.
- Produces, all exported from `components/PdfPanel.tsx`:
  - `type PdfType = 'experience_certificate' | 'leave_request' | 'advance_request' | 'overtime_request'`
  - `type PdfExport = { id: string; type: PdfType; serial_number: number; stage: 'unsigned' | 'signed'; status: 'pending' | 'ready' | 'failed'; employee_id: string | null; created_at: string; file_name: string; actions: ('download' | 'approve' | 'retry')[]; shows_pay?: boolean | null; employee?: { id: string; code: number; name_ar: string; name_en: string | null } }`
  - `const PDF_TYPES: Record<PdfType, { ownScope: boolean; fields: { name: string; kind: 'text' | 'textarea' | 'choice' | 'yesno'; required?: boolean; max?: number; options?: string[]; finance?: boolean }[] }>` with exactly the spec's table (design section 2).
  - `function PdfRowActions(props: { row: PdfExport; onChanged: () => void })`: View (opens `FileViewer` on `/hr-form-exports/{id}/download`, mime `application/pdf`, name `row.file_name`) and Download when `actions` has `download`; Approve when it has `approve` (POST `/hr-form-exports/{id}/approve`); Retry when it has `retry` (POST `…/retry`); shows the server error inline. Task 6 reuses it.
  - `function PdfPanel(props: { type: PdfType; subject: string })`.
  - `function canUsePdf(can: (a: string) => boolean, action: 'view' | 'generate', type: PdfType): boolean` = `can('hr_forms.' + action + '.all') || (PDF_TYPES[type].ownScope && can('hr_forms.' + action))`. Every mount uses it.

- [ ] **Step 1: Failing tests** in `PdfPanel.test.tsx` (with `mockFetch` from `test-utils.tsx`), one per line:
  - no rows: shows the empty text and the Generate form with the type's fields (assert the field names per type from `PDF_TYPES`).
  - a `pending` row: no View / Download; Generate disabled; a second GET happens after 3000 ms (fake timers) and none after unmount.
  - a `ready` row with `actions: ['download']`: View and Download, no Approve, no Retry.
  - a `ready` row with `actions: ['download','approve']`: Approve posts to `/hr-form-exports/{id}/approve`.
  - a `failed` row with `actions: []`: no Retry; with `['retry']`: Retry posts to `…/retry`.
  - a `ready` row whose `actions` is `[]` shows no button at all (buttons never come from `status`).
  - Generate posts `{ type, subject_id, idempotency_key, fields }`; after a failed or unanswered request a second submit with unchanged values sends the same key; after a create or a changed field, a new key.
  - View with `navigator.pdfViewerEnabled = false` shows the Open link, not an iframe.
  - grouping: rows `[{shows_pay:false, serial 3}, {shows_pay:true, serial 2}, {shows_pay:true, serial 1}]` render serial 2 and serial 3 as current (pay copy first, serial 3 with the no-pay badge) and serial 1 under the earlier-copies disclosure; a `pending` no-pay row does not disable Generate for a caller with `employee.view.all`.
  - `canUsePdf`: a caller with only `hr_forms.view.own` and `hr_forms.generate.own` gets `true` for `advance_request` and `false` for the other three; the panel renders nothing when view is refused and no form when generate is refused.
  - finance fields (`previous_advance`, `finance_remarks`) are absent from the form without `employee.view.all` and are not sent.
- [ ] **Step 2:** Run `npm run test -- PdfPanel`: all fail.
- [ ] **Step 3:** Implement `PdfPanel.tsx` per the spec's section 2 (states table, text sketch, grouping rule, 3 s polling only while a row is pending, `useApi(path, { fresh: true })`, `per_page=100`). Use existing `components/ui` and tokens; logical CSS properties; 44 px targets; works at 375 px inside a dialog.
- [ ] **Step 4:** Mount it with `canUsePdf` at the three places; gate the Documents tab filter (`employees.tsx:440`) so the `.own` grant of Task 4 does not open the certificate to managers; keep the Generate form for an archived employee. Delete the three old components and every i18n key left without a user (both locale files).
- [ ] **Step 5:** Update the page tests that named the old components or URLs. Run `npm run test`, `npm run lint`, `npm run build`: all pass. `git grep -n "leave-request-pdf\|attendance-request-pdf\|CertificateDocuments" frontend/src` is empty.

**Gate 2.1 (controller):** commit Tasks 1-3 separately by path; capture with `verify-hrms` (hr and ceo; ar and en; 375 and 1440; light and dark): the panel on the employee file, in the leave dialog and on Attendance requests in each state, the viewer dialog, Approve by the CEO. Report to the owner in Arabic; wait.

---

## Slice 2 (owner gate after Tasks 4 and 5; the two tasks touch disjoint folders and run in parallel)

### Task 4: The manager's no-pay advance copy, backend (2c)

**Files:**
- Create: `backend/database/migrations/2026_11_14_180000_grant_manager_advance_pdf.php`
- Modify: `backend/app/Modules/Pdf/Application/Types/AdvanceRequestPdf.php`, `backend/app/Modules/Pdf/Application/PdfExports.php`, `backend/app/Modules/Pdf/Presentation/PdfController.php` (row key, own-scope list validation), `backend/resources/views/pdf/advance-request.blade.php:39` and the previous-advance line, `scripts/smoke-hr-forms.sh`, `backend/tests/Smoke/render-hr-form.php`, `docs/superpowers/specs/2026-11-13-uniform-hr-pdfs-design.md` (decision 18, "Known gaps for task 2c": mark resolved), `backend/CLAUDE.md` only if a command changes
- Test: `backend/tests/Feature/PdfManagerAdvanceTest.php` (new); update pins `RbacTest.php:24-25`, `SiteManagerRoleMigrationTest.php:31`, `PdfApiTest.php:76-79,214`, `HrAdvanceRequestFormTest.php:71-73,80-84`, `HrLeaveRequestFormTest.php:69`, `HrExperienceCertificateTest.php:56`, `HrOvertimeRequestFormTest.php:85-90`

**Interfaces:**
- Produces: permissions `hr_forms.generate.own`, `hr_forms.view.own`, granted to role `manager`. Row JSON key `shows_pay`: `true|false` for `advance_request`, `null` otherwise. Advance field rule `previous_advance: nullable|boolean` (stored `null` when absent, printed blank). An own-scope `GET hr-form-exports` without `type=advance_request` and `subject_id` is 422 `validation_failed`.

- [ ] **Step 1: Failing tests** in `PdfManagerAdvanceTest` (a real `manager` user with a site, a permanent worker whose home site is that site on the request's date, an advance request on that date; HR as the second actor). One test per line, names as written:
  - `test_the_migration_grants_the_manager_own_scope_generate_and_view_and_down_removes_them`
  - `test_a_manager_generates_a_copy_without_salary`: 202; the stored snapshot has no `salary` key and `shows_pay === false`; the `CREATED` audit has no salary; rendering the row's HTML (the job's view with the snapshot) contains none of «راتب الموظف», «راتب الموظفة», `Employee Salary`, «غير متاح», `Unavailable`, nor the salary digits; row JSON `shows_pay === false`.
  - `test_hr_copy_keeps_the_salary_and_is_hidden_from_the_manager`: HR creates (snapshot has `salary`, `shows_pay === true`); as the manager the list by subject omits it, and show, download and retry of its id are 404.
  - `test_the_two_copies_supersede_separately`: HR creates, then the manager creates: the manager's row has `regenerated_from_id === null` and HR's row is not superseded; the manager creates again: the second points at the manager's first.
  - `test_a_manager_cannot_create_the_other_three_types` (403 `permission_denied` each) and `test_a_manager_cannot_approve` (403).
  - `test_out_of_reach_archived_and_own_record_employees_are_not_found` (create 404 for each).
  - `test_reach_follows_the_request_date`: the worker's home site moved to another manager's site after the request's date: the first manager still creates and reads (202 / 200); a manager who reaches the worker only today gets 404.
  - `test_an_own_scope_list_needs_the_subject` (422 without `subject_id`; 200 with it; empty `data` for a subject out of reach).
  - `test_view_as_a_manager_behaves_as_the_manager` (developer viewing as: create gives a no-pay copy; HR's copy 404).
  - `test_previous_advance_is_optional_and_prints_blank` (create without it as HR: 202; rendered HTML has neither `نعم / Yes` nor `لا / No` in that row).
- [ ] **Step 2:** Run `bash .claude/gate-pdf-2/wt-run.sh php artisan test --filter=PdfManagerAdvanceTest`: all fail.
- [ ] **Step 3:** Implement per the spec's section 4 (backend list). Keep `PdfDocument::snapshot`'s signature. Reach date: the attendance request's `date` at create, the snapshot's `request_date` on reads. Supersede lookup adds the `shows_pay` value for the advance. No salary is read at all when `$showsPay` is false (do not load pay fields and drop them).
- [ ] **Step 4:** Update the pinned tests listed above to the new behaviour (each change explained in the report). Add the smoke form `advance-request-no-pay` (permanent worker, real manager, create with the manager's scope) and its absent-phrase list.
- [ ] **Step 5:** Run: `php artisan test` (full), `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml`, `php artisan route:list --path=hr-form` (six routes), and `HRMS_PDF_IMAGE=hrms-php HRMS_SMOKE_FORM=<form> bash scripts/smoke-hr-forms.sh <outdir>` for `advance-request`, `advance-request-no-pay`, `experience-certificate`, `leave-request`, `overtime-request`. All pass.

### Task 5: The panel on the site-day row; `AdvancePrint` goes (2c)

**Files:**
- Modify: `frontend/src/pages/site-day-row.tsx:166`, `frontend/src/pages/attendance-requests.tsx:139-142`, `frontend/src/components/PdfPanel.tsx` (the amount-changed hint on the advance), `frontend/src/locales/{ar,en}.json`
- Delete: `frontend/src/components/AdvancePrint.tsx`; keys `attendanceRequests.print`, `.advanceForm`, `.reference`, `.paper.*`
- Test: `frontend/src/pages/site-day-row.test.tsx` (new), `frontend/src/pages/attendance-requests.test.tsx:9-44` (the print test is replaced)

**Interfaces:**
- Consumes: `PdfPanel`, `canUsePdf` (Task 3); `r.requests.advance = { id, status, minutes, amount, version }` (`lib/site-day.ts:3,12`).

- [ ] **Step 1: Failing tests:** in `site-day-row.test.tsx`: a saved advance and a caller holding `hr_forms.view.own` + `hr_forms.generate.own` shows the button «نموذج السلفة» (key `attendanceRequests.advancePdf`); clicking it opens a dialog that requests `/hr-form-exports?type=advance_request&subject_id=<advance.id>&per_page=100`; the button is absent while the amount is unsaved (`draft.advance_request !== undefined`) and absent without the permission. In `attendance-requests.test.tsx`: the pending advance shows the reworded paper hint (amount and serial, no `{{version}}`), no print button. In `PdfPanel.test.tsx`: the advance panel shows the "generate a new copy if the amount changed" hint; the other types do not.
- [ ] **Step 2:** Run the three test files: the new cases fail.
- [ ] **Step 3:** Implement; reword `attendanceRequests.saveBeforePrint` and `.paperApproval` (both languages) per the spec (`hrForms.printOnly` already went with Task 3); delete `AdvancePrint.tsx` and its keys, and the `siteName` / `date` props of `PersonRow` that only it used (`pages/site-day.tsx:191`).
- [ ] **Step 4:** Run `npm run test`, `npm run lint`, `npm run build`: pass. `git grep -n "window.print\|AdvancePrint" frontend/src` is empty.

**Gate 2.2 (controller):** commit Tasks 4 and 5 by path; capture as manager at 375 (site-day row, the dialog, generate → ready → viewer), ar and en, light and dark, plus 1440; HR's Attendance requests dialog showing both copies with the badge; page images of the manager's PDF beside HR's (`pdftoppm`), and the text check that the manager's has no salary row; the manager's 404 on HR's row. Report in Arabic; wait.

---

## Slice 3 (owner gate after Task 6)

### Task 6: «النماذج الصادرة» page (2d)

**Files:**
- Modify: `backend/app/Modules/Pdf/Presentation/PdfController.php` (row key `employee`), `backend/app/Modules/Pdf/Application/PdfExports.php` only if the page query needs it, `frontend/src/pages/hr-forms.tsx` (`IssuedFormsPage` replaces `FormApprovalsPage` and `ApprovedExport`; `SignaturePage` stays), `frontend/src/App.tsx`, `frontend/src/components/layout/AppLayout.tsx`, `frontend/src/locales/{ar,en}.json`, `CONTEXT.md:214-223`, `docs/hrms-module-plan.md` (one line: Step 2 built)
- Test: `backend/tests/Feature/PdfApiTest.php`, `frontend/src/pages/hr-forms.test.tsx`

**Interfaces:**
- Consumes: `PdfRowActions`, `PdfExport`, `PDF_TYPES` (Task 3).
- Produces: row JSON key `employee: { id, code, name_ar, name_en } | null` on every `hr-form-exports` answer, loaded with one query per page. Route `/hr-forms` → `IssuedFormsPage`; nav entry gated on `hr_forms.view.all`; `/hr-form-approvals` and its nav entry removed.

- [ ] **Step 1: Failing tests.** Backend `test_each_row_names_its_employee_with_one_query_for_the_page` (list of 3 rows: each has `employee.name_ar` and `employee.code`; the employee lookup runs once, asserted with the query log). Frontend, in `hr-forms.test.tsx`: the page lists serial, type, employee name (a link to `/employees/{id}`), stage, status and issued time; changing the type, stage and status filters and typing in search each refetch with `type=`, `stage=`, `status=`, `q=`; the «بانتظار اعتمادي» toggle sends `awaiting_approval=1`; Approve on a row with `actions: ['download','approve']` posts to `/hr-form-exports/{id}/approve` and the list refetches; a row with `actions: []` shows no action button; a caller with `hr_forms.view.own` only does not get the nav entry (assert on the nav's permission string `hr_forms.view.all`); empty and error states render.
- [ ] **Step 2:** Run both: the new cases fail.
- [ ] **Step 3:** Implement on the model of `pages/leave.tsx` `Balances` (`SearchInput`, `FilterSelect`, `useDebounced`, `DataList`, `Pagination`, `StatusBadge`, `useCairoTime`); 3 s refetch while a listed row is pending; `PdfRowActions` for the buttons. Add the **Issued form** (نموذج صادر) term to `CONTEXT.md`. `git grep -n "hr-form-approvals\|FormApprovalsPage\|ApprovedExport" frontend/src backend` must end empty (update any notification link that pointed there).
- [ ] **Step 4:** Run backend: `php artisan test --filter=PdfApiTest`, pint, PHPStan, deptrac. Frontend: `npm run test`, `npm run lint`, `npm run build`. All pass.

**Gate 2.3 (controller):** commit by path; capture as hr and ceo (ar / en; 375 / 1440; light / dark): the list, each filter, search, «بانتظار اعتمادي», the CEO approving from the page, phone cards, the viewer from a row. Report in Arabic; wait.

---

## Before the PR (controller)

- [ ] Two read-only Opus subagents in parallel: `code-review` (base `main`) and `security-review` (the grant, the pay gate, reach on the request's date, the own-scope list, the row's new keys).
- [ ] One fresh fix subagent for the findings; one independent verifier on the final head: full backend suite, pint, PHPStan, deptrac, frontend test / lint / build, `route:list --path=hr-form` (six), the five smoke forms, the two `git grep` guards.
- [ ] Push; open ONE PR into `main`; bind it; Gate 2 report to the owner in Arabic with the screenshots. No merge and no Step 3 until the owner approves.

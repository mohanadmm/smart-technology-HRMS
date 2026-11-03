# Employee documents: upload, view, no received date

Status: **approved**, 2026-11-03. The owner accepted every recommendation (D1–D10); building in two steps (`docs/superpowers/plans/2026-11-03-employee-documents.md`).
Owner's request (2026-11-03): every employee has their own documents; remove the received date; HR can upload documents and see them in the app, on the same page.

---

## Part 1 — For the owner (plain words)

### What it will look like
On the employee's page, the **Documents** tab becomes a list of the actual files.

- **Top of the tab:** "Documents · 5" and one main button, **Upload document** (HR only). When the employee has no documents yet, the tab shows one sentence and that one button in the middle instead.
- **Each document** is one line: its type (national ID, criminal record…) as a link, the file name and size, and the expiry date with a red **Expired** or amber **Expires in 12 days** tag when it matters. On a phone each document is a small card instead of a table row.
- **Tap the type to see the document**, without leaving the page. A photo opens large. A PDF opens inside the app on a computer. On a phone, a PDF gets an **Open** button that hands it to the phone's own PDF viewer, because phone browsers can't reliably show a PDF inside a page.
- **Under the list, one line says what is still missing**, for example "Not uploaded yet: criminal record, birth certificate." Each name is a link that opens the upload window with that type already chosen.
- **Upload window:** choose a file (or take a photo on a phone), pick the type, optionally an expiry date and a note, then **Upload**. Big phone photos are shrunk automatically before sending, so they upload fast and fit the limit.
- **Each document's menu (⋮):** Replace file, Edit details (type, expiry, note), Delete (asks to confirm).
- **The received date disappears** everywhere: the column, the form and the database. The expiry date stays.

### The ideas behind it
1. **One line = one file.** An employee can have several files of the same type (ID front and back, two certificates, several "other"). The fixed list of 9 types stays, as the "still missing" line.
2. **Files are private.** They never sit on a public link. The app fetches a file only for someone allowed to see that employee **and** allowed to open documents.
3. **Replace and delete hide, they don't erase.** The old file stays on the server and the audit log records who did what and when. There is no "undo" button; bringing a file back is a manual fix by the Developer.
4. **No new libraries.** Photos and PDFs are shown with what browsers already do.
5. **Same look as the rest of the app:** Arabic first, English too, cards on phones, one main button per screen.

### Decisions (owner, 2026-11-03: all recommendations accepted)
| # | Question | Options | Decided |
|---|---|---|---|
| D1 | How many files per document type? | **(a)** several, one line per file · (b) exactly one per type | Decided: **(a)** several files per type, one line per file. |
| D2 | What do managers see of their own employees' documents? | **(a)** the list only (type and expiry), no files and no notes · (b) nothing · (c) everything, files included | Decided: **(a)** managers see the list only (type and expiry); file access can be granted later from the console. |
| D3 | What does Delete do? | **(a)** hides the file; it stays on the server and in the audit; bringing it back is a manual Developer fix · (b) erases it for good · (c) hide, plus a "Restore" button | Decided: **(a)** Delete hides; the file stays on the server and in the audit; restoring is a manual Developer fix. |
| D4 | Size limit per file | **(a)** 10 MB (one-line server change), photos shrunk automatically · (b) 5 MB, as the server allows today | Decided: **(a)** 10 MB per file, photos shrunk automatically. |
| D5 | File types | **(a)** PDF, JPG, PNG, WEBP · (b) also Word / Excel | Decided: **(a)** PDF, JPG, PNG, WEBP. |
| D6 | Backups | Files are saved in the app's own folder on the server (`backend/storage/app/private`). **A database backup alone does not include them.** | Decided: one line in the manual backup notes: copy `backend/storage/app/private` with the DB dump. No automation. |
| D7 | Existing received dates and document rows | The local database has **0** documents. The test-server copy was not checked. | Decided: counted on the test server on 2026-11-03 (read only): **0** rows. The file columns are NOT NULL. |
| D8 | Should the app record who **opened** a file? | (a) no, as today for every screen · (b) yes, for documents only | Decided: **(a)** no view log for now. |
| D9 | Show who uploaded and when? | **(a)** inside the viewer only · (b) on every line · (c) nowhere | Decided: **(a)** uploader and date inside the viewer only. |
| D10 | Are the 9 document types right? | keep them · add some (e.g. "employment contract") | Decided: keep the 9 types. |

### Build plan (each step ends with screenshots for your approval: phone 375 px and desktop, Arabic and English)
1. **Server side + received date gone:** files are stored, checked and served; who can see what is tested; the received date leaves the screen and the database. The current Documents tab keeps working until step 2 replaces it.
2. **The new Documents tab and the viewer:** the list, the "still missing" line, the upload window with photo shrinking, Replace / Edit / Delete, the expiry tags and the count, and photos and PDFs opening on computer and phone. This step is also checked on a real iPhone and a real Android phone (taking a photo from the upload window, opening a PDF, an iPhone photo arriving as JPG).

Cut from the first draft as not asked for: drag-and-drop on desktop, a setting for the "expires soon" days (fixed at 30 until the Release 3 reminders need a setting), a separate storage volume, and extra file checks the server already covers.

---

## Part 2 — Technical design

Sources: three research notes (code map; CRM + Laravel; HR systems + viewers) in the session's shared notes folder, outside the repo. References are to `main` at 3fcc38a.

### Today
- `employee_documents` (`backend/database/migrations/2026_10_16_000001_create_employees_tables.php:55-63`): standard columns (UUIDv7, `created_by`/`updated_by`, soft delete), `employee_id`, `kind` string(30), `received_at` date, `expires_at` date, `note` text, `unique(employee_id, kind)` (not partial). No file. `DocumentKind` has 9 cases.
- One route: `PUT /api/v1/employees/{employee}/documents/{kind}` (`routes/api.php:46-47`), `permission:employee.edit` + `employee.scope`; `EmployeeService::document` `updateOrCreate` (`EmployeeService.php:347-353`). Documents are serialized inside `GET /employees/{id}` by `EmployeeService::show()` (`:170`) and `row()` (`:408-411`), which sends `toArray()` minus `created_by`, `updated_by`, `deleted_at`, `employee_id`, to managers too (`employee.view.own`).
- `permission:` middleware takes `resource.action`; `AuthorizeAction::decide()` (`Identity/Application/Rbac/AuthorizeAction.php:17-22`) finds the grants under that prefix and reads the scope (`all`/`own`) from them. `EnsureEmployeeInScope` then 404s an employee outside a manager's reach.
- Audit: `EmployeeDocument` uses `Audited`; CREATED stores every attribute, UPDATED the changed keys, DELETED the old attributes. A model `delete()` with `SoftDeletes` fires `deleted`; a query-builder delete fires nothing.
- Frontend: `Documents` in `frontend/src/pages/employees.tsx:710-771`, a raw table of 9 fixed rows with a JSON `FormDialog` that PUTs per kind.
- Auth is a **bearer token** in localStorage, so an API URL can't be an `<img src>`. `apiBlob()` exists (`frontend/src/lib/api.ts:78-80`); `attendance-import.tsx:58-65` already does blob → object URL → revoke, and `:96-106` has the styled `sr-only` file input.
- Nothing is stored on disk today (`Storage::` unused). Disk `local` = `storage/app/private`, git-ignored (`backend/storage/app/private/.gitignore` is `*`), `serve => true`. Docker bind-mounts `./backend` into PHP (`docker-compose.yml:9-10`), so files land on the host under `backend/storage/app/private`; `scripts/update.sh:88` chowns `storage` to www-data and backs up only the DB (`:56`).
- PHP `upload_max_filesize=6M`, `post_max_size=8M` (`docker/php/Dockerfile:15`); nginx `client_max_body_size 40m`, `nosniff`, `X-Frame-Options SAMEORIGIN`. No GD/Imagick.
- Over `post_max_size` → `PostTooLargeException` (413), passed through raw and in English by `bootstrap/app.php:98`. Between `upload_max_filesize` and `post_max_size` → PHP drops the file and Laravel says `validation.uploaded` ("failed to upload").

### Received date: removal scope
- Backend: migration (`:59`); `EmployeeController.php:124`; `backend/lang/ar/validation.php:189`, `backend/lang/en/validation.php:34`; `backend/tests/Feature/EmployeesApiTest.php:132-137`; implicit in `row()`.
- Frontend: `employees.tsx:723,737,749,754,758`; `locales/ar.json:146`, `en.json:146`; `employees.test.tsx:110-120`.
- Docs: `docs/hrms-module-plan.md:159`. Old plans in `docs/superpowers/plans/release-1*.md` are history and stay.
- Untouched: master-data import, seeders, CONTEXT.md, calculations.md; nothing reads `expires_at` yet.
- Data: the audit CREATED entries keep every received date that was ever saved. `down()` re-adds the column empty and **does not** restore the unique index (it would fail once an employee has two files of one kind); it says so.

### Data
One new migration on `employee_documents`:
- drop `received_at`; drop `unique(employee_id, kind)`;
- add `original_name` string(255), `mime` string(50), `size_bytes` integer (check > 0), all NOT NULL (D7: 0 rows on the test server).
- **No `path` column:** the path is derived, `employee-documents/{employee_id}/{id}.{ext}` with `ext` from `mime` (`pdf`/`jpg`/`png`/`webp`). One less column to drift; the client name never touches the path.
- No `files` table, pivots or versions. Replace = new row + soft-delete of the old one. (ponytail: one owner of files today; move to a shared files table when a second module needs files — the Release 3 attendance sign-off upload may be that moment.)

### Storage
- Disk `local`, `'serve' => false`. **No Docker volume**: the bind mount already keeps files on the host, git-ignored, and a named volume would be wiped by the `docker compose down -v` that `backend/CLAUDE.md:22` tells developers to run. Revisit when a production compose file exists.
- Backup note (doc only): copy `backend/storage/app/private` with the DB dump. Soft-deleted files are never purged, so the folder only grows; note that too.

### API (`/api/v1`, CRM envelope)
| Method | Path | Body | Middleware | Result |
|---|---|---|---|---|
| POST | `/employees/{employee}/documents` | multipart `file`, `kind`, `expires_at?`, `note?`, `replaces?` | `permission:employee_document.edit`, `employee.scope` | 201 + document |
| PATCH | `/employees/{employee}/documents/{document}` | `kind?`, `expires_at?`, `note?` | same | 200 |
| DELETE | `/employees/{employee}/documents/{document}` | — | same | 204, soft delete via the model |
| GET | `/employees/{employee}/documents/{document}/file` | — | `permission:employee_document.view`, `employee.scope` (exactly one `permission:`) | the bytes |

- `{document}` is always resolved through the employee (`$employee->documents()->findOrFail`), also for `replaces`; soft-deleted rows 404, including on `/file`.
- **Upload order:** new id `Str::uuid7()` → `storeAs` the file → `DB::transaction` (`lockForUpdate` the `replaces` row and check it is live, insert, soft-delete the old one) → on any exception delete the new file and rethrow. Test: a failed insert leaves no file.
- The old `PUT …/documents/{kind}` is removed in **step 2**, together with the dialog that calls it, so step 1 doesn't break the live tab.
- `GET /employees/{id}`: `EmployeeController::show` calls `AuthorizeAction::decide($user, 'employee_document.view')` (as `ImportMasterDataCommand` already does) and passes the result to `show()`. Without it, each document carries only `id`, `kind`, `expires_at` (D2a). With it: plus `original_name`, `mime`, `size_bytes`, `note`, `created_at`, uploader name. `received_at` gone.

### Permissions (seeded in the feature migration with the `GRANTS` const pattern)
- Grants: `employee_document.view.all` → developer, hr, ceo; `employee_document.view.own` → seeded, granted to nobody (the Developer may grant it to managers); `employee_document.edit.all` → developer, hr. Routes name only `employee_document.view` / `.edit`.
- Locale labels for the roles console: `perm.employee_document.view`, `perm.employee_document.edit` (ar + en).
- A manager without the view grant gets **403** on `/file` (permission runs before scope); outside reach, 404.

### Validation and security
- Server: `File::types(['pdf','jpg','jpeg','png','webp'])->max(10240)`; Laravel 13's `mimes` sniffs the bytes (finfo), and file rules refuse `.php*`/`.phtml`/`.phar`. Store the **detected** mime. No SVG, HTML, Office or archives.
- `original_name`: strip bidi control characters (U+202A–202E, U+2066–2069) and control characters, cut to 255.
- Limits (D4a): app 10 MB < PHP `upload_max_filesize=12M` ≤ `post_max_size=13M` < nginx 40 MB (one Dockerfile line + image rebuild). Map `validation.uploaded` for this field to the "too large" message, and render `PostTooLargeException` as a 413 in the envelope. The frontend maps **HTTP status 413** (nginx's own 413 is HTML) to "too large".
- Serving: `Storage::response()` with `Content-Type` = stored mime, `nosniff`, `Cache-Control: private, no-store`, and a Content-Disposition name built as the original base name + the **detected** extension (so a polyglot uploaded as `x.html` downloads as `x.pdf`), RFC 5987 via `HeaderUtils::makeDisposition`. A CSP `sandbox` header may be added as defence in depth only: the SPA reads the bytes through fetch, so headers don't reach the blob.
- The real control in the browser: rebuild the Blob with an allow-listed type from the document's stored mime (never `res.blob().type`); `<img>` only for `image/*`, `<iframe>` only for `application/pdf`. Test it. If an app CSP is added later it needs `img-src blob:` and `frame-src blob:`.
- Skipped, with reason: virus scanning (trusted HR uploaders on an internal app; the CRM only ships a stub); `%%EOF`/`getimagesize` checks (the `file` rule already rejects partial uploads; `%%EOF` rejects some valid PDFs); signed URLs (the URL becomes the credential); server-side EXIF stripping (needs GD). Ceiling: photos under ~1.5 MB and PNGs are sent as-is and keep their EXIF (GPS included).
- Fixtures: `UploadedFile::fake()` and tiny generated files only. No real document enters the public repo; nothing writes to the `public` disk.

### Audit
`Audited` covers it: upload = CREATED (kind, name, mime, size); replace = CREATED + DELETED in one transaction; edit = UPDATED; delete = DELETED, always through the model. Tests assert each entry. File views are not audited (D8a).

### Frontend
- **List:** `DataList` (table ≥ 640 px, cards below, built-in states). Title cell = a `<button>` with the kind name that opens the viewer (`DataList` has no row click, and a button is keyboard-reachable and gives Radix a focus-return target). Then `<bdi>` file name (`max-w-*` + `truncate` + `title` on desktop) and size, expiry + badge (constant 30 days), `RowActions` ⋮ (Replace file, Edit details, Delete). Sizes and dates with `tabular-nums` and `intlLocale()` (`form.tsx`). Sort by `DocumentKind` order, then newest (new work: today's sort is alphabetical).
- **Header:** title "Documents · N"; the Upload button only when N > 0 and the user can edit. When N = 0, `EmptyState` carries the single Upload action (or only the sentence for non-editors).
- **Missing line:** only when N > 0 and the user can edit; styled as content; each kind is a text link that opens the upload dialog with the kind preset.
- **Upload dialog** (bottom sheet on phones): the `attendance-import` file-input pattern (`sr-only` input + label styled as an outline button + chosen name in `<bdi>`), `accept="image/jpeg,image/png,image/webp,application/pdf"`, no `capture`, never `image/heic`. Kind select, `type=date` expiry, note (`enterkeyhint="done"`), image preview (its object URL revoked too). The `File` lives in the dialog's own state, because `FormApi.set` is string-typed (`form.tsx:238`). Order: check type → if a JPEG/PNG/WEBP image over ~1.5 MB, shrink → check size of the result. Shrink = `createImageBitmap(file, { imageOrientation: 'from-image' })`, `drawImage` to a 2400 px long edge on a white-filled canvas, `toBlob('image/jpeg', 0.85)`, keep whichever file is smaller, rename to `.jpg`, fall back to the original on any error. Busy state on the button; no progress bar. Errors: too large (with size and limit; for PDFs, "scan at lower quality or split it"), wrong type, HEIC ("save it as JPG or PDF"), network with Retry.
- **Retry** (step-2 gate): after a network failure or a 5xx the upload may still have landed, so a Retry of a plain upload may create a duplicate, which HR can delete; a Retry of a replace that answers 404 means the first attempt landed, and the dialog closes.
- **Viewer** (`Dialog` with a class override: full screen on phones with `env(safe-area-inset-top/bottom)` on header and footer, `max-w-4xl`/`85vh` on desktop, `bg-scrim` = 70% backdrop): `DialogTitle` = kind, `DialogDescription` = `<bdi>` name + "uploaded … by …". Fetch with `apiBlob`; loading skeleton (`aria-busy`); error with Retry inside the dialog. Image → `<img alt>` (kind + name), object-contain. PDF → `<iframe title>` when `navigator.pdfViewerEnabled`, else an **Open / Download** link rendered once the blob is ready (iOS blocks `window.open` after an await). No `sandbox` on the PDF iframe (Chromium refuses to render PDFs there). Revoke the URL when the dialog closes. Footer: Download (secondary). Whether iOS reports `pdfViewerEnabled` and shows all pages is checked on a real iPhone in step 2.
- **i18n:** keys under `employee.document.*`, ar + en. Arabic strings naming "PDF"/"JPG" contain Latin letters, so add their keys to `allowed` in `frontend/src/locales/locales.test.ts:27`.
- `useSave`'s default invalidation (`['employees']`) already refetches the page after upload, edit and delete. No new dependency; pdf.js (~515 KB gzip) only if phone users need multi-page PDFs inside the page.

### Tests (PHPUnit before merge; permission logic ships with tests)
Upload stores file + row (fake disk); wrong type, SVG renamed to `.png`, HTML renamed to `.pdf`, oversize → 422 with the right messages; `PostTooLargeException` → 413 envelope; manager outside reach → 404; manager inside reach without the view grant → 403 on `/file` and no file fields or note in `GET /employees/{id}`; another employee's document id → 404; soft-deleted document → 404 on `/file`; replace soft-deletes the old row; failed insert leaves no file; delete is soft and keeps the file; audit entries; no `received_at` and no path in any response; download name uses the detected extension. Frontend: `employees.test.tsx` updated; viewer picks img / iframe / Open by stored mime; the missing line and empty state.

### Build slices
1. **Backend**: migration, permissions + console labels, `serve => false`, the four endpoints, validation, 413 handling, Dockerfile limits (D4a), `show()` filtering, tests; frontend drops the received-date column and field only (the old PUT stays until step 2). Then `code-review` + `security-review`.
2. **Documents tab + viewer**: list, header count, empty state, missing line, upload dialog with shrinking, Replace / Edit / Delete, expiry badges; remove the old PUT route and dialog; the viewer (image and PDF, phone fallback). Gate: screenshots 375 / 1024 / 1440, ar / en, + one real iPhone and one Android check.

---

## Part 3 — Verification

### How it was checked
- **pstack `interrogate`** (read and followed from its SKILL.md, owner rule): two independent Opus reviewers (high effort, per `pstack-models.md`) reviewed the first draft against the code with the interrogate rubric and code-quality lens; I sorted their findings with its lead-judgment rules.
- **ponytail + karpathy guidelines**: every researcher and reviewer loaded or applied them; the cuts below are their result.
- **Owner's UI/UX takeaways** (`~/.claude/skills/uiux/uiux-notes.md`, the 24 "Combined takeaways"; the Apple Notes original was blocked by macOS privacy, so the skill's copy was used): checked one by one by a third Opus reviewer against the real components.
- **security-review mindset** (OWASP file upload) by the same reviewer, on uploads, downloads and permissions.
- I re-checked the critical claims in the code myself: the permission prefix in `AuthorizeAction.php:19`, the bind mount and `down -v` instruction, `update.sh` chown/backup, the Arabic Latin-letter test, no `restore()` anywhere.

### Interrogate verdict (first draft → this version)
**Act on (done in this version)**
1. Route permissions named with `.all` would have refused everyone; routes now name `resource.action` only (A; confirmed in `AuthorizeAction.php:19`).
2. `row()` would have sent the file path, and the note, to managers; no path column at all now, and `show()` filters by `employee_document.view` (A + B).
3. A named Docker volume would be wiped by `docker compose down -v`, which the repo tells developers to run; dropped, files stay on the bind mount (A + B).
4. Step 1 would have broken the live Documents tab (the old PUT); the PUT now leaves in step 2 (A + B).
5. Upload order and cleanup were undefined; now id → file → transaction → delete file on failure, with a test (A + B).
6. The photo shrink was ordered after the size check, which would refuse the photos it exists for; also PNG transparency → black, a bigger result, the wrong extension; all fixed (B).
7. The 6–8 MB gap gave a "failed to upload" message and the 5 MB limit doesn't fit scanned PDFs; D4 now offers 10 MB with aligned PHP limits and mapped messages (B + UI/security reviewer).
8. Download name now uses the detected extension (polyglot); bidi control characters stripped from file names (UI/security reviewer).
9. Part 1 overstated "undo" and missed the third manager outcome; D2 and D3 rewritten (A + B).

**Consider (left to the owner)**
- A view log for documents (D8): low cost, matters if the client cares about the data-protection law.
- A Restore button (D3c).

**Noted**: race between two simultaneous replaces (now `lockForUpdate`, as `AttendanceDays.php:309`); `original_name` length; 403 vs 404 named in tests; delete through the model only; wrong file references fixed.

**Dismissed / cut as over-building**: the expiry-days setting (constant until Release 3), `%%EOF`/`getimagesize` checks, drag-and-drop, CSP presented as a protection.

**Agreement map**: both reviewers independently found the manager data leak, the volume risk, the step-1 breakage and the write-order gap — the highest-signal findings. Only A traced the permission-prefix bug; only B traced the photo-shrink order and the locale test. No finding was contradicted by the other reviewer.

### UI/UX takeaways checklist
| # | Takeaway | Verdict |
|---|---|---|
| 1 | One hero metric | N/A, not a dashboard |
| 2 | Next carousel card peeks | ⚠️ the tab strip scrolls sideways at 375 px; check in the step 2 gate that the next tab stays partly visible after adding "· N" |
| 3 | Connect multi-step flows | N/A, upload is one step |
| 4 | Fewer options up front | ✅ 9 kinds in one select; the missing links preset it |
| 5 | One obvious main action | ✅ Upload is the only solid button |
| 6 | Never two equal buttons | ✅ after fix: the header button hides when the list is empty, so the empty state's button is the only one |
| 7 | Hide what isn't relevant yet | ✅ after fix: no missing line and no header button on an empty list; managers see no upload controls |
| 8 | One flat accent | ✅ accent only on Upload; red/amber only on real expiry states |
| 9 | No decorative colour-coding | ✅ no icon tiles per kind |
| 10 | Hairlines, shadows only on floating things | ✅ card hairline; only dialogs and the ⋮ menu float |
| 11 | Radius scale | ✅ dialog `rounded-xl`, inputs `rounded-lg`, menu items `rounded-md` |
| 12 | ~70% modal backdrop | ✅ `--scrim` is `rgb(0 0 0 / 0.7)` (`index.css:130`) |
| 13 | Real titles, no placeholder copy | ✅ "Documents · 5", "Expires in 12 days" |
| 14 | Deltas vs a named baseline | N/A |
| 15 | Tabular figures | ✅ `tabular-nums` on sizes and dates |
| 16 | Fill in what you know | ✅ kind preset from the missing links; Replace prefills kind, expiry, note |
| 17 | Warnings inline, not as banners | ✅ the missing line and expiry tags sit in the list |
| 18 | 16 px inputs, right keyboard hints | ✅ global 16 px on coarse pointers (`index.css:345`); `enterkeyhint` on the note |
| 19 | Tables → cards under 640 px | ✅ `DataList` |
| 20 | Big numbers on one line | N/A |
| 21 | 44 px touch targets on coarse pointers | ✅ `--tap` 44 px; ⋮ is 44×44 on touch; links inside a sentence are exempt (WCAG 2.5.8) |
| 22 | Safe-area padding | ✅ after fix: the full-screen viewer pads top and bottom insets |
| 23 | No "AI-made" tells | ✅ no gradients, rainbow tiles, uniform cards or placeholder copy |
| 24 | ✕/✓ pairs with a number | see below |

✕/✓ pairs:
- **Empty tab:** ✕ header Upload + empty-state Upload = **2** equal main buttons · ✓ empty-state Upload only = **1**.
- **Phone photo:** ✕ a 9 MB photo refused at the 5 MB limit = **0** uploads · ✓ shrunk to ~0.6 MB before sending = **1** upload, ~15× less data on site mobile networks.
- **Manager payload:** ✕ first draft sent the storage path and note = **2** private fields per document · ✓ kind and expiry only = **0**.

### Security checklist (OWASP file upload, applied)
| Item | Status |
|---|---|
| Allow-list of types, checked against the bytes | ✅ `File::types` (finfo sniff); SVG/HTML renames tested |
| Random storage name, no client name in path | ✅ `{employee_id}/{id}.{ext}` from the detected type |
| Outside the web root, never served directly | ✅ private disk, `serve => false`, nginx doesn't serve it |
| Size limits with clear messages | ✅ app < PHP < nginx, `uploaded` and 413 mapped |
| Authorization on every read, no IDOR | ✅ permission + manager reach; document resolved through the employee |
| Safe rendering in the app (token lives in localStorage) | ✅ Blob rebuilt with an allow-listed type; img/iframe by type |
| Download name can't change the type | ✅ detected extension; bidi controls stripped |
| Audit of writes | ✅ `Audited`, through the model |
| Client files out of the public repo | ✅ git-ignored folder; fake fixtures only |
| Virus scan, EXIF stripping, view log | ⏭ skipped with reason (D8 for the view log) |

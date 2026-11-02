# UI refresh: Gate 0 findings

Baseline: `.claude/gate-ui-0/` in the main checkout, captured 2026-11-02. 15 screens × 375/1024/1440 × ar/en × light/dark = 180 shots: 0 failed, 0 page overflow, 0 console errors, 0 failed requests. Read for this audit: ar-light at 375 and 1440 for every screen, plus ar-dark at 1440 for the employees list and the day sheet. The 375 shots ran with a **fine** pointer (see the capture caveat in the design doc), so their 44 px targets come from today's `max-sm:` rules.

Format (takeaway 24): each finding is a ✕/✓ pair with one number each, the takeaway number (#) and the slice that fixes it. "HO" is the hand-over to the other developer. Pixel positions are from the full-page shots at a 812 px phone viewport and a 900 px desktop viewport; "est." marks an estimate.

## Every screen (kit-level)

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| Shadow under every card on 15 of 15 screens (`card.tsx` `shadow-1`, plus 5 page uses) | 0 resting shadows; shadows only on 6 floating layers (`shadow-float`) | 10 | UI-1 |
| Card radius 8 px, inputs and buttons 6 px, badges fully round | Cards 12, inputs and buttons 8, badges 6 | 11 | UI-1 |
| Modal backdrop at `brightness(0.45)` ≈ 55% veil in light, almost no change in dark | `bg-black/70` = 70% in both themes | 12 | UI-1 |
| 44 px targets at < 640 px on any pointer; a 768–1024 px touch tablet gets 32–36 px | 44 px on every coarse pointer at any width; 36 px (≥ 24 px floor) with a mouse | 21 | UI-1 |
| 16 px fields only below 640 px; iPad gets 14 px and zooms | 16 px on every coarse pointer, raw inputs included | 18 | UI-1 |
| `cn()` drops 2 classes today: `text-table` (`SegmentedControl.tsx:32`) and `text-card-title` (`attendance-import.tsx:194`) | 0 dropped (`extendTailwindMerge` + `theme.test.ts`) | — (bug) | UI-1 |
| The same primary button is orange (white text 5.18:1) in light and indigo (navy text 6.42:1) in dark | 1 hue in both themes | 8 | Owner decision 1 |
| 7 hand-drawn banners in 4 styles | 1 `Alert`, 5 tones | 17 | UI-2, UI-3 |

## employees (other developer)

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| 5 equal stat cards, each with a tinted icon tile (5 hues) | 1 hero (30 px) + 4 secondary, 0 icon tiles | 1, 9, 20 | UI-1 (tiles neutral), HO |
| Stats with no date | 1 "as of" line on the hero | 13 | HO |
| "Add employee" 130 px wide at 375 | 343 px, full width | 5 | UI-2 + HO |
| 3 of 6 fields visible per row at 375 (number, department and hire date hidden) | 6 of 6 as label/value lines | 19 | UI-2 + HO |
| 2 rows visible in the first screen at 375 | ≥ 2 (stats no taller; est.) | 19 | HO |

Dark: borders already carry the surfaces (the dark `shadow-1` is invisible on `#0b0f19`); the five tile hues read as five accents next to the indigo primary.

## employee detail (other developer)

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| Save sits 2,200 px down at 375 (≈ 2.7 screens), not sticky | Save in a sticky `ActionBar`, 0 px away | 5, 22 | HO |
| The only top button is an outline "Assign manager"; the page has no visible main action above the fold | 1 solid main action | 5 | HO |
| Phone field is plain text (no `tel` keyboard); 2 number fields without `inputMode` | 3 fields with the right keyboard | 18 | HO |

## attendance: period grid

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| The period appears only in the toolbar, 1 card below the title | Period in the line under the title | 13 | UI-3 |
| "108 days missing" tile with an amber icon tile, squeezed beside the Lock button | 0 tiles; the count joins the period line | 9, 1 | UI-3 |
| Lock is solid on all 3 tabs, so the import tab shows 2 solid buttons | Lock solid on the grid tab only; 1 solid per tab | 5, 6 | UI-3 |
| Grid cells are 44 px tall with a mouse (`min-h-11` on every pointer) | 36 px with a mouse (≈ 20% more rows per screen, est.), 44 px on touch | 21 | UI-3 |
| 3 of 30 day columns visible at 375; 17 of 30 at 1440 | Unchanged: a 2-D matrix with a sticky first column | 19 | Owner decision 5 |
| 108 of 150 cells amber "not recorded" | Kept: a real category | 9 (met) | — |

## attendance: day sheet

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| 0 employee rows in the first screen at 375 (first card at 1,410 px) and at 1440 (first row at ≈ 1,000 px) | First row ≥ 300 px higher (est.; StatRow, header tile gone, shortcuts collapsed); measure at the UI-3 gate | 1, 7 | UI-3 |
| 5 KPI tiles at one size; the "total" hero differs only by an accent border | 1 hero at 30 px + 4 secondaries at 18 px | 1, 20 | UI-2, UI-3 |
| 4 tinted icon tiles on the KPI tiles | 0; the tone stays on the number (real categories) | 9 | UI-3 |
| 8 keyboard-shortcut chips always visible at 1440 | 1 closed "Shortcuts" row | 7 | UI-3 |
| "Mark rest present (4)" is an outline button among Paste and Undo: 0 solid buttons on the sheet | 1 solid main action | 5 | UI-3 |
| 4 equal buttons per row at 1440; 5 per card at 375 (Details + 4 statuses), 25 for 5 people | 2 per row (est., if decision 3 passes) | 4, 6 | Owner decision 3, UI-3 |
| Bulk bar at the top of the list, 0 px from the rows it acts on only while scrolled up | Sticky bottom bar, safe-area padded | 22 | Owner decision 4, UI-3 |
| 10 hard-coded heights (`h-11 md:h-9`) in the page, 7 in its rows file | 0 | 21 | UI-3 |

## attendance: import

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| 2 solid buttons on screen (header Lock, Preview changes) | 1 (Preview; Lock becomes ghost) | 5, 6 | UI-3 |
| 3 steps (download template → choose file → preview) shown as loose buttons | 1 numbered 1-2-3 list in the one card | 3 | UI-3 |
| Changes and errors drawn twice by hand (table ≥ xl, cards below) | 1 `DataList` each, cards below 640 px | 19 | UI-3 |
| Errors card title at 14 px (the `cn()` bug) | 16 px | — | UI-1 |

## settings: sites (and the default settings route)

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| "Add site" is a 32 px outline `sm` button; 0 solid buttons on the tab | 1 solid main action | 5 | UI-3 |
| 2 of 3 fields visible per row at 375 ("outside Alexandria" hidden) | 3 of 3 | 19 | UI-3 |
| 3 of 6 tabs visible at 375, the 4th clipped mid-label | Kept: the clipped tab signals the scroll | 2 (met) | — |

## settings: residences

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| "Add residence" outline, 0 solid buttons | 1 solid | 5 | UI-3 |
| 1-column list rendered as a table with a header row | `DataList` (same look, 1 owner of loading/empty/pagination) | 19 | UI-3 |

## settings: fares

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| 20 of 20 empty cells filled with the accent tint | 0 accent-filled empty cells; neutral dashed border | 8 | UI-3 |
| 2 of 5 site columns visible at 375 | Unchanged: a 2-D matrix with a sticky first column | 19 | Owner decision 5 |

## site-day (other developer; `feature/manager-attendance`)

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| 4 equal outline buttons × 5 cards = 20 equal buttons | The pressed mark toned, the rest outline | 6 | HO |
| Buttons 44 px with a mouse at 1440 (`h-11`/`size-11`) | 36 px with a mouse, 44 px on touch | 21 | HO |
| 5 cards with their own `shadow-1` | 0 (inert after UI-1; the class is deleted in HO) | 10 | UI-1, HO |
| The site picker is read before the `h1` | Title first, then the date line, then the picker | 13 | HO |
| Date line "Monday 2 November · Today" | Kept | 13 (met) | — |

## console: accounts

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| "Add account" 130 px wide at 375 | 343 px, full width | 5 | UI-2, UI-3 |
| 3 of 4 fields visible per row at 375 (email hidden) | 4 of 4 | 19 | UI-3 |

## console: roles

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| With a role open: 2 solid buttons ("Add role", "Save changes"; `console.tsx:236`, `335`) | 1 solid (Save), Add role ghost | 6 | UI-3 |
| At 1440, an 824 px-wide panel holds 1 sentence ("choose a role") | 0 empty panels: the first role opens by default from lg | 7 | UI-3 |
| A third segmented style at 28 px (`h-7` radio pills) | 1 style (`SegmentedControl`), 44 px on touch | 21 | UI-3 |

## console: audit

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| Details button 36 px at 375 (`max-sm:size-9` deletes the kit's 44 px; confirmed in tailwind-merge) | 44 px on touch | 21 | UI-1 (floor), UI-2 |
| 2 of 5 fields visible per row at 375 (entity type and id hidden) | 5 of 5 | 19 | UI-2 |
| Time and event pill each wrap onto 2 lines in 20 of 20 rows at 375 | 1 line each (time as the card heading, pill on its own line) | 19, 20 | UI-2 |
| 4 stacked filters push the first row to 545 px at 375 (3 rows in the first screen) | Unchanged in this refresh; revisit if HR filters rarely | 7 | — |

## login

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| Card with a shadow | Border only | 10 | UI-1 |
| Password field has no `enterKeyHint` | `go` (1 tap to submit) | 18 | UI-3 |
| Full-width solid "Sign in", `autocomplete` username/current-password | Kept | 5, 18 (met) | — |

## not found

| ✕ now | ✓ after | # | Slice |
|---|---|---|---|
| The only action is a ~20 px text link | 1 solid button, 36 px (44 px on touch) | 5 | UI-3 |
| Real title, no placeholder copy | Kept | 13 (met) | — |

## Not captured in Gate 0

Holidays, leave types and work-rules tabs; the day editor sheet; every dialog and sheet; the paste dialog. The UI-3 gate must capture them. Known from code (how.md): the holidays delete button is `h-11` on every pointer (#21), the day editor builds its own sheet header and footer and four `bg-muted` notes (#17), and the paste dialog footer has 3 equal outline buttons and no primary (#6).

## The 24 takeaways

| # | Takeaway | Status |
|---|---|---|
| 1 | One hero metric | **To fix**: UI-2 (`StatRow`), UI-3 (day sheet), HO (employees) |
| 2 | Next card peeks in carousels | **Met**: the day strip and the settings tab strip clip the next item mid-way |
| 3 | Join the steps of a flow | **To fix**: UI-3 (import steps as a numbered list) |
| 4 | Fewer options up front | **To fix**: owner decision 3, then UI-3 (day-sheet row buttons) |
| 5 | One obvious main action | **To fix**: UI-2 (`PageHeader action`), UI-3 (sheet, settings tabs, holidays, not-found), HO (employee detail) |
| 6 | No two equal-weight buttons | **To fix**: UI-3 (roles, import, paste dialog, day-sheet rows), HO (site-day) |
| 7 | Hide advanced options; empty state shows only its action | **To fix**: UI-3 (shortcut chips, roles empty panel) |
| 8 | One flat accent | **Exception pending**: precedence rank 3 (the project's CRM tokens, ADR 0001) outranks the house rule (rank 4) unless the owner chooses one hue (decision 1). Fares' accent-filled empty cells: to fix, UI-3 |
| 9 | No colour-coding of non-categories; no icon tiles | **To fix**: UI-1 (`StatCard` tones neutral), UI-3 (day-sheet tiles, attendance header tile). Status colours on attendance cells and KPI numbers are real categories: met |
| 10 | Borders over shadows | **To fix**: UI-1 (`--shadow-*: initial`) |
| 11 | Radius scale 12 / 8 / 6 | **To fix**: UI-1 |
| 12 | ~70% modal backdrop | **To fix**: UI-1 |
| 13 | Real title + explicit period | **To fix**: UI-3 (attendance), HO (employees "as of", site-day order). Copy: met (no placeholder copy anywhere) |
| 14 | Deltas against a named baseline | **n/a**: no screen shows a delta yet (`Stat.detail` carries one when it does) |
| 15 | Tabular figures; right-aligned money | Tabular: **met** globally (`index.css:269`). Alignment: **to fix**, UI-2 (`numeric`), UI-3 (sites), HO (pay history); direction is owner decision 2 |
| 16 | Fill in what you know | **Met**: login autocomplete; group and period default from the URL and today |
| 17 | Inline warnings styled like content | Placement **met** (all inline). Styling: **to fix**, UI-2 (`Alert`), UI-3 |
| 18 | 16 px fields, `inputmode` / `autocomplete` / `enterkeyhint` | 16 px: **to fix** (mechanism), UI-1. Attributes: **to fix**, UI-1 (search), UI-3 (login), HO (phone, bank, insurance) |
| 19 | Tables become cards below 640 px | **To fix**: UI-2 (`DataList`), UI-3, HO. The 3 matrices: **exception requested**; no precedence rank decides it, so the owner signs off (decision 5) |
| 20 | Large figures on one line, ~30 px on phones | **To fix**: UI-2 (`text-kpi` 30 px, nowrap) |
| 21 | 44 px only for coarse pointers | **To fix**: UI-1 (`--tap`), UI-3 (35 page height lines), HO (site-day). The 24 px fine-pointer floor is rank 2 (WCAG 2.2 AA) and is kept |
| 22 | Safe-area padding on fixed bottom bars | **Met** today (no fixed bars; dialogs, sheets and the shell pad the insets). New bars use `ActionBar` (UI-2) |
| 23 | Check for "AI-made" tells | **To fix**: tells found are rainbow icon tiles (employees 5, day sheet 4, attendance 1), uniform cards (5 equal stat cards twice) and shadows everywhere; UI-1 to UI-3 remove them, and each gate repeats the check |
| 24 | ✕/✓ pairs with one number | **Met** by this document |

Takeaways 18–22 are **unverified** in the browser until the UI-1 gate re-captures with a coarse pointer at 375 px (`hasTouch`, see the design doc).

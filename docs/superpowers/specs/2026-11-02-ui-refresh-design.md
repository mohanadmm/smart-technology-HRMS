# UI refresh: design

Status: synthesis of architect Phase B, 2026-11-02. Not yet approved by the owner.
Branch `feature/ui-refresh` (main @ 7be93b9). All paths are under `frontend/src/` unless they start with `frontend/` or `.claude/`.
Inputs: Phase A (`.claude/ui-refresh/how.md`), sketch A (tokens first), sketch B (components first), the house takeaways (`~/.claude/skills/uiux/uiux-notes.md`, "Combined takeaways to act on", numbered 1–24 below), and the Gate 0 captures (`.claude/gate-ui-0/`, 180 shots, 0 failed, 0 overflow). The per-screen audit is in `2026-11-02-ui-refresh-findings.md`.

Checked for this synthesis (the worktree has no `node_modules`, so these ran against the main checkout's install, which pins the same versions):
- tailwind-merge 3.7.0: `cn()` drops `text-table` from `layout/SegmentedControl.tsx:32` (**bug confirmed**), and also drops `text-card-title` from `pages/attendance-import.tsx:194` (`<CardTitle className="text-destructive">`). `audit.tsx:151`'s `max-sm:size-9` removes the kit's 44 px phone height (confirmed). The `extendTailwindMerge` list below keeps every case.
- Tailwind 4.3.3 compile: after `--shadow-*: initial`, `shadow-1` and `shadow-sm` are not generated; `shadow-float`, `shadow-none`, `ring-1`, `min-h-tap`, `min-w-tap`, `text-kpi`, `pointer-coarse:h-11` and `bg-black/70` are.
- Playwright (the copy `capture.mjs` uses): a plain context at 375 px reports `(pointer: coarse)` **false**; `hasTouch: true` reports it true.

## Problem

House takeaways 1, 5, 6, 8–13, 15 and 17–22 have to reach 15 screens. Three constraints make the shape non-obvious:

1. Two pages belong to the other developer: `pages/employees.tsx` (on main, and changed again on `feature/manager-attendance`) and `pages/site-day.tsx` (only on that branch). This branch must not edit them. They change through the kit or through a hand-over list.
2. Today the 44 px rule depends on viewport width (`max-sm:`), not on the pointer. It is copied into about 15 kit class strings and 35 page lines (in 9 files), and `mobile.test.tsx` pins those class names. Page heights such as `h-11 md:h-9` win over any prefixed kit class, so a kit change alone cannot make desktop dense.
3. `cn()` is plain `twMerge`. It does not know the project's theme names, so a kit-versus-caller merge can silently drop a class. That happens today (see above).

## Usage (caller's view)

A list page after UI-3 (`audit.tsx`). No table tree, no height, shadow, radius or breakpoint class:

```tsx
<PageHeader title={t('page.audit')} description={t('audit.subtitle')} />
<Card>
  <div className="space-y-3 border-b p-4">{/* filters, unchanged */}</div>
  <DataList
    label={t('page.audit')}
    rows={rows}
    rowKey={(r) => r.id}
    title={{ header: t('audit.time'), cell: (r) => time(r.occurred_at) }}
    status={{ header: t('audit.action'), cell: (r) => <Badge variant={EVENT_VARIANT[r.event] ?? 'neutral'}>{label.event(r)}</Badge> }}
    fields={[
      { header: t('audit.actor'), cell: (r) => <Actor user={r.user} /> },
      { header: t('audit.entityType'), cell: label.entity, tableFrom: 'md' },
      { header: t('audit.entityId'), cell: (r) => <bdi dir="ltr" className="font-mono text-xs">{r.entity_id}</bdi>, tableFrom: 'xl' },
    ]}
    actions={(r) => <Button variant="ghost" size="sm" onClick={() => setOpen(r)}>{t('audit.details')}</Button>}
    loading={loading}
    empty={{ icon: ScrollText, title: t('common.noResults') }}
    page={{ page, pageSize: PER_PAGE, total, onPageChange: setPage }}
  />
</Card>
```

At 375 px each row is a card: the time is the heading, Details sits top-end, the event pill sits under the heading, then `Actor … name`, `Entity … type` and `Entity ID … uuid` as label/value lines. From 640 px up it is a table.

A header with one main action, and the day-sheet KPIs (`attendance.tsx`, `attendance-sheet.tsx`):

```tsx
<PageHeader
  title={t('nav.attendance')}
  description={t('attendance.periodLine', { from, to, missing: period?.missing })}   // rule 13: the period sits under the title
  action={tab === 'grid' && canLock ? { label: t('attendance.lock'), icon: Lock, onClick: () => setDlg('lock') } : undefined}
  secondary={tab !== 'grid' && canLock ? [{ label: t('attendance.lock'), icon: Lock, onClick: () => setDlg('lock') }] : undefined}
/>
{period?.is_locked && <Alert tone="info" icon={Lock}>{t('attendance.sheet.locked')}</Alert>}

<StatRow
  label={t('attendance.sheet.kpis')}
  hero={{ label: t('attendance.sheet.kpi.total'), value: rows.length, detail: t('attendance.sheet.kpi.accounted', { done, total }),
          progress: done / rows.length, select: { pressed: filter === 'all', onSelect: () => pick('all') } }}
  stats={KPIS.map(([f, label, look]) => ({ label: t(label), value: count(f), tone: TONE[look], select: { pressed: filter === f, onSelect: () => pick(f) } }))}
/>
```

The one rule for kit authors: **a fine-pointer size goes in `h-*`, `w-*` or `size-*`; the touch floor goes only in `min-h-tap` / `min-w-tap`.** Pages never set a control height.

## Shape

### Token diff (`index.css`)

```diff
 :root {
   color-scheme: light;
   --radius: 0.5rem;
+  /* Smallest hit area: 24 px is the WCAG 2.2 AA floor on any pointer; 44 px on touch (rule 21). */
+  --tap: 1.5rem;
   …
-  --elevation-1: 0 1px 2px 0 rgb(0 0 0 / 0.06), 0 1px 3px 0 rgb(0 0 0 / 0.1);
   --elevation-2: …unchanged
 }
 .dark {
   …
-  --elevation-1: 0 1px 2px 0 rgb(0 0 0 / 0.4), 0 1px 3px 0 rgb(0 0 0 / 0.3);
   --elevation-2: …unchanged
 }
+/* Rule 21: 44 px targets for coarse pointers only; mouse layouts stay dense. */
+@media (pointer: coarse) {
+  :root { --tap: 2.75rem; }
+}
+/* Rule 10: the only shadow utility is the floating-layer one. */
+@theme {
+  --shadow-*: initial;
+}
 @theme inline {
   …
-  --shadow-1: var(--elevation-1);
-  --shadow-2: var(--elevation-2);
+  --shadow-float: var(--elevation-2);
+  --spacing-tap: var(--tap);          /* min-h-tap, min-w-tap */
+  --text-kpi: 1.875rem;               /* rule 20: the hero figure, 30 px at every width */
+  --text-kpi--line-height: 1.15;
+  --text-kpi--font-weight: 600;
   --radius-sm … --radius-xl            /* unchanged: rounded-md = 6, rounded-lg = 8, rounded-xl = 12 */
 }
 @layer base {
   …
+  input:is([type='checkbox'], [type='radio']) { accent-color: var(--primary); }
 }
+/* Rule 18: iOS zooms into a field under 16 px, on iPhone and iPad. Unlayered, so no caller's text-xs can undo it. */
+@media (pointer: coarse) {
+  :is(input:not([type='checkbox'], [type='radio'], [type='range'], [type='file'], [type='color']), textarea, select) { font-size: 16px; }
+}
```

Colour tokens are not touched. The accent split is an owner decision (decision 1).

### `lib/utils.ts`

```ts
import { extendTailwindMerge } from 'tailwind-merge'

/** Every custom @theme name the kit uses. theme.test.ts fails when index.css and this list disagree. */
export const THEME_NAMES = {
  text: ['body', 'table', 'page-title', 'section-title', 'card-title', 'kpi'],
  shadow: ['float'],
  spacing: ['tap'],
}
const merge = extendTailwindMerge({ extend: { theme: THEME_NAMES } })
export function cn(...inputs: ClassValue[]) { return merge(clsx(inputs)) }
```

Checked in node: `text-table text-muted-foreground` keeps both; `size-9 min-h-tap min-w-tap max-sm:size-9` keeps all four; `min-h-tap min-h-0` gives `min-h-0` (an explicit opt-out still works); `shadow-float shadow-none` gives `shadow-none`.

### UI-1 kit class edits (classes only, no new API)

| File | Before → after | Rules |
|---|---|---|
| `ui/button.tsx` | base `rounded-md` → `rounded-lg min-h-tap`; drop every `max-sm:h-11` and the repeated `rounded-md` in sizes; `icon` `size-9 max-sm:size-11` → `size-9 min-w-tap`; `icon-sm` → `size-8 min-w-tap` | 11, 21 |
| `ui/input.tsx` (Input, Textarea) | `rounded-md` → `rounded-lg`; `max-sm:h-11 max-sm:text-base` → `min-h-tap` (Textarea: drop `max-sm:text-base`) | 11, 18, 21 |
| `ui/select.tsx` | trigger as Input; `SelectItem` `max-sm:min-h-11` → `min-h-tap`; `shadow-2` → `shadow-float` | 10, 11, 21 |
| `form.tsx` | `selectCls` as Input. `TimeInput` (`172-180`): `h-11 … sm:h-9` → `h-9 min-h-tap`, AM/PM `min-w-11 … sm:min-w-9` → `w-9 min-w-tap`, frame `rounded-md` → `rounded-lg` | 11, 21 |
| `ui/card.tsx` | `rounded-lg … shadow-1` → `rounded-xl …`, no shadow | 10, 11 |
| `ui/badge.tsx` | `rounded-full` → `rounded-md`; delete the unused `violet` variant (and its `StatusBadge` dot entry) | 8, 11 |
| `ui/dialog.tsx` | overlay `backdrop-brightness-[0.45] backdrop-blur-[2px]` → `bg-black/70`; content `shadow-2` → `shadow-float`, `rounded-t-xl … sm:rounded-xl` unchanged (already 12); close button `size-8 min-h-tap min-w-tap`, drop its `max-sm:` set; header `pe-12 max-sm:pe-14` → `pe-14` | 10, 12, 21 |
| `ui/sheet.tsx` | overlay → reuse `<DialogOverlay />` (the copied string at `20` goes); `shadow-2` → `shadow-float`; close `size-11` → `size-9 min-h-tap min-w-tap` | 10, 12, 21 |
| `ui/tabs.tsx` | list: drop `h-10 max-sm:h-auto`; trigger `h-8 max-sm:h-11 … data-[state=active]:shadow-1` → `h-8 min-h-tap … data-[state=active]:ring-1 data-[state=active]:ring-border` | 10, 21 |
| `ui/dropdown-menu.tsx` | items `max-sm:min-h-11` → `min-h-tap`; `shadow-2` → `shadow-float` | 10, 21 |
| `ui/misc.tsx` | tooltip `shadow-2` → `shadow-float` | 10 |
| `layout/SegmentedControl.tsx` | `h-8 min-w-8 max-sm:h-11 max-sm:min-w-11` → `h-8 min-h-tap min-w-tap`; active `shadow-1` → `ring-1 ring-border`; `text-table` now applies (13 px) | 10, 21 |
| `layout/NotificationBell.tsx` | `size-10 max-sm:size-11` → `size-10 min-h-tap min-w-tap` | 21 |
| `layout/AppLayout.tsx` | `:134` nav `min-h-11` → `min-h-tap py-2`; `:188` collapse `size-9` + `min-h-tap min-w-tap`; `:216` `max-sm:min-h-11` → `min-h-tap`; `:312` `shadow-2` → `shadow-float` | 10, 21 |
| `cards/StatCard.tsx` | every `Tone` maps to `bg-muted text-muted-foreground`; `rounded-xl` stays; mark `MiniStat` and `Tone` `@deprecated → StatRow` | 9 |
| `common/Filters.tsx` | `SearchInput`: `type="search" enterKeyHint="search"` | 18 |

UI-1 edits no page and adds no i18n key. Three things reach the other developer's pages with no edit: the `shadow-1` in `site-day.tsx:56` and the day-sheet KPI tiles stop drawing (the utility no longer exists), and the five tinted icon tiles on `employees.tsx` turn neutral.

### UI-2 components (signatures)

No component adds an i18n key; every label comes from the caller's `t()`. None takes `className`: a class prop is how pages bypassed the kit before.

```tsx
// components/common/DataList.tsx
/** One column. Its header is also the label on the phone card, so the card can never lose a label. */
export type Field<T> = {
  header: string
  cell: (row: T) => ReactNode
  /** Money and counts: end-aligned header and cell, nowrap, in both layouts (rule 15). */
  numeric?: boolean
  /** Table layout only: the column shows from this width up. Cards always show it. */
  tableFrom?: 'md' | 'lg' | 'xl'
}
export type DataColumns<T> = {
  /** First table column; the card's heading, with no label. */
  title: { header: string; cell: (row: T) => ReactNode }
  /** Label/value lines on the card; the middle columns of the table. */
  fields?: Field<T>[]
  /** One pill: the last data column in the table, at the top of the card under the heading. */
  status?: { header: string; cell: (row: T) => ReactNode }
  /** The trailing cell (sr-only header `common.actions`); top-end of the card. */
  actions?: (row: T) => ReactNode
}
export type DataListProps<T> = DataColumns<T> & {
  label: string                       // the table's accessible name
  rows: readonly T[]                  // already the current page
  rowKey: (row: T) => string
  loading?: boolean
  error?: unknown                     // a danger Alert above the list; suppresses the empty state
  empty?: { icon: LucideIcon; title: string; description?: string; action?: ReactNode }
  page?: { page: number; pageSize: number; total: number; onPageChange: (p: number) => void }  // Pagination only when total > pageSize
}
/**
 * One render, two layouts, switched by CSS at 640 px. From sm up: a ui/table. Below sm: each <tr> is a grid block
 * [title | actions] / [status] / [label … value]*, separated by hairlines inside the page's Card (not a card in a card).
 * Each field cell carries <span aria-hidden className="sm:hidden">{header}</span> as its visible label; <thead> stays as
 * max-sm:sr-only, and explicit role="table|rowgroup|row|columnheader|cell" keep the table semantics that Safari drops
 * when display changes. States in order: loading → TableSkeleton, error → Alert, no rows → EmptyState, else rows + Pagination.
 * No selection, sorting or row click: the day sheet keeps its own rows. `tableFrom` uses a literal class map
 * ({ md: 'sm:max-md:hidden', lg: 'sm:max-lg:hidden', xl: 'sm:max-xl:hidden' }) so Tailwind sees the classes.
 */
export function DataList<T>(props: DataListProps<T>): ReactNode
```

`ui/table.tsx` stays for the three 2-D matrices (attendance grid, fare matrix, permission matrix) and the day-sheet rows. Its file gets one doc line: "Lists use DataList; this is for matrices."

```tsx
// components/common/PageHeader.tsx (replaces the current one; `eyebrow` has no caller and goes)
export type HeaderAction = { label: string; icon?: LucideIcon; disabled?: boolean }
  & ({ onClick: () => void; to?: never } | { to: string; onClick?: never })
type Current = {
  /** The screen's one main action: always a solid default Button, full width below 640 px (rules 5, 6). */
  action?: HeaderAction
  /** At most two alternatives, rendered as ghost buttons before the main one. */
  secondary?: [HeaderAction] | [HeaderAction, HeaderAction]
  actions?: never
}
/** @deprecated Pass `action`. Kept only until employees.tsx moves (hand-over item 3); delete in that PR. */
type Legacy = { actions: ReactNode; action?: never; secondary?: never }
export type PageHeaderProps = {
  title: string
  /** One plain line under the title. Put the explicit period or "as of" date here (rule 13). */
  description?: ReactNode
} & (Current | Legacy)
export function PageHeader(props: PageHeaderProps): ReactNode
// { to } → <Button asChild><Link to={to}>; { onClick } → <Button onClick>. A dialog opened from the header is a
// controlled FormDialog (`Controlled`, form.tsx:231), as sites.tsx already does for row dialogs.
```

```tsx
// components/common/StatRow.tsx (replaces MiniStat; MiniStat is deleted when employees.tsx moves)
export type StatTone = 'success' | 'warning' | 'destructive' | 'info'
export type Stat = {
  label: string
  value: ReactNode
  /** One plain line under the value: an "as of" date, a baseline delta ("+3 vs Sep"), a count. */
  detail?: ReactNode
  /** Makes the tile a filter toggle: <button aria-pressed>. A pressed tile gets border-primary + ring-1 (a non-colour cue). */
  select?: { pressed: boolean; onSelect: () => void }
}
export type StatRowProps = {
  label: string                                   // role="group" name
  /** The one big number: text-kpi (30 px), nowrap, untoned. */
  hero: Stat & { progress?: number }              // 0..1 bar under the value (the day-sheet total keeps its bar)
  /** Secondary tiles, text-lg (18 px), nowrap; tone colours the number only, for real categories (rule 9). No icon prop. */
  stats?: (Stat & { tone?: StatTone })[]
}
/** Below lg: the hero spans the row and the stats sit 2-up under it. From lg: a 3fr/2fr split, stats 2-up on the right.
 *  Tiles are rounded-xl border bg-card, no shadow. */
export function StatRow(props: StatRowProps): ReactNode
```

```tsx
// components/common/Alert.tsx (replaces sites.tsx `Notice` and the 7 hand-drawn banners)
export type AlertProps = {
  tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger'
  icon?: LucideIcon            // default per tone, aria-hidden; the text carries the meaning
  title?: string
  children: ReactNode
}
/** Inline, styled like content (rule 17): rounded-lg border px-4 py-3 text-sm, {tone}-bg {tone}-border; the tone colour on
 *  the icon and title only, the body stays text-foreground. Roles: danger → "alert"; success → "status" (it appears after
 *  an action); neutral, info and warning → none (a static note must not interrupt a screen reader). */
export function Alert(props: AlertProps): ReactNode
// form.tsx ErrorBanner keeps its signature (31 calls, employees.tsx among them) and renders <Alert tone="danger">.
```

```tsx
// components/ui/sheet.tsx: two thin exports, mirroring DialogHeader/DialogFooter (attendance-day.tsx:52/146, audit.tsx:46/54)
export function SheetHeader(props: React.ComponentProps<'div'>): ReactNode   // border-b px-5 py-4 pe-14
export function SheetFooter(props: React.ComponentProps<'div'>): ReactNode   // mt-auto border-t px-5 py-3; flex-col-reverse, sm:flex-row sm:justify-end

// components/layout/ActionBar.tsx (rule 22)
/** sticky bottom-0 z-10 flex flex-wrap items-center gap-2 border-t bg-card px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))],
 *  role="group". Sticky, not fixed: it keeps its height in the flow, so the last row always scrolls clear with no spacer. */
export function ActionBar(props: { label: string; children: ReactNode }): ReactNode
```

`ActionBar` has three named callers: the day-sheet bulk bar (UI-3, if the owner approves decision 4), the employee-detail Save (hand-over) and the site-day marking bar (hand-over). If the owner keeps the bulk bar at the top, `ActionBar` moves into the other developer's first PR instead of UI-2.

`EmptyState`: signature unchanged; the icon bubble `rounded-2xl ring-8 ring-muted/40` → `rounded-xl`, no ring. Rule 7 is a usage rule: pass `action` only when the page header has no main action.

### What the design does not do

- No colour token changes (decision 1). No retuning of `--radius`: pages keep their own `rounded-*`, and the kit uses `rounded-md` 6 / `rounded-lg` 8 / `rounded-xl` 12.
- No kit `Checkbox`: the global `accent-color` line covers the 7 raw checkboxes; their 44 px comes from the wrapping label's `min-h-tap min-w-tap`.
- No sorting, selection or virtualisation in `DataList`.
- No sparklines (no trend data yet).

## Synthesis decision

The sketches are layers, not rivals: A is right about the token layer, B about the component layer. Base: **A's token layer + B's components**. Per decision, the winner and why. "Copy test" asks what the next agent produces when it copies the nearest example.

| Decision | Winner | Why |
|---|---|---|
| 44 px mechanism | **A**: `--tap` + `min-h-tap` / `min-w-tap` | One declaration instead of ~15 `pointer-coarse:` copies. `min-h`/`min-w` are a different tailwind-merge group from `h`/`size`, so a caller's `size-9` cannot remove the touch floor. B's `pointer-coarse:h-11` repeats the `audit.tsx:151` bug: `h-9 pointer-coarse:h-11` + caller `pointer-coarse:size-9` merges to `h-9 pointer-coarse:size-9` (checked). The 24 px fine-pointer value also encodes the WCAG floor (precedence rank 2). Copy test: a copied `min-h-tap` is always right. |
| 16 px fields | **A**: one unlayered `@media (pointer: coarse)` rule | It reaches raw inputs and both of the other developer's pages, covers iPad, and no `className` can undo it. B keeps class pairs in four kit files and misses raw inputs. |
| Shadows | **A**: `--shadow-*: initial` + `shadow-float` | Deleting the utility makes every page `shadow-1` inert at once, including `site-day.tsx`, with no edit. The name says where a shadow is allowed. B only edits `card.tsx` and leaves page shadows drawing. |
| Radius | **B**: kit classes `rounded-md/lg/xl` | The existing scale already gives 6/8/12. A's `rounded-badge/control/card` add three names, a tailwind-merge registration and two ways to get 8 px (A lists this as a tradeoff). Copy test: a page that copies the kit's `rounded-xl` onto a surface gets 12, which is right. |
| `cn()` registration and guard | **A**: `extendTailwindMerge` + `THEME_NAMES` + `theme.test.ts` | It fixes a live bug (two dropped classes), and the test turns the hand-synced list into a failing build. B does not address it. |
| Hero figure size | **A**: one `--text-kpi` (30 px fixed) | Rule 20 caps the figure at about 30 px. B's three tokens and a `clamp()` grow it on desktop for no rule. Secondaries use the existing `text-lg`. |
| Stat tiles | **B**: `StatRow` with exactly one required `hero` | A's `MiniStat hero` flag allows zero or five heroes and leaves the 3fr/2fr layout to each page. Two pages need it (day sheet; employees on hand-over). **Graft from A:** `progress` on the hero only, so the day sheet keeps its working bar; the icon prop is dropped (rule 9). |
| Page header | **B**: `action` (always solid) + `secondary` (≤ 2 ghost) | With A's `primary: ReactNode`, an outline primary or two solid buttons still compile; the roles screen does exactly that today. **Graft from A:** no `period` prop; `description` already sits under the title, and a second slot there would be two ways to do one thing. |
| Table → cards | **B**: `DataList<T>` | Rule 19 lives behind one field list: the card label is the column header, so nothing is hand-synced, and the loading/error/empty/pagination ternary every list page repeats moves inside. A's `<Table stack>` + `data-label` repeats each header in every cell (a hand-synced list), and a forgotten `data-label` fails silently. The `::before` label is also read aloud twice; B's `aria-hidden` span plus explicit roles keep one screen-reader model. 9 lists on Youssef's pages plus 6 on the hand-over use it. |
| Inline alert | **B** name and shape, **A** tones and roles | `Alert` (the shadcn name a copying agent looks for), no `className`. A's `neutral` tone covers the four `bg-muted` notes in `attendance-day.tsx`. A is right that a static note should not be a live region; B is right that the import success should announce. Hence: danger = alert, success = status, others none. A's `action` prop is dropped (no caller). |
| Sheet header/footer | **A** | Two pages need them (`attendance-day.tsx`, `audit.tsx`); they mirror the existing `DialogHeader`/`DialogFooter`. B skipped them. |
| Bottom bar | Both (converged) | Same sticky + safe-area shape; B's name `ActionBar`. Built only because three callers are named. |
| Checkbox | **A**: global `accent-color` | One line instead of a component. |
| Backdrop | Both (converged) | `bg-black/70`; the sheet reuses `DialogOverlay`. |
| `StatCard` tones, `SearchInput` attrs | **B** | Neutral tones in UI-1 reach `employees.tsx` with no edit; `type="search" enterKeyHint="search"` is one shared line. |

Rejected outright: A's CSS stack block in `index.css` (replaced by `DataList`), B's `--text-stat*` tokens, B's `period` prop, A's radius aliases, A's `Notice.action`.

## Slices

### UI-1: tokens + primitives (one small PR, kit only, zero pages)

Files: `index.css`, `lib/utils.ts`, `components/ui/{button,input,select,card,badge,dialog,sheet,tabs,dropdown-menu,misc}.tsx`, `components/form.tsx` (`selectCls`, `TimeInput`), `components/common/{Filters,StatusBadge,EmptyState}.tsx`, `components/cards/StatCard.tsx`, `components/layout/{SegmentedControl,NotificationBell,AppLayout}.tsx`, `mobile.test.tsx`, new `theme.test.ts`.
Contents: the token diff, `cn()`, the kit class table above. Land it before `feature/manager-attendance` merges where possible: the overlap is four class strings in `AppLayout.tsx`.

### UI-2: shared patterns (kit only)

New: `components/common/{DataList,StatRow,Alert}.tsx`, `components/layout/ActionBar.tsx` (subject to decision 4). Changed: `common/PageHeader.tsx` (new API + deprecated `actions`), `ui/sheet.tsx` (`SheetHeader`/`SheetFooter`), `form.tsx` (`ErrorBanner` → `Alert`), `ui/table.tsx` (doc line), `components/kit.test.tsx`.
To give the UI-2 screenshots a real consumer, `audit.tsx` moves to `DataList` + `SheetHeader` in this PR (it is the smallest list page; it is also touched by 2 lines on the manager branch, so it is a small rebase).

### UI-3: Youssef's pages

First apply the mechanical rule to every page below: delete `h-11`, `h-11 md:h-9`, `h-11 sm:h-9`, `size-11`, `max-sm:size-9` and `[&_input]:h-11 [&_select]:h-11` on kit controls; `min-h-11` / `size-11` / `min-w-11` on a non-kit element (label, grid cell) → `min-h-tap min-w-tap`; delete `shadow-1`, `tabular` and `tabular-nums` (figures are already tabular globally, `index.css:269`).

| Page | Edits | Rules |
|---|---|---|
| `attendance.tsx` (shell, grid) | `MiniStat` leaves the header; the period and the missing count go into `description`; Lock is `action` on the grid tab and `secondary` on the sheet and import tabs (Reopen is always `secondary`); notices `104`, `333` → `Alert`; legend swatches keep their tones (real categories); grid stays a `Table` (decision 5) | 5, 6, 13, 17, 21 |
| `attendance-sheet.tsx` | KPI tiles → `StatRow` (hero total with `progress`); info banner `280` → `Alert`; "mark rest present" becomes the solid action of the sheet; Paste/Undo ghost; the 8 shortcut chips are deleted (no keyboard shortcuts); bulk bar → `ActionBar` (decision 4); cohort tiles `rounded-xl`, no shadow | 1, 5, 6, 7, 9, 17, 20, 22 |
| `attendance-sheet-rows.tsx` | Keeps its own xl table/cards split (selection; no J/K cursor). Delete the duplicate `FOCUS` (`13`, use `lib/utils.ts:9`) and the passed-in `h-9`/`h-11`. Status pill → `Badge`. The 4 quick-status buttons wait on decision 3 | 6, 21 |
| `attendance-sheet-command.tsx` | Paste-dialog footer: Present solid, Absent and Sick ghost (the dialog has no Apply); `CHIP` → `Badge` | 6 |
| `attendance-day.tsx` | Header/footer → `SheetHeader`/`SheetFooter`; the 4 notes → `Alert tone="neutral"`, the conflict → `Alert tone="warning"` | 17, 21 |
| `attendance-import.tsx` | `Changes` and `Errors` → `DataList` (deletes the second hand-built split); success banner → `Alert tone="success"`; the three steps (download template → choose file → preview) become a numbered `<ol>` in the one card; the file-picker label keeps `buttonVariants` without `h-11` | 3, 6, 17, 19 |
| `audit.tsx` | Done in UI-2 | 19, 21 |
| `console.tsx` | Accounts and sessions → `DataList`; Add account / Add role → header `action` (controlled `FormDialog`); roles "Save changes" stays the form's only solid button and "Add role" becomes `secondary` while a role is open; select the first role by default from `lg` up; the `h-7` radio pills (`291-295`) → `SegmentedControl`; raw checkbox gets the global accent | 5, 6, 7, 19, 21 |
| `sites.tsx` | `ListCard` stays local, its body → `DataList` (sites, leave types, residences, allowance rules with `numeric`); `ListCard`'s `action` renders solid; local `Notice` → `Alert`; fare matrix stays a `Table`, its empty cells drop `bg-accent` for a neutral dashed border | 5, 8, 15, 17, 19 |
| `settings.tsx` | `Alert` where it banners; `fieldset`/`legend` keeps its own label classes; `TabsList` override kept | 17 |
| `holidays.tsx` | `DataList`; Add → solid; delete button `h-11` removed | 5, 19, 21 |
| `login.tsx` | `enterKeyHint="go"` on the password field; the card loses its shadow through `card.tsx` | 10, 18 |
| `pages.tsx` (not found) | The text link "Back to employees" → a solid `Button asChild` + `Link` | 5 |

Merge risk: `sites.tsx` (+39) and `audit.tsx` (2 lines) also change on `feature/manager-attendance`. Do `sites.tsx` last.

### Hand-over to the other developer (no edits on `feature/ui-refresh`)

Each item is a ✕/✓ pair with one number (rule 24). The rule number is in brackets.

**`employees.tsx`** (main, and +92 lines on `feature/manager-attendance`)

1. ✕ 5 equal `MiniStat`s with 5 tinted icon tiles → ✓ one `StatRow`: hero "total" at 30 px + 4 secondaries, 0 icon tiles. After UI-1 the tiles are already neutral with no edit. [1, 9, 20]
2. ✕ stats with no date → ✓ hero `detail` "as of 2 Nov 2026". [13]
3. ✕ "Add employee" is 130 px wide at 375 → ✓ `PageHeader action={{ label, icon: UserPlus, to: '/employees/new' }}`, 343 px full width. Then delete `Legacy` from `PageHeader`. [5]
4. ✕ list shows 3 of 6 fields at 375 (number, department and hire date are hidden) → ✓ `DataList`, 6 of 6 as label/value lines. The same for documents, type/pay/manager history and the new home-site history. [19]
5. ✕ pay amounts start-aligned → ✓ `numeric: true`, decimals aligned (direction per decision 2). [15]
6. ✕ phone is a plain text field → ✓ `type="tel" autoComplete="tel" inputMode="tel"`; bank account and insurance number `inputMode="numeric"`; `enterKeyHint="done"` on the last field. [18]
7. ✕ on the detail page Save sits 2,200 px down at 375 and the only top button is an outline "Assign manager" → ✓ Save in `ActionBar`, 0 px away at every scroll position. [5, 22]
8. ✕ `MiniStat`/`Tone` import → ✓ removed; then delete `MiniStat`, `Tone` and `cards/StatCard.tsx`.

**`site-day.tsx`** (only on `feature/manager-attendance`)

1. ✕ the card `li` carries `shadow-1` (`56`) → ✓ delete the class; it already draws nothing after UI-1, and the guard test fails on it. [10]
2. ✕ `h-11` / `size-11` on the mark and ⋯ buttons (`66`, `70`): 44 px with a mouse at 1440 → ✓ delete; the kit gives 36 px on a mouse and 44 px on touch. [21]
3. ✕ 4 equal outline buttons × 5 cards = 20 equal buttons → ✓ the pressed mark takes its `Badge` tone (success/destructive/info), the rest stay outline; the local `PRESSED` map (`17-21`) goes. [6]
4. ✕ the site picker sits above the `h1` → ✓ `PageHeader title={site} description="Monday 2 Nov · Today"`, picker under it. [13]
5. ✕ no save bar yet → ✓ when marking lands, its bar is `ActionBar` (safe area included). [22]

## Tests

**`mobile.test.tsx`** (the mechanism moves from viewport width to pointer type, rule 21, so the pinned class names change in the same PR):

- `every kit field is 16 px below sm and 14 px from sm up` → `every field is 16 px on a coarse pointer`: the four kit fields keep `text-sm` and no longer contain `max-sm:text-base`; `index.css` contains the `@media (pointer: coarse)` field rule with `font-size: 16px`.
- `a caller that shrinks a field still gets 16 px on a phone` → assert that rule is unlayered: a brace-depth scan of `index.css` finds the block at depth 0, outside every `@layer`. That is what makes a caller's `text-xs` lose.
- `buttons, fields and tabs are 44 px tall below sm, whatever the size` → `every kit control has the touch floor`: every button size, the `h-7` override, Input, SelectTrigger, `selectCls` and the tab trigger contain `min-h-tap`; `icon` and `icon-sm` also contain `min-w-tap`. New case: `<Button size="icon" className="size-8 max-sm:size-9">` still contains `min-h-tap` and `min-w-tap` (the `audit.tsx:151` regression). Plus: `index.css` sets `--tap: 1.5rem` in `:root` and `--tap: 2.75rem` inside `@media (pointer: coarse)`.
- Manifest and `index.html` tests: unchanged.

**New `theme.test.ts`** (UI-1): (a) every `--text-*`, `--shadow-*` and `--spacing-*` name in `@theme inline` (ignoring `--x--modifier` keys) is in `THEME_NAMES`, and the reverse; (b) `cn('text-table text-muted-foreground')` keeps both; (c) guard: no `(?:[a-z-]+:)*(?:min-)?(?:h|w|size)-11\b`, `shadow-[12]\b` or `backdrop-brightness` in `src/components/**`. UI-3 widens (c) to `src/pages/**`. If `site-day.tsx` is on main by then, it goes in a one-entry `SKIP` that its hand-over PR deletes.

**`attendance-sheet.test.tsx`** (UI-3): `:243` asserts `h-11 md:h-9` on "mark rest present" → assert the button has no height class and is the default variant (it becomes the solid action); `:1280` `min-h-11` → `min-h-tap`. Same reason as above. `attendance-import.test.tsx:150` (`max-sm:hidden` on a label) is unrelated and stays.

**`components/kit.test.tsx`** (UI-2): `DataList` shows each field's header as visible label text, `numeric` header and cell are `text-end`, `tableFrom` cells still render, loading → skeleton, `error` → alert and no empty state, `[]` → empty state, pagination only when `total > pageSize`; `PageHeader` `action` renders exactly one default-variant button and `to` renders a link; `StatRow` with `select` renders `button[aria-pressed]`, without it plain groups, and no tone class on the hero; `Alert` danger → `role="alert"`, success → `role="status"`, info → no role.

## Capture caveat (verify-hrms)

`.claude/skills/verify-hrms/scripts/capture.mjs` lives in the main checkout (`/Users/yousefhasabo/HRMS-Smart TECH`), not in this worktree. Line 127 creates every context as `browser.newContext({ viewport: { width, height: 900 }, colorScheme: theme, storageState })`, with no `hasTouch` and no `isMobile`. Run against the same Playwright copy (`~/.npm/_npx/e41f203b7505f1fb/node_modules/playwright`), a plain 375 px context reports `matchMedia('(pointer: coarse)')` **false**; with `hasTouch: true` it reports true (and `hover: none`). `isMobile: true` is not needed: on a page without a viewport meta it widened the layout viewport to 981 px.

So after UI-1, the current script would show 375 px shots with 36 px buttons and 14 px fields, which would look like a regression. The Gate 0 baseline was taken with a fine pointer, where `max-sm:` gave 44 px.

The change, before the UI-1 gate:
1. Line 127: add `hasTouch: Number(width) < 640` to the `newContext` options. 1024 and 1440 stay mouse captures.
2. Record the result: in the `page.evaluate` that fills `activeLang`/`dir`/`scrollWidth` (line ~142), add `coarse: matchMedia('(pointer: coarse)').matches`, so `summary.json` proves each 375 shot ran with a coarse pointer.
3. The Browser pane's mobile preset emulates touch, but check `matchMedia('(pointer: coarse)').matches` there before trusting a pane screenshot.

## Tradeoffs accepted

- We accept `min-h-tap` as the only touch-floor spelling, with the rule "never `min-h-N` next to `min-h-tap`" (they now merge as one group), in exchange for a floor no caller size can remove.
- We accept an unlayered global 16 px rule that no class can override, in exchange for covering raw inputs, iPad and the other developer's pages.
- We accept that a touch laptop whose primary pointer is a mouse gets 24 px, not 44 px; that is the house rule's own `pointer: coarse`. `any-pointer: coarse` is a one-line swap.
- We accept header actions as data, so a dialog opened from the header needs a `useState` and a controlled `FormDialog`, in exchange for a header that can only render one solid button.
- We accept `PageHeader`'s `Legacy` `actions` and `MiniStat` until the hand-over lands (two ways to do one task), bounded by `@deprecated` tags and a named deleting PR, in exchange for not touching `employees.tsx`.
- We accept one fixed card breakpoint (640 px) and one card layout, in exchange for list pages that cannot get rule 19 wrong. The day sheet keeps its own xl split.
- We accept `aria-hidden` visible labels that duplicate the `<thead>`, in exchange for one screen-reader model at every width.
- We accept a 2 px taller tab strip on desktop (auto height) in exchange for one fewer height rule.

## Alternatives considered

- **`pointer-coarse:h-11` in each kit file** (how.md, sketch B). It keeps ~15 copies of the rule, and a caller's `size-*` at the same variant still deletes it (checked). Shallow: every kit author must remember the variant.
- **CSS-only stacked table (`<Table stack>` + `data-label`)** (sketch A). A smaller kit change, but every page keeps the knowledge: one `data-label` per cell, equal to the header by hand, plus its own loading/empty/pagination ternary. A forgotten label fails silently.
- **Retuning `--radius` so the t-shirt scale becomes 6/8/12.** It moves every page's own `rounded-md/lg` at once, the other developer's pages included, with no review of each.
- **Two renders (table ≥ sm, list < sm) from one column definition**, as the attendance pages do by hand. It doubles the DOM, and every test sees each row twice; CSS on one tree gives the same look.

## Owner decisions

**Decided 2026-11-02 (Gate 0): the owner took every recommendation below** — (1) orange in both themes, with a note in ADR 0001; (2) money `text-end`; (3) Present toned, Absent/Sick into ⋯, confirm with HR before UI-3 ships it; (4) sticky bottom bulk bar; (5) matrices keep horizontal scroll with a sticky first column.

1. **One accent (takeaway 8).** Light primary is orange `#c2410c` (white text 5.18:1); dark primary is indigo `#818cf8` (navy text 6.42:1). Both are copied from the CRM per ADR 0001. Under the uiux precedence the project's design system (rank 3) outranks the house takeaways (rank 4), so the split is a valid exception unless you decide otherwise. Options: (a) keep the split, as the CRM does; (b) orange in both themes; (c) indigo in both. **Recommendation: (b)**, because light is the default theme, it is the one in every gate shot, and it shows the same button in the same hue on every screen. A dark-theme orange such as `#fb923c` gives navy text 8.46:1. The cost is a dark theme that no longer matches the CRM's, which needs a note in ADR 0001. The change is two token lines plus the two `--accent` mixes; nothing else.
2. **Money alignment in Arabic (takeaway 15).** `numeric` = `text-end`, which is the left edge in RTL. Is that where HR expects amounts in Arabic pay tables? Recommendation: yes (it lines up the decimals, as Arabic accounting sheets do).
3. **Day-sheet quick status buttons (takeaways 4, 6).** Each row has 4 equal outline buttons (present, absent, sick, details), 5 per card at 375. They exist for fast marking. Recommendation: keep Present as the one toned button and move Absent/Sick into the ⋯ menu with Details, which leaves 2 targets per row; confirm with HR before changing a working flow.
4. **Day-sheet bulk bar position (takeaway 22).** Move it from the top of the list to a sticky bottom `ActionBar`? Recommendation: yes, at all widths (the "2 selected · Apply" pattern), which also gives `ActionBar` its first caller on this branch.
5. **Matrices keep horizontal scroll (takeaway 19).** The attendance grid (30 days), the fare matrix (residence × site) and the permission matrix cannot become one card per row without losing the comparison. No precedence rank decides this, so it needs your sign-off. Recommendation: keep them as scrolling tables with a sticky first column.

## Risks

- `display` changes on `<tr>`/`<td>` drop table semantics in Safari; `DataList` sets explicit roles. Check with VoiceOver on iOS at the UI-2 gate.
- In the 640–768 px band a 5–6 column table can still scroll; `tableFrom` handles it per column. Check the import `Changes` table at 700 px.
- The tailwind-merge fix has visible side effects: `SegmentedControl` labels go from 14 px to their intended 13 px, and the import errors card title goes from 14 px to its intended 16 px. Grep finds no other case.
- `min-h-tap` makes touch rows taller on iPad in dense tables (grid, day sheet). Intended by rule 21; check the 1024 px touch view once.
- `feature/manager-attendance` touches `AppLayout.tsx`, `audit.tsx`, `sites.tsx` and `employees.tsx`. UI-1 should land first; `sites.tsx` goes last in UI-3.
- Screens not in Gate 0 (holidays, leave types and work-rules tabs, the day editor sheet, every dialog) must be captured at the UI-3 gate.

## Next implementation step

Write `theme.test.ts` and the new `mobile.test.tsx` assertions and confirm they fail on main, add `hasTouch` to `capture.mjs`, then apply the token diff, `cn()` and the UI-1 class table as one PR.

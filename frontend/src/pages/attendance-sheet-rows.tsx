import { CalendarOff, Check, ChevronDown, CircleHelp, Clock, Ellipsis, Eye, type LucideIcon, MapPin, Stethoscope, UserX } from 'lucide-react'
import { type ReactNode, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { formatTime, localName, type Row, TimeInput } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { type SheetAction, type SheetRow, statusOf } from '@/lib/day-sheet'
import { cn, FOCUS } from '@/lib/utils'

export type Status = ReturnType<typeof statusOf>
type TimeField = 'in_time' | 'out_time'

// "missing" (no record) and "absent" (غ) never share a look: different tokens, icon and border style
const LOOK: Record<Status, { cls: string; text: string; icon: LucideIcon }> = {
  missing: { cls: 'border-dashed bg-warning-bg border-warning-border', text: 'text-warning', icon: CircleHelp },
  present: { cls: 'bg-success-bg border-success-border', text: 'text-success', icon: Check },
  late: { cls: 'bg-warning-bg border-warning-border', text: 'text-warning', icon: Clock },
  absent: { cls: 'bg-destructive-bg border-destructive-border', text: 'text-destructive', icon: UserX },
  leave: { cls: 'bg-info-bg border-info-border', text: 'text-info', icon: CalendarOff },
  derived: { cls: 'border-dashed bg-muted', text: 'text-muted-foreground', icon: CalendarOff },
}
// the quick actions: [action, label key, icon, status tone]. The bulk bar and the paste dialog show all three; a row shows Present and puts the rest in its ⋯ menu
export const QUICK: [SheetAction, string, LucideIcon, string][] = [
  [{ kind: 'present' }, 'attendance.sheet.action.present', Check, 'text-success hover:bg-success-bg hover:border-success-border'],
  [{ kind: 'leave', code: 'absent' }, 'attendance.sheet.action.absent', UserX, 'text-destructive hover:bg-destructive-bg hover:border-destructive-border'],
  [{ kind: 'leave', code: 'sick' }, 'attendance.sheet.action.sick', Stethoscope, 'text-info hover:bg-info-bg hover:border-info-border'],
]
// a selected row or card: full accent, so it still shows in dark mode (accent/60 was too faint there), and kept under the pointer
const SELECTED = 'bg-accent hover:bg-accent'
const TOTALS = [['worked', 'text-success', 'attendance.sheet.kpi.worked'], ['leave', 'text-info', 'attendance.sheet.kpi.leave'], ['absent', 'text-destructive', 'attendance.sheet.kpi.absent'], ['missing', 'text-warning', 'attendance.state.missing']] as const

/**
 * One page of the day sheet: a table from xl up, cards below it (below xl the table is wider than its card, in both groups). Below 2xl
 * the table's actions are icons, and its totals always sit in two columns, so the table fits its card. Writes go back through `onApply` (the sheet's `apply()`).
 */
export function SheetRows({ rows, office, editable, date, sites, selected, pending, leaveSite, onToggle, onApply, onOpenDay }: {
  rows: SheetRow[]
  office: boolean
  editable: boolean
  date: string
  sites: Row[]
  selected: ReadonlySet<string>
  pending: ReadonlySet<string>
  leaveSite: (code: string) => string | undefined
  onToggle: (ids: string[], on: boolean) => void
  onApply: (action: SheetAction, row: SheetRow, label: string) => void
  onOpenDay: (employee: Row, date: string) => void
}) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const name = (r: SheetRow) => localName(r.employee, lang)
  const siteName = (id: string | null) => {
    const s = sites.find((x) => x.id === id)
    return s ? localName(s, lang) : '—'
  }
  const status = (r: SheetRow) => statusOf(r, office)
  const workSites = sites.filter((s) => s.kind === 'work' && s.active)
  // the day's leave type (a derived day has no site: its code's leave type); the seeded ones have no name_en, so English falls back to the code's name
  const leaveName = (r: SheetRow, code: string) => {
    const s = sites.find((x) => x.id === r.day.site_id) ?? sites.find((x) => x.kind === 'leave' && x.leave_code === code)
    return s ? localName(s, lang, t(`attendance.leaveName.${code}`, { defaultValue: s.name_ar })) : t(`attendance.leaveName.${code}`, { defaultValue: code })
  }
  const pageIds = rows.map((r) => r.employee.id)
  const pageAll = pageIds.every((id) => selected.has(id))
  const pageSome = pageIds.some((id) => selected.has(id))
  const noLeave = (a: SheetAction) => a.kind === 'leave' && !leaveSite(a.code)
  // a field row is present at its last site; without one there is nowhere to put it (bulk paths skip such rows with a toast)
  const noSite = (a: SheetAction, r: SheetRow) => a.kind === 'present' && !office && !r.last_site_id
  const noSiteId = useId()
  // the select-page box: in the table header from xl, on its own line above the cards below it
  const pageCheck = <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={pageAll} ref={(el) => { if (el) el.indeterminate = pageSome && !pageAll }} onChange={() => onToggle(pageIds, !pageAll)} />
  const check = (r: SheetRow) => (
    <label className="flex min-h-tap min-w-tap cursor-pointer items-center justify-center">
      <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={selected.has(r.employee.id)} onChange={(e) => onToggle([r.employee.id], e.target.checked)} />
      <span className="sr-only">{t('attendance.sheet.select', { name: name(r) })}</span>
    </label>
  )
  // compact (the table): icon-only below 2xl, named by its tooltip; the aria-label always names the action and the employee
  const present = (r: SheetRow, compact = false) => {
    const [action, label, Icon, tone] = QUICK[0]
    const s = status(r)
    return (
      <Button type="button" variant="outline" size="sm" className={cn('px-2.5', tone, 'aria-pressed:border-success/25 aria-pressed:bg-success/10')} aria-pressed={s === 'present' || s === 'late'}
        disabled={pending.has(r.employee.id) || noSite(action, r)} {...(compact && { title: t(label) })} {...(noSite(action, r) && { title: t('attendance.sheet.noLastSite'), 'aria-describedby': noSiteId })}
        aria-label={t('attendance.sheet.rowAction', { action: t(label), name: name(r) })} onClick={() => onApply(action, r, t('attendance.sheet.actionOn', { action: t(label), name: name(r) }))}>
        <Icon aria-hidden />
        <span className={cn(compact && 'hidden 2xl:inline')}>{t(label)}</span>
      </Button>
    )
  }
  // ⋯: the other quick actions and Details. Not modal, so the day editor it opens never inherits a blocked page
  const more = (r: SheetRow) => (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button type="button" variant="outline" size="sm" className="px-2.5" title={t('attendance.sheet.action.more')}
          aria-label={t('attendance.sheet.rowAction', { action: t('attendance.sheet.action.more'), name: name(r) })}>
          <Ellipsis aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {QUICK.slice(1).map(([action, label, Icon]) => (
          <DropdownMenuItem key={label} disabled={pending.has(r.employee.id) || noLeave(action)} onSelect={() => onApply(action, r, t('attendance.sheet.actionOn', { action: t(label), name: name(r) }))}>
            <Icon aria-hidden />
            {t(label)}
          </DropdownMenuItem>
        ))}
        <DropdownMenuItem onSelect={() => onOpenDay(r.employee, date)}>
          <Eye aria-hidden />
          {t('attendance.sheet.action.details')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
  /**
   * A row's times, each placed by `cell`: office rows the in-time, present field rows arrival and departure with the server's late/OT
   * hints; boxes while the day is editable, plain text otherwise. Keyed by the stored times, so a refetch never wipes typing.
   */
  const times = (r: SheetRow, cell: (field: TimeField, node: ReactNode) => ReactNode) => {
    const fields: TimeField[] = office ? ['in_time'] : status(r) === 'present' ? ['in_time', 'out_time'] : []
    if (!fields.length) return null
    const late = r.hints.late_minutes ?? 0
    const over = r.hints.ot_minutes ?? 0
    const hint = (f: TimeField) => (office ? undefined : f === 'in_time' ? (late > 0 ? t('attendance.sheet.lateBy', { minutes: late }) : undefined) : over > 0 ? t('attendance.sheet.overBy', { minutes: over }) : undefined)
    if (!editable) return fields.map((f) => cell(f, <><span className="text-sm">{formatTime(r.day[f], lang)}</span><Hint text={hint(f)} /></>))
    return (
      <TimeCells key={`${r.employee.id}/${r.day.in_time}/${r.day.out_time}`} cell={cell} disabled={pending.has(r.employee.id)}
        cells={fields.map((f) => ({ field: f, was: r.day[f] ? String(r.day[f]).slice(0, 5) : '', label: t('attendance.sheet.rowAction', { action: t(`attendance.day.${f}`), name: name(r) }), hint: hint(f) }))}
        onCommit={(v) => onApply({ kind: 'time', ...v }, r, t('attendance.sheet.actionOn', { action: t(v.in_time && v.out_time ? 'attendance.sheet.bulk.times' : v.in_time ? 'attendance.day.in_time' : 'attendance.day.out_time'), name: name(r) }))} />
    )
  }
  const tableCell = (f: TimeField, node: ReactNode) => <TableCell key={f} className="align-top">{node}</TableCell>
  // a card labels each box above it; the pair stacks below sm (≈309 px of card at 375) and sits side by side from sm
  const cardCell = (f: TimeField, node: ReactNode) => (
    <div key={f}>
      <p aria-hidden className="mb-1.5 text-xs font-medium text-muted-foreground">{t(`attendance.day.${f}`)}</p>
      {node}
    </div>
  )
  const pill = (r: SheetRow) => {
    const s = status(r)
    const { cls, text, icon: Icon } = LOOK[s]
    const leave = r.day.leave_code
    const label = s === 'missing' ? t('attendance.state.missing')
      : s === 'present' ? t('attendance.present')
      : s === 'late' ? t('attendance.sheet.lateBy', { minutes: r.hints.late_minutes })
      : s === 'absent' ? t('attendance.sheet.kpi.absent')
      : leave ? leaveName(r, leave) : t('attendance.state.derived')
    return (
      <Badge variant="outline" className={cn('font-semibold', cls, text)}>
        <Icon className="shrink-0" aria-hidden />
        {label}
      </Badge>
    )
  }
  // a field row's site: the day's site when present, else the last site on a missing row, else «choose a site»; a pick writes that site
  const siteChip = (r: SheetRow) => {
    const s = status(r)
    const at = s === 'present' ? r.day.site_id : s === 'missing' ? r.last_site_id : null
    const text = at ? siteName(at) : t('attendance.sheet.bulk.pickSite')
    return (
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <Button type="button" variant="outline" size="sm" className="max-w-48 min-w-0 px-2.5" disabled={pending.has(r.employee.id)} aria-label={t('attendance.sheet.rowAction', { action: text, name: name(r) })}>
            <MapPin aria-hidden />
            <span className="truncate">{text}</span>
            <ChevronDown aria-hidden className="text-muted-foreground" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuRadioGroup value={s === 'present' ? (r.day.site_id ?? '') : ''}>
            {workSites.map((x) => (
              <DropdownMenuRadioItem key={x.id} value={x.id} onSelect={() => onApply({ kind: 'site', siteId: x.id }, r, t('attendance.sheet.actionOn', { action: t('attendance.sheet.bulk.setSite'), name: name(r) }))}>
                {localName(x, lang)}
              </DropdownMenuRadioItem>
            ))}
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    )
  }
  const where = (r: SheetRow) => {
    if (office) return null
    if (editable) return siteChip(r)
    const s = status(r)
    if (s === 'present') return <span className="rounded-md border bg-card px-2 py-0.5 text-xs">{siteName(r.day.site_id)}</span>
    if (s === 'missing' && r.last_site_id) return <span className="text-xs text-muted-foreground">{t('attendance.sheet.lastSite', { site: siteName(r.last_site_id) })}</span>
    return null
  }
  const details = (r: SheetRow, compact = false) => (
    <Button type="button" variant="outline" size="sm" className={cn(compact && 'px-2.5')} {...(compact && { title: t('attendance.sheet.action.details') })}
      aria-label={t('attendance.sheet.rowAction', { action: t('attendance.sheet.action.details'), name: name(r) })} onClick={() => onOpenDay(r.employee, date)}>
      {compact && <Eye aria-hidden />}
      <span className={cn(compact && 'hidden 2xl:inline')}>{t('attendance.sheet.action.details')}</span>
    </Button>
  )
  // the table's totals: two content-sized columns of "number label" (fr tracks let the labels overlap); a card spreads four across
  const totals = (r: SheetRow, table = false) => (
    <div className={table ? 'grid w-max grid-cols-[auto_auto] gap-x-4 gap-y-0.5' : 'grid grid-cols-4 gap-3'}>
      {TOTALS.map(([k, tone, label]) => (
        <div key={k} className={table ? 'flex items-baseline gap-1' : 'min-w-14'}>
          <div className={cn('text-sm font-semibold', r.totals[k] ? tone : 'text-muted-foreground')}>{r.totals[k]}</div>
          <div className={cn('text-[11px] text-muted-foreground', !table && 'truncate')}>{t(office && k === 'worked' ? 'attendance.present' : label)}</div>
        </div>
      ))}
    </div>
  )

  return (
    <>
      {editable && !office && <span id={noSiteId} className="sr-only">{t('attendance.sheet.noLastSite')}</span>}
      <div className="hidden xl:block">
        <Table>
          <TableHeader>
            <TableRow>
              {editable && (
                <TableHead className="w-0 px-1 first:ps-2">
                  <label className="flex min-h-tap min-w-tap cursor-pointer items-center justify-center">
                    {pageCheck}
                    <span className="sr-only">{t('attendance.sheet.selectPage')}</span>
                  </label>
                </TableHead>
              )}
              <TableHead>{t('employee.code')}</TableHead>
              <TableHead>{t('attendance.employee')}</TableHead>
              {office && <TableHead>{t('attendance.day.in_time')}</TableHead>}
              <TableHead>{t('common.status')}</TableHead>
              {!office && <TableHead>{t('attendance.day.in_time')}</TableHead>}
              {!office && <TableHead>{t('attendance.day.out_time')}</TableHead>}
              <TableHead><span className="sr-only">{t('common.actions')}</span></TableHead>
              <TableHead className="border-s">{t('attendance.sheet.totals')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((r) => (
              <TableRow key={r.employee.id} className={cn(selected.has(r.employee.id) && SELECTED)}>
                {editable && <TableCell className="px-1 py-0 first:ps-2">{check(r)}</TableCell>}
                <TableCell className="text-muted-foreground">{r.employee.code}</TableCell>
                <TableCell className="whitespace-normal">
                  <bdi className="font-medium">{name(r)}</bdi>
                  {r.employee.department && <div className="text-xs text-muted-foreground">{r.employee.department}</div>}
                </TableCell>
                {office && times(r, tableCell)}
                <TableCell>
                  <div className="flex items-center gap-1.5">
                    {pill(r)}
                    {where(r)}
                  </div>
                </TableCell>
                {!office && (times(r, tableCell) ?? <><TableCell /><TableCell /></>)}
                <TableCell>
                  <div className="flex items-center justify-end gap-1.5">
                    {editable ? <>{present(r, true)}{more(r)}</> : details(r, true)}
                  </div>
                </TableCell>
                <TableCell className={cn('border-s', !selected.has(r.employee.id) && 'bg-muted/40')}>{totals(r, true)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      {editable && (
        <label className="flex min-h-tap cursor-pointer items-center gap-1.5 border-b ps-1.5 pe-4 text-sm font-medium xl:hidden">
          <span className="flex min-h-tap min-w-tap items-center justify-center">{pageCheck}</span>
          {t('attendance.sheet.selectPage')}
        </label>
      )}
      <ul aria-label={t('attendance.employee')} className="divide-y xl:hidden">
        {rows.map((r) => (
          <li key={r.employee.id} className={cn('space-y-3 p-4', selected.has(r.employee.id) && SELECTED)}>
            <div className="flex items-start justify-between gap-3">
              {editable && <div className="-my-2.5 -ms-2.5">{check(r)}</div>}
              <div className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground">{r.employee.code}</span>
                  <bdi className="truncate font-medium">{name(r)}</bdi>
                </span>
                {r.employee.department && <div className="truncate text-xs text-muted-foreground">{r.employee.department}</div>}
              </div>
              {pill(r)}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="min-w-0">{where(r)}</div>
              <div className="flex gap-2">{editable ? <>{present(r)}{more(r)}</> : details(r)}</div>
            </div>
            {(office || status(r) === 'present') && <div className="grid gap-3 sm:grid-cols-2">{times(r, cardCell)}</div>}
            {/* not on a row already at a site today: there the disabled Present is no problem to explain */}
            {editable && noSite({ kind: 'present' }, r) && status(r) !== 'present' && <p className="-mt-1.5 text-xs text-muted-foreground">{t('attendance.sheet.noLastSite')}</p>}
            <div className="border-t pt-3">
              <p className="mb-1.5 text-[11px] font-medium text-muted-foreground">{t('attendance.sheet.totals')}</p>
              {totals(r)}
            </div>
          </li>
        ))}
      </ul>
    </>
  )
}

/** A server late/OT hint under a time, in the warning tone. */
function Hint({ text }: { text?: string }) {
  return text ? <p className="mt-1 flex items-center gap-1 text-xs text-warning"><Clock className="size-3.5 shrink-0" aria-hidden />{text}</p> : null
}

/**
 * A row's time boxes, one unit: nothing is written while focus moves between them; when it leaves them, the changed times go out in one
 * write (Enter in a box leaves it; an AM/PM click keeps the cursor in its box, it never writes). Escape, a blank, an unreadable time or
 * one still waiting for AM/PM (a "10:30" with neither chosen is never read as morning) puts the stored time back. Focus selects the
 * time, so typing replaces it. `cell` places each box (a table cell, a card slot).
 */
function TimeCells({ cells, disabled, onCommit, cell }: {
  cells: { field: TimeField; was: string; label: string; hint?: string }[]
  disabled: boolean
  onCommit: (times: Partial<Record<TimeField, string>>) => void
  cell: (field: TimeField, node: ReactNode) => ReactNode
}) {
  const { t } = useTranslation()
  const stored = () => Object.fromEntries(cells.map((c) => [c.field, { v: c.was, needs: false }]))
  const [draft, setDraft] = useState<Record<string, { v: string; needs: boolean }>>(stored)
  const boxes = useRef<(HTMLDivElement | null)[]>([])
  const done = () => {
    const out: Partial<Record<TimeField, string>> = {}
    const next = { ...draft }
    for (const c of cells) {
      const { v, needs } = draft[c.field]
      const ok = !needs && /^\d{2}:\d{2}$/.test(v) ? v : c.was
      // say why a typed time is not written, then put the stored one back
      if (v && ok !== v) toast.error(t(needs ? 'time.choosePeriod' : 'time.invalid'))
      next[c.field] = { v: ok, needs: false }
      if (ok !== c.was) out[c.field] = ok
    }
    setDraft(next)
    if (Object.keys(out).length) onCommit(out)
  }
  return cells.map((c, i) => cell(c.field, (
    <div ref={(el) => { boxes.current[i] = el }} onBlur={(e) => { if (!boxes.current.some((b) => b?.contains(e.relatedTarget))) done() }}
      onKeyDown={(e) => { if (e.key === 'Escape') setDraft(stored()); else if (e.key === 'Enter' && e.target instanceof HTMLInputElement) e.target.blur() }}>
      {/* mouseup would drop the selection a click's focus just made */}
      <TimeInput value={draft[c.field].v} label={c.label} aria-label={c.label} quietHint disabled={disabled}
        onFocus={(e) => e.currentTarget.setSelectionRange(0, e.currentTarget.value.length)} onMouseUp={(e) => e.preventDefault()}
        onChange={(v, needs) => setDraft((d) => ({ ...d, [c.field]: { v, needs } }))} />
      <Hint text={c.hint} />
    </div>
  )))
}

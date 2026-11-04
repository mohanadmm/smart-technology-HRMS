import { useQueryClient } from '@tanstack/react-query'
import { HardHat, MapPinOff, MoreHorizontal, Search, UserMinus, UserPlus, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Alert } from '@/components/common/Alert'
import { EmptyState } from '@/components/common/EmptyState'
import { ErrorBanner, intlLocale, isHhmm, localName, type Row, stopAtMissingPeriod, TimeInput, toErrs } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { queryKey, useApi, useSave } from '@/lib/query'
import {
  changed, checkOutAll, checkOutCount, counts, current, arrivalDiff, departureDiff, type Draft, type Drafts, type LeaveSites, type Mark, markAllCount, markAllPresent,
  MARKS, MORE, saveBody, type SiteDay, type SiteDayRow,
} from '@/lib/site-day'
import { cn } from '@/lib/utils'
import { NotFoundPage } from './pages'

// own scope: the site manager's work sites only
const MY_SITES = '/sites?kind=work&active=true&per_page=100'
const WORK_SITES = 'hrms.siteDay.sites'
const PRESSED: Record<Mark, string> = {
  present: 'bg-success-bg border-success-border text-success',
  absent: 'bg-destructive-bg border-destructive-border text-destructive',
  sick: 'bg-info-bg border-info-border text-info',
}

/** One exact path per site and date, so the site tabs and the open day share one cache entry. */
const dayPath = (siteId: string, date: string | null) => `/attendance/site-day?${new URLSearchParams({ site_id: siteId, ...(date && { date }) })}`

/** The site manager's last working list on this device (a convenience only: HR owns assignments and the server checks access). */
const readWorking = (): string[] => {
  try {
    const v: unknown = JSON.parse(localStorage.getItem(WORK_SITES) ?? '[]')
    return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string') : []
  } catch {
    return []
  }
}
const writeWorking = (ids: string[]) => {
  try {
    localStorage.setItem(WORK_SITES, JSON.stringify(ids))
  } catch {
    // a private window: nothing is preselected next time
  }
}

const omit = (d: Drafts, id: string): Drafts => Object.fromEntries(Object.entries(d).filter(([k]) => k !== id))

/** The leave code a draft's site stands for, among the site manager's leave types. */
const leaveOf = (d: Draft | null, leave: LeaveSites) => (d ? (Object.entries(leave).find(([, id]) => id === d.site_id)?.[0] ?? null) : null)

/**
 * The site manager's attendance workspace, URL state `?site=&date=` (design Part 2, mockup A; owner, 2026-11-04; PR #22 review).
 * Entry: choose the sites to work on today (a working list, not an assignment), then one site at a time.
 * Morning: «حضور الكل», fix exceptions, «حفظ اليوم». Evening: «انصراف الكل», fix exceptions, save. Unsaved changes are kept per site and
 * date, so switching sites or changing the working list never loses or mixes them. While a site's day is saving, its rows are read-only.
 */
export function SiteDayPage() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const { can } = useAuth()
  const allowed = can('attendance.record')
  const [params, setParams] = useSearchParams()
  const { data: mine = [], err: sitesErr, loading: sitesLoading } = useApi<Row[]>(allowed ? MY_SITES : null)
  const [working, setWorking] = useState<string[] | null>(null) // null: the picker is open
  const chosen = working === null ? [] : mine.filter((s) => working.includes(s.id))
  const site = chosen.find((s) => s.id === params.get('site')) ?? chosen[0]
  const date = params.get('date')
  const path = allowed && site ? dayPath(site.id, date) : null
  const { data, err, loading, reload } = useApi<SiteDay>(path)
  const [all, setAll] = useState<Record<string, Drafts>>({})
  const [filter, setFilter] = useState('')
  const [saveErr, setSaveErr] = useState<unknown>()
  const [savingKey, setSavingKey] = useState<string | null>(null)
  const [savedKey, setSavedKey] = useState<string | null>(null)
  const save = useSave()
  const qc = useQueryClient()
  const siteId: string = site?.id ?? ''
  const dirty = Object.values(all).some((d) => Object.keys(d).length > 0)
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])
  if (!allowed) return <NotFoundPage />

  const unsavedAt = (id: string) => Object.entries(all).some(([k, d]) => k.startsWith(`${id}|`) && Object.keys(d).length > 0)
  const pick = (id: string) => {
    setParams((p) => (p.set('site', id), p), { replace: true })
    setSaveErr(undefined)
  }
  const openDate = (d: string | null) => {
    setParams((p) => (d === null ? p.delete('date') : p.set('date', d), p))
    setSaveErr(undefined)
  }

  if (sitesErr) return <div className="mx-auto w-full max-w-3xl"><ErrorBanner err={sitesErr} shown={[]} /></div>
  if (sitesLoading) return <div className="mx-auto w-full max-w-3xl"><TableSkeleton rows={4} /></div>
  if (!mine.length) return <div className="mx-auto w-full max-w-3xl"><EmptyState icon={MapPinOff} title={t('siteDay.noSites')} /></div>
  if (working === null) {
    const assigned = mine.map((s) => s.id)
    const previous = readWorking().filter((id) => assigned.includes(id))
    return (
      <SitePicker sites={mine} initial={mine.length === 1 ? assigned : previous} unsavedAt={unsavedAt}
        onContinue={(ids) => {
          writeWorking(ids)
          setWorking(ids)
          pick(ids[0])
        }} />
    )
  }

  const day = data?.site.id === siteId ? data : undefined
  const key = day ? `${day.site.id}|${day.date}` : ''
  const drafts = all[key] ?? {}
  const locked = savingKey !== null && savingKey === key
  const setDrafts = (d: Drafts) => {
    if (locked) return
    setAll((a) => ({ ...a, [key]: d }))
    setSavedKey(null)
    setSaveErr(undefined)
  }
  const rows = day?.rows ?? []
  const pending = rows.filter((r) => changed(r, drafts[r.employee.id])).length
  const c = counts(rows, drafts, siteId)
  const isToday = !!day && day.date === day.today

  const onSave = async () => {
    if (!day || !path || locked) return
    // a period-less "9:30" must never reach the API, which would read it as a 24-hour time
    if (stopAtMissingPeriod(document.body)) return
    const bad = rows.filter((r) => { const d = drafts[r.employee.id]; return d && [d.in_time, d.out_time].some((v) => v !== null && !isHhmm(v)) })
    if (bad.length) {
      setSaveErr(new Error(t('siteDay.badTime', { names: bad.map((r) => localName(r.employee as Row, lang)).join('، ') })))
      return
    }
    const savedPath = path
    const savedAt = key
    const sent = drafts // the drafts this request carries; rows stay read-only until it returns
    setSavingKey(savedAt)
    setSaveErr(undefined)
    try {
      const fresh = await save<SiteDay>('/attendance/site-day', { body: saveBody(day.site.id, day.date, rows, sent) }, ['/attendance'])
      qc.setQueryData(queryKey(savedPath), (old: Row | undefined) => ({ ...old, data: fresh }))
      // clear only what was sent: anything changed since then stays unsaved
      setAll((a) => ({ ...a, [savedAt]: Object.fromEntries(Object.entries(a[savedAt] ?? {}).filter(([id, d]) => sent[id] !== d)) }))
      setSavedKey(savedAt)
    } catch (e) {
      setSaveErr(e)
    } finally {
      setSavingKey(null)
    }
  }
  const loadLatest = () => {
    setAll((a) => ({ ...a, [key]: {} }))
    setSaveErr(undefined)
    reload()
  }

  const dateLine = day && (
    <p className="mt-1 text-sm text-muted-foreground">
      <time dateTime={day.date}>{new Intl.DateTimeFormat(intlLocale(lang), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${day.date}T00:00:00Z`))}</time>
      {isToday && ` · ${t('siteDay.today')}`}
      {!isToday && <Button type="button" variant="link" className="ms-2 h-auto px-0 text-sm" onClick={() => openDate(null)}>{t('siteDay.backToToday')}</Button>}
    </p>
  )

  const needle = filter.trim().toLowerCase()
  const shown = needle ? rows.filter((r) => `${r.employee.name_ar} ${r.employee.name_en ?? ''} ${r.employee.code}`.toLowerCase().includes(needle)) : rows
  const workers = shown.filter((r) => r.section === 'workers')
  const engineers = shown.filter((r) => r.section === 'engineers')
  const markN = locked ? 0 : markAllCount(rows, drafts, siteId)
  const outN = locked ? 0 : checkOutCount(rows, drafts, siteId)
  const siteName = site ? localName(day?.site ?? site, lang) : ''
  const crewTools = isToday && (can('employee.crew') || can('employee.propose_laborer'))
  const person = (r: SiteDayRow, removable: boolean) => (
    <PersonRow key={r.employee.id} r={r} draft={current(r, drafts)} siteId={siteId} leave={day?.leave_sites ?? {}} sites={mine} locked={locked} onOpenDate={openDate}
      removable={removable && !locked} onRemoved={reload} set={(d) => setDrafts(d === null ? omit(drafts, r.employee.id) : { ...drafts, [r.employee.id]: d })} />
  )

  let body
  if (err) body = <ErrorBanner err={err} shown={[]} />
  else if (site && (loading || !day)) body = <TableSkeleton rows={4} />
  else if (!rows.length) body = <EmptyState icon={Users} title={t('siteDay.empty')} />
  else
    body = (
      <ul aria-label={t('siteDay.rows')} className="flex flex-col gap-2">
        {workers.map((r) => person(r, isToday && can('employee.crew') && r.employee.type === 'day_laborer' && r.day.state !== 'stored' && !drafts[r.employee.id]))}
        {engineers.length > 0 && (
          <li className="pt-2">
            <h2 className="flex items-center gap-2 text-section-title"><HardHat className="size-4 text-muted-foreground" aria-hidden />{t('siteDay.engineers')}</h2>
          </li>
        )}
        {engineers.map((r) => person(r, false))}
      </ul>
    )

  let status = ''
  if (locked) status = t('siteDay.saving')
  else if (pending > 0) status = t('siteDay.unsaved', { count: pending })
  else if (savedKey === key) status = t('siteDay.saved')

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 lg:max-w-6xl">
      <nav aria-label={t('siteDay.site')} className="flex flex-wrap items-center gap-2">
        {chosen.length > 1 && chosen.map((s) => (
          <SiteTab key={s.id} s={s} active={s.id === siteId} date={date} unsaved={unsavedAt(s.id)} onPick={() => pick(s.id)} />
        ))}
        <Button type="button" variant="ghost" className="min-h-11" onClick={() => setWorking(null)}>{t('siteDay.changeSites')}</Button>
      </nav>
      {site && (
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-page-title text-balance">{siteName}</h1>
            {dateLine}
            <p className="mt-1 text-[13px] text-muted-foreground">{t('siteDay.hrOwnsSites')}</p>
          </div>
          {day && (
            <div className="flex flex-wrap gap-2" role="group" aria-label={t('siteDay.bulk', { site: siteName })}>
              <Button type="button" className="min-h-11" disabled={!markN} onClick={() => setDrafts(markAllPresent(rows, drafts, siteId))}>
                {t('siteDay.markAll', { count: markN })}
              </Button>
              <Button type="button" variant="outline" className="min-h-11" disabled={!outN} onClick={() => setDrafts(checkOutAll(rows, drafts, siteId))}>
                {t('siteDay.checkOutAll', { count: outN })}
              </Button>
              {crewTools && <AddLaborer siteId={siteId} siteName={siteName} canAdd={can('employee.crew')} canPropose={can('employee.propose_laborer')} onDone={reload} />}
            </div>
          )}
        </div>
      )}
      {rows.length > 6 && (
        <div className="relative">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input type="search" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder={t('siteDay.find')} aria-label={t('siteDay.find')} className="min-h-11 ps-9" />
        </div>
      )}
      {saveErr !== undefined && (saveErr instanceof ApiError && saveErr.status === 409 ? (
        <Alert tone="danger">
          <p>{t('siteDay.conflict')}</p>
          <Button type="button" variant="outline" className="mt-2" onClick={loadLatest}>{t('siteDay.loadLatest')}</Button>
        </Alert>
      ) : <ErrorBanner err={saveErr} shown={[]} />)}
      {body}
      {day && rows.length > 0 && (
        <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-t bg-card/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur sm:mx-0 sm:rounded-xl sm:border">
          <p className="flex flex-wrap gap-x-3 text-sm">
            <span>{t('siteDay.count.present', { count: c.present })}</span>
            <span className={cn(c.unrecorded > 0 && 'font-semibold text-warning')}>{t('siteDay.count.unrecorded', { count: c.unrecorded })}</span>
            <span className={cn(c.noCheckout > 0 && 'font-semibold text-warning')}>{t('siteDay.count.noCheckout', { count: c.noCheckout })}</span>
            {c.late > 0 && <span className="font-semibold text-warning">{t('siteDay.lateCount', { count: c.late })}</span>}
          </p>
          <p role="status" className="text-sm text-muted-foreground sm:ms-auto">{status}</p>
          <Button type="button" className="min-h-11 w-full sm:w-auto" disabled={!pending || locked} onClick={() => void onSave()}>{t('siteDay.save')}</Button>
        </div>
      )}
    </div>
  )
}

/**
 * The entry step: which of the site manager's assigned sites they work on now. A working list only; it never changes an assignment.
 * A site left out keeps its unsaved changes, which come back when it is chosen again.
 */
function SitePicker({ sites, initial, unsavedAt, onContinue }: { sites: Row[]; initial: string[]; unsavedAt: (id: string) => boolean; onContinue: (ids: string[]) => void }) {
  const { t, i18n } = useTranslation()
  const [picked, setPicked] = useState<string[]>(initial)
  const toggle = (id: string) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
  const ordered = sites.map((s) => s.id).filter((id) => picked.includes(id))
  const leftOut = sites.filter((s) => !picked.includes(s.id) && unsavedAt(s.id)).map((s) => localName(s, i18n.language))
  return (
    <div className="mx-auto flex w-full max-w-xl flex-col gap-4">
      <div>
        <h1 className="text-page-title text-balance">{t('siteDay.pickTitle')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('siteDay.pickHint')}</p>
      </div>
      <ul aria-label={t('siteDay.pickTitle')} className="grid gap-2">
        {sites.map((s) => {
          const on = picked.includes(s.id)
          return (
            <li key={s.id}>
              <button type="button" role="checkbox" aria-checked={on} onClick={() => toggle(s.id)}
                className={cn('flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border bg-card px-4 text-start shadow-1 outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring', on && 'border-primary bg-primary/10')}>
                <span className="font-semibold">{localName(s, i18n.language)}</span>
                <span className="flex items-center gap-2">
                  {unsavedAt(s.id) && <span className="text-[12px] text-warning">{t('siteDay.unsavedShort')}</span>}
                  <span aria-hidden className={cn('grid size-6 place-items-center rounded-md border', on && 'border-primary bg-primary text-primary-foreground')}>{on ? '✓' : ''}</span>
                </span>
              </button>
            </li>
          )
        })}
      </ul>
      {leftOut.length > 0 && <Alert tone="warning">{t('siteDay.leftOutUnsaved', { sites: leftOut.join('، ') })}</Alert>}
      <p className="text-[13px] text-muted-foreground">{t('siteDay.hrOwnsSites')}</p>
      <Button type="button" className="min-h-11" disabled={!ordered.length} onClick={() => onContinue(ordered)}>{t('siteDay.continue')}</Button>
    </div>
  )
}

/** A site in the switcher, with what is still open there (its day is the same cached query the page opens). */
function SiteTab({ s, active, date, unsaved, onPick }: { s: Row; active: boolean; date: string | null; unsaved: boolean; onPick: () => void }) {
  const { t, i18n } = useTranslation()
  const { data } = useApi<SiteDay>(dayPath(s.id, date))
  const open = data && data.site.id === s.id ? counts(data.rows, {}, s.id) : null
  const todo = open ? open.unrecorded + open.noCheckout : 0
  let state = null
  if (unsaved) state = <span className="text-[12px] font-normal text-warning">{t('siteDay.unsavedShort')}</span>
  else if (open && todo > 0) state = <span className="rounded-full bg-warning-bg px-2 text-[12px] font-normal text-warning">{t('siteDay.todo', { count: todo })}</span>
  else if (open) state = <span className="text-[12px] font-normal text-success">{t('siteDay.done')}</span>
  return (
    <Button type="button" variant="outline" aria-pressed={active} onClick={onPick} className={cn('min-h-11 gap-2', active && 'border-primary bg-primary/10 text-primary')}>
      <span>{localName(s, i18n.language)}</span>
      {state}
    </Button>
  )
}

/** One person: status buttons, then (when present) the arrival and the departure with its difference from the shift end. */
function PersonRow({ r, draft, siteId, leave, sites, set, removable, onRemoved, locked, onOpenDate }: {
  r: SiteDayRow; draft: Draft | null; siteId: string; leave: LeaveSites; sites: Row[]; set: (d: Draft | null) => void; removable: boolean; onRemoved: () => void; locked: boolean
  onOpenDate: (date: string) => void
}) {
  const { t, i18n } = useTranslation()
  const save = useSave()
  const [removeErr, setRemoveErr] = useState<unknown>()
  const lang = i18n.language
  const name = localName(r.employee as Row, lang)
  const present = draft?.site_id === siteId
  const code = leaveOf(draft, leave)
  const mark: Mark | null = present ? 'present' : code === 'absent' || code === 'sick' ? code : null
  const off = !r.can_edit || locked
  const leaveName = (c: string) => t(`attendance.leaveName.${c}`, { defaultValue: c })
  let note: string | null = null
  if (r.why_not) note = t(`siteDay.why.${r.why_not === 'open_day' && !r.open_editable ? 'open_day_hr' : r.why_not}`, { date: r.open_date ?? '' })
  else if (code && mark === null) note = leaveName(code)                       // rest or other
  else if (!draft && r.day.leave_code) note = leaveName(r.day.leave_code)       // a weekly off or holiday
  else if (draft && !present && !code) {
    // stored elsewhere: a leave type the site manager cannot choose, or another work site
    const other = sites.find((s) => s.id === draft.site_id)
    note = r.day.leave_code ? leaveName(r.day.leave_code) : other ? t('siteDay.atSite', { site: localName(other, lang) }) : t('siteDay.otherSite')
  }

  const press = (m: Mark | (typeof MORE)[number]) => {
    if (m === 'present') {
      if (!present) set({ site_id: siteId, in_time: r.shift.start, out_time: null })
      return
    }
    const id = leave[m]
    if (id) set({ site_id: id, in_time: null, out_time: null })
  }
  // TimeInput hands back every keystroke (a half-typed "9:" too) and expects it back as its value; the save checks the result
  const time = (field: 'in_time' | 'out_time') => (v: string) => set({ ...(draft ?? { site_id: siteId, in_time: null, out_time: null }), [field]: v === '' ? null : v })
  const out = draft?.out_time ?? null
  const diff = present && isHhmm(out) ? departureDiff(out, r.shift.end) : null
  const arrival = draft?.in_time ?? null
  const late = present && isHhmm(arrival) ? arrivalDiff(arrival, r.shift.start) : null
  const missingOut = present && isHhmm(draft?.in_time ?? null) && !out
  const remove = async () => {
    setRemoveErr(undefined)
    try {
      await save('/attendance/site-day/crew', { body: { site_id: siteId, employee_id: r.employee.id, action: 'remove' } }, ['/attendance'])
      onRemoved()
    } catch (e) {
      setRemoveErr(e)
    }
  }

  return (
    <li className={cn('rounded-xl border bg-card p-3 shadow-1', draft !== null && changed(r, draft) && 'border-s-4 border-s-warning')}>
      <div role="group" aria-label={name} className="grid gap-2 xl:grid-cols-[minmax(10rem,1fr)_minmax(16rem,20rem)_minmax(10rem,13rem)_minmax(10rem,13rem)] xl:items-center xl:gap-3">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="font-semibold"><bdi>{name}</bdi></div>
            <div className="text-[13px] text-muted-foreground">
              {t(`enum.type.${r.employee.type}`)}
              {note && <> · <span>{note}</span></>}
            </div>
            {r.why_not === 'open_day' && r.open_date && r.open_editable && (
              <Button type="button" variant="link" className="h-auto min-h-11 px-0 text-[13px]" onClick={() => onOpenDate(r.open_date!)}>
                {t('siteDay.openDay', { date: r.open_date })}
              </Button>
            )}
          </div>
          {removable && (
            <Button type="button" variant="ghost" size="icon" className="size-11 shrink-0" aria-label={t('siteDay.removeFromToday', { name })} title={t('siteDay.removeFromToday', { name })} onClick={() => void remove()}>
              <UserMinus aria-hidden />
            </Button>
          )}
        </div>
        <div className="grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
          {MARKS.map((m) => (
            <Button key={m} type="button" variant="outline" disabled={off || (m !== 'present' && !leave[m])} aria-pressed={mark === m} onClick={() => press(m)}
              className={cn('h-11 min-w-0 px-2', mark === m ? cn(PRESSED[m], 'disabled:opacity-100') : 'text-muted-foreground')}>
              {t(`siteDay.${m}`)}
            </Button>
          ))}
          <DropdownMenu dir={lang === 'ar' ? 'rtl' : 'ltr'}>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="outline" size="icon" disabled={off} className={cn('size-11', code && mark === null && 'border-primary text-primary')} aria-label={t('siteDay.more')}>
                <MoreHorizontal aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {MORE.map((m) => (
                <DropdownMenuItem key={m} disabled={!leave[m]} onSelect={() => press(m)}>{leaveName(m)}</DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
        {present ? (
          <div className="grid grid-cols-2 gap-2 xl:col-span-2 xl:gap-3">
            <div className="grid content-start gap-1">
              <span aria-hidden className="text-[12px] text-muted-foreground">{t('siteDay.arrival')}</span>
              <TimeInput label={t('siteDay.arrival')} aria-label={t('siteDay.arrivalOf', { name })} value={draft?.in_time ?? ''} disabled={off} onChange={time('in_time')} quietHint />
              {late !== null && late > 0 && <p className="text-[13px] text-warning">{t('siteDay.late', { count: late })}</p>}
            </div>
            <div className="grid content-start gap-1">
              <span aria-hidden className="text-[12px] text-muted-foreground">{t('siteDay.departure')}</span>
              <TimeInput label={t('siteDay.departure')} aria-label={t('siteDay.departureOf', { name })} value={draft?.out_time ?? ''} disabled={off} onChange={time('out_time')} quietHint />
              {missingOut && <p className="text-[13px] text-warning">{t('siteDay.missingCheckout')}</p>}
              {diff !== null && diff !== 0 && (
                <p className={cn('text-[13px]', diff < 0 ? 'text-warning' : 'text-info')}>
                  {diff < 0 ? t('siteDay.early', { count: -diff }) : t('siteDay.later', { count: diff })}
                </p>
              )}
            </div>
          </div>
        ) : <div className="hidden xl:col-span-2 xl:block" />}
      </div>
      {removeErr !== undefined && <div className="mt-2"><ErrorBanner err={removeErr} shown={[]} /></div>}
    </li>
  )
}

type Match = { id: string; code: number; name_ar: string; name_en: string | null; site: { id: string; name_ar: string; name_en: string | null } | null; here: boolean; can_add: boolean }

/** Find an existing day laborer to add to today's crew, or add a new one with a proposed daily wage for HR to review. */
function AddLaborer({ siteId, siteName, canAdd, canPropose, onDone }: { siteId: string; siteName: string; canAdd: boolean; canPropose: boolean; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const save = useSave()
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [wage, setWage] = useState('')
  const [phone, setPhone] = useState('')
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const search = q.trim()
  const { data: matches = [], loading } = useApi<Match[]>(open && search.length >= 2 ? `/attendance/site-day/laborers?${new URLSearchParams({ site_id: siteId, search })}` : null)
  const reset = () => {
    setQ('')
    setWage('')
    setPhone('')
    setErr(undefined)
  }
  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    setErr(undefined)
    try {
      await fn()
      setOpen(false)
      reset()
      onDone()
    } catch (e) {
      setErr(e)
    } finally {
      setBusy(false)
    }
  }
  const add = (id: string) => run(() => save('/attendance/site-day/crew', { body: { site_id: siteId, employee_id: id, action: 'add' } }, ['/attendance']))
  const propose = () => run(() => save('/attendance/site-day/laborers', { body: { site_id: siteId, name_ar: search, daily_wage: wage.trim(), phone: phone.trim() || null } }, ['/attendance']))
  const errs = toErrs(err)

  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (!o) reset() }}>
      <Button type="button" variant="outline" className="min-h-11" onClick={() => setOpen(true)}><UserPlus aria-hidden />{t('siteDay.addLaborer')}</Button>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('siteDay.addLaborer')}</DialogTitle>
          <DialogDescription>{t('siteDay.addLaborerHint', { site: siteName })}</DialogDescription>
        </DialogHeader>
        <DialogBody className="grid gap-3">
          <label className="grid gap-1 text-sm font-medium">
            {t('siteDay.laborerName')}
            <Input value={q} onChange={(e) => setQ(e.target.value)} autoComplete="off" className="min-h-11" aria-invalid={!!errs.name_ar || undefined} />
          </label>
          {search.length >= 2 && (
            <section aria-label={t('siteDay.matches')} className="grid gap-2">
              {loading && <p className="text-sm text-muted-foreground">{t('siteDay.searching')}</p>}
              {!loading && matches.length === 0 && <p className="text-sm text-muted-foreground">{t('siteDay.noMatches')}</p>}
              {!loading && matches.length > 0 && (
                <ul className="grid gap-2">
                  {matches.map((m) => (
                    <li key={m.id} className="flex items-center justify-between gap-2 rounded-lg border p-2">
                      <div className="min-w-0 text-sm">
                        <div className="font-semibold"><bdi>{localName(m as Row, lang)}</bdi> <span className="font-normal text-muted-foreground">#{m.code}</span></div>
                        <div className="text-[13px] text-muted-foreground">{m.site ? localName(m.site as Row, lang) : t('site.homeNone')}</div>
                      </div>
                      {m.here && <span className="text-[13px] text-success">{t('siteDay.alreadyHere')}</span>}
                      {!m.here && m.can_add && canAdd && (
                        <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => void add(m.id)}>{t('siteDay.addToToday')}</Button>
                      )}
                      {!m.here && !m.can_add && <span className="max-w-40 text-[13px] text-muted-foreground">{t('siteDay.onOtherSite')}</span>}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          )}
          {canPropose && search.length >= 2 && (
            <section aria-label={t('siteDay.newLaborer')} className="grid gap-3 rounded-lg border border-dashed p-3">
              <h3 className="text-sm font-semibold">{t('siteDay.newLaborer')}</h3>
              <label className="grid gap-1 text-sm font-medium">
                {t('siteDay.proposedWage')}
                <Input value={wage} onChange={(e) => setWage(e.target.value)} inputMode="decimal" dir="ltr" className="min-h-11 rtl:text-right" aria-invalid={!!errs.daily_wage || undefined} />
                {errs.daily_wage && <span className="text-[13px] text-destructive">{errs.daily_wage}</span>}
              </label>
              <label className="grid gap-1 text-sm font-medium">
                {t('siteDay.mobile')}
                <Input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="numeric" autoComplete="tel" maxLength={11} dir="ltr" className="min-h-11 rtl:text-right" aria-invalid={!!errs.phone || undefined} />
                {errs.phone && <span className="text-[13px] text-destructive">{errs.phone}</span>}
              </label>
              <p className="text-[13px] text-muted-foreground">{t('siteDay.wageNeedsHr')}</p>
              <Button type="button" className="min-h-11" disabled={busy || !wage.trim()} onClick={() => void propose()}>{t('siteDay.addNewLaborer')}</Button>
            </section>
          )}
          <ErrorBanner err={err} shown={['daily_wage', 'phone']} />
        </DialogBody>
      </DialogContent>
    </Dialog>
  )
}

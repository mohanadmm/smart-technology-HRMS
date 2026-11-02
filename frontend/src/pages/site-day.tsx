import { HardHat, MapPinOff, MoreHorizontal, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { EmptyState } from '@/components/common/EmptyState'
import { ErrorBanner, intlLocale, localName, type Row, SelectField } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/lib/auth'
import { useApi } from '@/lib/query'
import { type Mark, markOf, MARKS, type SiteDay, type SiteDayRow } from '@/lib/site-day'
import { cn } from '@/lib/utils'
import { NotFoundPage } from './pages'

// own scope: the manager's work sites only
const MY_SITES = '/sites?kind=work&active=true&per_page=100'
// a pressed button stays fully visible although the whole row is read-only in this slice
const PRESSED: Record<Mark, string> = {
  present: 'bg-success-bg border-success-border text-success',
  absent: 'bg-destructive-bg border-destructive-border text-destructive',
  sick: 'bg-info-bg border-info-border text-info',
}

/** The manager's day at one of their sites, URL state `?site=&date=` (design Part 2, mockup A). Read-only until marking lands. */
export function SiteDayPage() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language
  const { can } = useAuth()
  const allowed = can('attendance.record')
  const [params, setParams] = useSearchParams()
  const { data: mine = [], err: sitesErr, loading: sitesLoading } = useApi<Row[]>(allowed ? MY_SITES : null)
  const site = mine.find((s) => s.id === params.get('site')) ?? mine[0]
  const date = params.get('date')
  const { data, err, loading } = useApi<SiteDay>(allowed && site ? `/attendance/site-day?${new URLSearchParams({ site_id: site.id, ...(date && { date }) })}` : null)
  if (!allowed) return <NotFoundPage />

  const pick = (id: string) => setParams((p) => (p.set('site', id), p), { replace: true })
  const siteName = (id: string | null) => {
    const s = mine.find((x) => x.id === id)
    return s ? t('siteDay.atSite', { site: localName(s, lang) }) : t('siteDay.otherSite')
  }
  const day = data?.site.id === site?.id ? data : undefined
  const dateLine = day && (
    <p className="mt-1 text-sm text-muted-foreground">
      <time dateTime={day.date}>{new Intl.DateTimeFormat(intlLocale(lang), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${day.date}T00:00:00Z`))}</time>
      {day.date === day.today && ` · ${t('siteDay.today')}`}
    </p>
  )

  const card = (r: SiteDayRow) => {
    const name = localName(r.employee as Row, lang)
    const mark = site ? markOf(r.day, site.id) : null
    const { leave_code, site_id } = r.day
    const where = leave_code !== null && mark === null ? t(`attendance.leaveName.${leave_code}`, { defaultValue: leave_code })
      : r.day.state === 'stored' && leave_code === null && site_id && site_id !== site?.id ? siteName(site_id) : null
    return (
      <li key={r.employee.id} className="rounded-xl border bg-card p-3 shadow-1">
        <div role="group" aria-label={name}>
          <div className="font-semibold"><bdi>{name}</bdi></div>
          <div className="text-[13px] text-muted-foreground">
            {t(`enum.type.${r.employee.type}`)}
            {where && <> · <span>{where}</span></>}
          </div>
          <div className="mt-2 grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
            {MARKS.map((m) => (
              <Button key={m} type="button" variant="outline" disabled aria-pressed={mark === m}
                className={cn('h-11 min-w-0 px-2', mark === m ? cn(PRESSED[m], 'disabled:opacity-100') : 'text-muted-foreground')}>
                {t(`siteDay.${m}`)}
              </Button>
            ))}
            <Button type="button" variant="outline" size="icon" disabled className="size-11" aria-label={t('siteDay.more')}>
              <MoreHorizontal aria-hidden />
            </Button>
          </div>
        </div>
      </li>
    )
  }

  const workers = day?.rows.filter((r) => r.section === 'workers') ?? []
  const engineers = day?.rows.filter((r) => r.section === 'engineers') ?? []
  let body
  if (sitesErr || err) body = <ErrorBanner err={sitesErr ?? err} shown={[]} />
  else if (sitesLoading || (site && (loading || !day))) body = <TableSkeleton rows={4} />
  else if (!site) body = <EmptyState icon={MapPinOff} title={t('siteDay.noSites')} />
  else if (!day?.rows.length) body = <EmptyState icon={Users} title={t('siteDay.empty')} />
  else
    body = (
      <ul aria-label={t('siteDay.rows')} className="flex flex-col gap-2">
        {workers.map(card)}
        {engineers.length > 0 && (
          <li className="pt-2">
            <h2 className="flex items-center gap-2 text-section-title"><HardHat className="size-4 text-muted-foreground" aria-hidden />{t('siteDay.engineers')}</h2>
          </li>
        )}
        {engineers.map(card)}
      </ul>
    )

  return (
    <div className="mx-auto flex w-full max-w-3xl flex-col gap-4">
      {mine.length > 1 && site && (
        <SelectField name="site" label={t('siteDay.site')} values={{ site: site.id }} set={(_, v) => pick(v)} errs={{}} options={mine.map((s) => [s.id, localName(s, lang)])} />
      )}
      {site && (
        <div>
          <h1 className="text-page-title text-balance">{localName(day?.site ?? site, lang)}</h1>
          {dateLine}
        </div>
      )}
      {body}
    </div>
  )
}

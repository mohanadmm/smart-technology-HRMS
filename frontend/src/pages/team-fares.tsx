import { Bus, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState } from '@/components/common/EmptyState'
import { FilterSelect } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { ErrorBanner, useMoney } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import { useApi } from '@/lib/query'
import { cn } from '@/lib/utils'
import { NotFoundPage } from './pages'

export type Ref = { id: string; name: string }
export type TeamFareRow = { employee_id: string; code: number; name: string; type: 'engineer' | 'permanent_worker' | 'day_laborer'; residence: Ref | null; site: Ref | null; amount: string | null; source: 'override' | 'default' | 'none' | 'no_residence'; default_amount: string | null; overrides: { site_id: string; amount: string }[] }
export type TeamFares = { sites: Ref[]; rows: TeamFareRow[] }

const TONE: Partial<Record<TeamFareRow['source'], BadgeVariant>> = { default: 'neutral', override: 'info', none: 'warning' }
const isEngineer = (r: TeamFareRow) => r.type === 'engineer'
const missing = (r: TeamFareRow) => !isEngineer(r) && r.amount === null
const CHIP = 'min-h-tap rounded-full border px-3 text-[13px] font-medium transition-colors outline-none hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-solid focus-visible:outline-ring aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary'

/** «مواصلات التيم»: the team's fare today from residence × site, corrected per worker (mockup 1). HR picks a team lead first. */
export function TeamFaresPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const allowed = can('fare.manage')
  const all = can('fare.manage.all')
  const [lead, setLead] = useState('')
  const leads = useApi<Ref[]>(allowed && all ? '/sites/manager-candidates' : null)
  const path = !allowed ? null : all ? (lead ? `/team-fares?manager_id=${lead}` : null) : '/team-fares'
  const { data, err, loading } = useApi<TeamFares>(path)
  if (!allowed) return <NotFoundPage />
  return (
    <div className="space-y-4">
      <PageHeader title={t('teamFare.title')} description={t('teamFare.subtitle')} />
      {all && <FilterSelect value={lead} onChange={setLead} label={t('fare.lead')} allLabel={t('teamFare.pickLead')} options={(leads.data ?? []).map((m) => [m.id, m.name])} />}
      <ErrorBanner err={leads.err ?? err} shown={[]} />
      {path === null ? (
        <EmptyState icon={Users} title={t('teamFare.pickLead')} />
      ) : loading || !data ? (
        !err && <TableSkeleton />
      ) : !data.rows.length ? (
        <EmptyState icon={Bus} title={t('teamFare.empty')} />
      ) : (
        <TeamTable team={data} />
      )}
    </div>
  )
}

type Chip = 'all' | 'missing' | string // a site id

function TeamTable({ team }: { team: TeamFares }) {
  const { t } = useTranslation()
  const money = useMoney()
  const [chip, setChip] = useState<Chip>('all')
  const siteName = (id: string) => team.sites.find((s) => s.id === id)?.name ?? ''
  const shown = team.rows.filter((r) => chip === 'all' || (chip === 'missing' ? missing(r) : r.site?.id === chip || r.overrides.some((o) => o.site_id === chip)))
  const chips: [Chip, string][] = [['all', t('teamFare.all', { count: team.rows.length })], ...team.sites.map((s): [Chip, string] => [s.id, s.name]), ['missing', t('teamFare.missing', { count: team.rows.filter(missing).length })]]
  return (
    <>
      <div className="flex flex-wrap gap-2">
        {chips.map(([value, label]) => (
          <button key={value} type="button" aria-pressed={chip === value} onClick={() => setChip(value)} className={cn(CHIP, 'bg-card text-muted-foreground')}>
            {label}
          </button>
        ))}
      </div>
      <Card className="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              {(['worker', 'residence', 'site', 'fare', 'source'] as const).map((c) => <TableHead key={c}>{t(`teamFare.col.${c}`)}</TableHead>)}
            </TableRow>
          </TableHeader>
          <TableBody>
            {shown.map((r) => (
              <TableRow key={r.employee_id}>
                <TableCell>
                  <div className="font-medium"><bdi>{r.name}</bdi></div>
                  <div className="text-xs text-muted-foreground">{t(`enum.type.${r.type}`)}</div>
                </TableCell>
                <TableCell>{r.residence ? r.residence.name : <Badge variant="danger">{t('teamFare.noResidence')}</Badge>}</TableCell>
                <TableCell>{isEngineer(r) ? t('teamFare.byDay') : r.site?.name}</TableCell>
                <TableCell className="tabular-nums">
                  {isEngineer(r) ? (r.overrides.length ? r.overrides.map((o) => <div key={o.site_id}>{`${siteName(o.site_id)} ${money(o.amount)}`}</div>) : '—') : money(r.amount)}
                </TableCell>
                <TableCell>
                  {isEngineer(r) ? (
                    <span className="text-xs text-muted-foreground">{t('teamFare.source.engineer')}</span>
                  ) : TONE[r.source] ? (
                    <Badge variant={TONE[r.source]}>{t(`teamFare.source.${r.source}`)}</Badge>
                  ) : (
                    <span className="text-xs text-muted-foreground">{t(`teamFare.source.${r.source}`)}</span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
    </>
  )
}

import { Bus, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { EmptyState } from '@/components/common/EmptyState'
import { chipCls, FilterSelect } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { cairoToday, type Controlled, daysBefore, ErrorBanner, FormDialog, SelectField, TextField, useMoney } from '@/components/form'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/lib/auth'
import { useApi, useSave } from '@/lib/query'
import { NotFoundPage } from './pages'

export type Ref = { id: string; name: string }
export type TeamFareRow = { employee_id: string; code: number; name: string; type: 'engineer' | 'permanent_worker' | 'day_laborer'; residence: Ref | null; site: Ref | null; amount: string | null; source: 'override' | 'default' | 'none' | 'no_residence'; default_amount: string | null; overrides: { site_id: string; amount: string }[] }
export type TeamFares = { sites: Ref[]; rows: TeamFareRow[] }

const TONE: Partial<Record<TeamFareRow['source'], BadgeVariant>> = { default: 'neutral', override: 'info', none: 'warning' }
const isEngineer = (r: TeamFareRow) => r.type === 'engineer'
const missing = (r: TeamFareRow) => !isEngineer(r) && r.amount === null

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
  const [editing, setEditing] = useState<TeamFareRow>()
  const siteName = (id: string) => team.sites.find((s) => s.id === id)?.name ?? ''
  const shown = team.rows.filter((r) => chip === 'all' || (chip === 'missing' ? missing(r) : r.site?.id === chip || r.overrides.some((o) => o.site_id === chip)))
  const chips: [Chip, string][] = [['all', t('teamFare.all', { count: team.rows.length })], ...team.sites.map((s): [Chip, string] => [s.id, s.name]), ['missing', t('teamFare.missing', { count: team.rows.filter(missing).length })]]
  return (
    <>
      <div className="flex flex-wrap gap-2">
        {chips.map(([value, label]) => (
          <button key={value} type="button" aria-pressed={chip === value} onClick={() => setChip(value)} className={chipCls}>
            {label}
          </button>
        ))}
      </div>
      <Card className="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              {(['worker', 'residence', 'site', 'fare', 'source'] as const).map((c) => <TableHead key={c}>{t(`teamFare.col.${c}`)}</TableHead>)}
              <TableHead>
                <span className="sr-only">{t('common.actions')}</span>
              </TableHead>
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
                <TableCell>
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
                <TableCell className="text-end">
                  <Button type="button" variant="outline" size="sm" aria-label={t('teamFare.edit', { name: r.name })} onClick={() => setEditing(r)}>
                    {t('fare.edit')}
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
      {editing && <TeamFareDialog row={editing} team={team} open onOpenChange={() => setEditing(undefined)} />}
    </>
  )
}

/** One worker's fare from a date: an override for this worker (default), or the residence × site fare for everyone in that pair. Engineers only get an override, at a site they pick. */
function TeamFareDialog({ row, team, ...dialog }: { row: TeamFareRow; team: TeamFares } & Controlled) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const money = useMoney()
  const send = useSave()
  const today = cairoToday()
  const engineer = isEngineer(row)
  const pair = !engineer && row.residence && row.site ? { residence: row.residence, site: row.site } : null
  const pairCount = pair ? team.rows.filter((r) => !isEngineer(r) && r.residence?.id === pair.residence.id && r.site?.id === pair.site.id).length : 0
  const where = row.site && !engineer ? ` · ${row.residence ? `${row.residence.name} ← ` : ''}${row.site.name}` : ''
  const invalidate = ['/team-fares', '/fares']
  const submit = (form: Record<string, string>) =>
    form.scope === 'pair' && pair
      ? send('/fares', { body: { residence_id: pair.residence.id, site_id: pair.site.id, effective_from: form.effective_from, amount: form.amount } }, invalidate)
      : send(`/employees/${row.employee_id}/fare-overrides`, { body: { site_id: form.site_id, effective_from: form.effective_from, amount: form.amount } }, invalidate)
  return (
    <FormDialog
      {...dialog}
      title={t('teamFare.edit', { name: row.name }) + where}
      init={{ effective_from: today, amount: '', scope: 'worker', site_id: engineer ? team.sites[0]?.id ?? '' : row.site?.id ?? '' }}
      submit={submit}
      shown={['effective_from', 'amount', 'site_id']}
    >
      {(f) => (
        <>
          {row.default_amount !== null && <Badge variant="neutral" className="justify-self-start">{t('teamFare.auto', { amount: money(row.default_amount) })}</Badge>}
          {engineer && <SelectField name="site_id" label={t('teamFare.col.site')} options={team.sites.map((s) => [s.id, s.name])} {...f} />}
          <TextField name="amount" label={t('teamFare.amount')} inputMode="decimal" {...f} />
          {/* a team lead may backdate one day only; the server enforces it too */}
          <TextField name="effective_from" label={t('teamFare.from')} type="date" min={can('fare.manage.all') ? undefined : daysBefore(today, 1)} {...f} />
          {pair && (
            <div className="grid gap-2">
              {(['worker', 'pair'] as const).map((scope) => (
                <label key={scope} className="flex min-h-tap items-center gap-2 text-sm">
                  <input type="radio" name="scope" checked={f.values.scope === scope} onChange={() => f.set('scope', scope)} />
                  {scope === 'worker' ? t('teamFare.scope.worker') : t('teamFare.scope.pair', { residence: pair.residence.name, site: pair.site.name, count: pairCount })}
                </label>
              ))}
            </div>
          )}
        </>
      )}
    </FormDialog>
  )
}

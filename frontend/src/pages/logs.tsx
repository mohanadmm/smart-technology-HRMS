import { Activity } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { DataList } from '@/components/common/DataList'
import { PageHeader } from '@/components/common/PageHeader'
import { ErrorBanner, type Row, SelectField, TextField, toErrs, useCairoTime, useForm, useList } from '@/components/form'
import { Badge, type BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuth } from '@/lib/auth'
import { USERS, useApi } from '@/lib/query'
import { Actor, show } from './audit'

const PER_PAGE = 20
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
const tone = (status: number): BadgeVariant => (status >= 500 ? 'danger' : status >= 400 ? 'warning' : 'success')

function Details({ entry, onClose }: { entry: Row; onClose: () => void }) {
  const { t } = useTranslation()
  const time = useCairoTime()
  // IP and browser reuse the sessions list's labels
  const meta = [['logs.requestId', entry.request_id], ['console.ip', entry.ip_address], ['logs.route', entry.route], ['console.userAgent', entry.user_agent]] as const
  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent side="right" className="sm:max-w-[720px]">
        <SheetHeader>
          <SheetTitle dir="ltr" className="text-start font-mono text-sm [overflow-wrap:anywhere]">
            {entry.method} {entry.path}
          </SheetTitle>
          <SheetDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <Badge variant={tone(entry.status)}>{entry.status}</Badge>
            <span>{time(entry.occurred_at)} · {entry.user?.name ?? '—'} · {t('logs.ms', { n: entry.duration_ms })}</span>
          </SheetDescription>
        </SheetHeader>
        <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain p-5">
          <dl className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
            {meta.map(([k, v]) => (
              <div key={k} className={k === 'logs.route' || k === 'console.userAgent' ? 'min-w-0 sm:col-span-2' : 'min-w-0'}>
                <dt className="text-xs text-muted-foreground">{t(k)}</dt>
                <dd dir="ltr" className="text-start font-mono text-xs [overflow-wrap:anywhere]">{show(v)}</dd>
              </div>
            ))}
          </dl>
          {(['query', 'request_body', 'response_body'] as const).map((k) => (
            <section key={k} aria-label={t(`logs.${k}`)} className="min-w-0 rounded-lg border p-3">
              <h3 className="mb-2 text-xs font-semibold text-muted-foreground">{t(`logs.${k}`)}</h3>
              <pre dir="ltr" className="text-start font-mono text-xs whitespace-pre-wrap [overflow-wrap:anywhere]">{show(entry[k])}</pre>
            </section>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  )
}

/** The Requests tab. `request_id` lives in the URL so other pages can link to one request. */
// ponytail: no tab bar while Requests is the only tab; the Errors tab adds it, synced to `?tab=`.
export function LogsPage() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const time = useCairoTime()
  const [params, setParams] = useSearchParams()
  const { form, setForm, set } = useForm()
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<Row>()
  const canUsers = can('user.view')
  const { data: users = [], err: usersErr } = useApi<Row[]>(canUsers ? USERS : null) // shared with the accounts list
  const path = useDebounced(form.path ?? '', 250) // the box stays instant; the request waits for a pause in typing
  const filters = { ...form, path, request_id: params.get('request_id') ?? '' }
  const qs = new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), page: String(page), per_page: String(PER_PAGE) })
  const { rows, total, err, loading } = useList(`/request-log?${qs}`)
  const f = { values: form, set: (k: string, v: string) => { set(k, v); setPage(1) }, errs: toErrs(err) }
  const clear = () => {
    setForm({})
    setPage(1)
    setParams((p) => { p.delete('request_id'); return p }, { replace: true })
  }
  return (
    <div className="space-y-6">
      <PageHeader title={t('page.logs')} description={t('logs.subtitle')} />
      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <TextField name="from" label={t('logs.from')} type="date" {...f} />
            <TextField name="to" label={t('logs.to')} type="date" {...f} />
            <SelectField name="status" label={t('logs.status')} blank={t('common.all')} options={[['failed', t('logs.statusFilter.failed')], ['server', t('logs.statusFilter.server')]]} {...f} />
            <SelectField name="method" label={t('logs.method')} blank={t('common.all')} options={METHODS.map((m) => [m, m])} {...f} />
            {canUsers && <SelectField name="user_id" label={t('logs.account')} blank={t('common.all')} options={users.map((u) => [u.id, u.name])} {...f} />}
            <TextField name="path" label={t('logs.path')} type="search" dir="ltr" {...f} />
          </div>
          {(Object.values(form).some(Boolean) || params.has('request_id')) && (
            <Button variant="ghost" size="sm" onClick={clear}>
              {t('common.clearFilters')}
            </Button>
          )}
          <ErrorBanner err={err} shown={['from', 'to', 'status', 'method', 'user_id', 'path']} />
          <ErrorBanner err={usersErr} shown={[]} />
        </div>
        <DataList
          label={t('page.logs')}
          rows={rows}
          rowKey={(r) => r.id}
          title={{ header: t('logs.time'), cell: (r) => time(r.occurred_at) }}
          status={{ header: t('logs.status'), cell: (r) => <Badge variant={tone(r.status)}>{r.status}</Badge> }}
          fields={[
            {
              header: t('logs.request'),
              cell: (r) => (
                <span dir="ltr" title={`${r.method} ${r.path}`} className="inline-block max-w-full truncate align-bottom font-mono text-xs sm:max-w-xs lg:max-w-md">
                  <span className="font-semibold">{r.method}</span> <span>{r.path}</span>
                </span>
              ),
            },
            { header: t('logs.duration'), cell: (r) => r.duration_ms, numeric: true },
            { header: t('logs.account'), cell: (r) => <Actor user={r.user} />, tableFrom: 'md' },
          ]}
          actions={(r) => (
            <Button variant="ghost" size="sm" onClick={() => setOpen(r)}>
              {t('logs.details')}
            </Button>
          )}
          loading={loading}
          empty={{ icon: Activity, title: t('common.noResults') }}
          page={{ page, pageSize: PER_PAGE, total, onPageChange: setPage }}
        />
      </Card>
      {open && <Details entry={open} onClose={() => setOpen(undefined)} />}
    </div>
  )
}

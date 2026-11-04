import { useState } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { SearchInput } from '@/components/common/Filters'
import { StatusBadge } from '@/components/common/StatusBadge'
import { type Controlled, ErrorBanner, Field, type FormApi, FormDialog, localName, ltrDates, type Row, SelectField, TextField, useCairoTime } from '@/components/form'
import type { BadgeVariant } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/misc'
import { useDebounced } from '@/hooks/useDebounced'
import { useAuth } from '@/lib/auth'
import { useApi, useList, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'
import type { LeaveRequest } from './leave'

export const STATUS: Record<string, BadgeVariant> = { pending: 'warning', escalated: 'info', approved: 'success', refused: 'danger', cancelled: 'neutral' }

const KINDS = ['annual', 'casual', 'sick', 'unpaid'] as const

/** `GET /leave/requests/preview`: the server's count and split; the dialog shows these numbers and computes none. */
type Preview = { days: number; unpaid: number; remaining_after: number | null }

const FIELDS = ['employee_id', 'kind', 'from_date', 'to_date', 'note']

/** Filing a leave request. `employee` fixes and hides the employee (the employee page); without it a picker searches the employees in reach. */
export function RequestDialog({ open, onOpenChange, employee }: Required<Controlled> & { employee?: Row }) {
  const { t } = useTranslation()
  const send = useSave()
  const submit = (f: Row) => {
    const body = { employee_id: f.employee_id, kind: f.kind, from_date: f.from_date, to_date: f.to_date, note: f.note.trim() || null }
    return send('/leave/requests', { body }, ['/leave/requests', `/employees/${f.employee_id}/leave`])
  }
  return (
    <FormDialog
      title={t('leave.request.title')}
      open={open}
      onOpenChange={onOpenChange}
      init={{ employee_id: employee?.id ?? '', kind: 'annual', from_date: '', to_date: '', note: '' }}
      submit={submit}
      onDone={() => toast.success(t('leave.request.sent'))}
      shown={FIELDS}
      footer="leave.request.submit"
      clearErrOnEdit
    >
      {(f) => (
        <>
          {!employee && <EmployeePicker {...f} />}
          <SelectField name="kind" label={t('leave.request.kind')} options={KINDS.map((k) => [k, t(`attendance.leaveName.${k}`)])} {...f} />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField name="from_date" label={t('leave.request.from')} type="date" {...f} />
            <TextField name="to_date" label={t('leave.request.to')} type="date" {...f} />
          </div>
          <PreviewLine values={f.values} />
          <Field name="note" id="note" label={t('leave.request.note')} errs={f.errs}>
            {(props) => <Textarea {...props} value={f.values.note} onChange={(e) => f.set('note', e.target.value)} />}
          </Field>
        </>
      )}
    </FormDialog>
  )
}

/** The server's count, balance after and unpaid days (or its refusal) for the values as they are now; nothing while the answer is for older values. */
function PreviewLine({ values: v }: { values: Row }) {
  const { t } = useTranslation()
  const live = v.employee_id && v.kind && v.from_date && v.to_date ? new URLSearchParams({ employee_id: v.employee_id, kind: v.kind, from_date: v.from_date, to_date: v.to_date }).toString() : null
  const query = useDebounced(live, 300)
  const preview = useApi<Preview>(query && `/leave/requests/preview?${query}`)
  if (!live || query !== live) return null
  if (preview.err) return <ErrorBanner err={preview.err} shown={[]} />
  const p = preview.data
  if (!p) return null
  return (
    <>
      <p role="status" className="text-sm">
        {t('leave.request.days', { count: p.days })} {t('leave.request.excluded')}
        {/* ltr: in Arabic text a leading minus would otherwise land after the digits */}
        {p.remaining_after !== null && <Trans i18nKey="leave.request.after" components={{ n: <bdi dir="ltr">{String(p.remaining_after)}</bdi> }} />}
      </p>
      {p.unpaid > 0 && <Alert tone="warning">{t('leave.request.unpaid', { count: p.unpaid })}</Alert>}
    </>
  )
}

/** A search box over `GET /employees` (the server keeps a manager to the employees in reach) and a select of the matches. */
function EmployeePicker({ values, set, errs }: FormApi) {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const search = useDebounced(q, 250)
  const { rows } = useList(`/employees?${new URLSearchParams({ per_page: '20', ...(search && { search }) })}`)
  // the chosen employee stays an option when a later search no longer matches it
  const [picked, setPicked] = useState<Row>()
  const options = picked && !rows.some((r) => r.id === picked.id) ? [picked, ...rows] : rows
  const pick = (k: string, id: string) => {
    set(k, id)
    setPicked(options.find((r) => r.id === id))
  }
  return (
    <div className="grid gap-2">
      <SearchInput value={q} onChange={setQ} placeholder={t('leave.search')} className="sm:w-full" />
      <SelectField name="employee_id" label={t('employee.employee')} blank={t('leave.request.pick')} options={options.map((r) => [r.id, `${localName(r, i18n.language)} (${r.code})`])} values={values} set={pick} errs={errs} />
    </div>
  )
}

/** `GET /leave/requests/{id}`: the list row, the decision so far, and while pending or escalated the approval the server would make now. */
type Shown = LeaveRequest & {
  decision_reason: string | null
  decided_by: { id: string; name: string } | null
  decided_at: string | null
  approval: { days: number; balance: { remaining: number | null; casual_remaining: number | null } | null; keep: number; unpaid: number; remaining_after: number | null; blocked: string[] } | null
}
type Action = 'approve' | 'refuse' | 'escalate'

/** One request by id (a link may point past the loaded list page): its details, and the decisions the reader may take on it now. */
export function DecideDialog({ id, open, onOpenChange }: { id: string } & Required<Controlled>) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('leave.decide.title')}</DialogTitle>
        </DialogHeader>
        <DecideBody id={id} close={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function DecideBody({ id, close }: { id: string; close: () => void }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const time = useCairoTime()
  const send = useSave()
  const path = `/leave/requests/${id}`
  const { data: r, err: loadErr } = useApi<Shown>(path, { fresh: true })
  const [err, setErr] = useState<unknown>()
  const [busy, setBusy] = useState(false)
  const [asking, setAsking] = useState<Action>()
  if (!r) return <DialogBody>{loadErr ? <ErrorBanner err={loadErr} shown={[]} /> : <Skeleton className="h-40" />}</DialogBody>

  const a = r.approval
  const actions: Action[] = r.status === 'pending' && can('leave.decide') ? ['escalate', 'refuse', 'approve'] : r.status === 'escalated' && can('leave.decide_escalated') ? ['refuse', 'approve'] : []
  // a refusal (409 stale or blocked days) also marks the request stale, so it reloads with the server's current status and blocked dates
  const decide = (action: Action, reason?: string) =>
    send(`${path}/${action}`, { body: { version: r.version, ...(reason && { reason }) } }, ['/leave', '/attendance', ...(r.employee ? [`/employees/${r.employee.id}/leave`] : [])])
  const done = (action: Action) => {
    toast.success(t(`leave.decide.${action}.done`))
    close()
  }
  const approve = async () => {
    setBusy(true)
    setErr(undefined)
    try {
      await decide('approve')
      done('approve')
    } catch (e) {
      setErr(e)
    } finally {
      setBusy(false)
    }
  }
  const kind = t(`attendance.leaveName.${r.kind}`)

  return (
    <>
      <DialogBody className="grid gap-4">
        <ErrorBanner err={err} shown={[]} />
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          <Item label={t('employee.employee')}>{r.employee ? <>{localName(r.employee, i18n.language)} (<bdi dir="ltr">{r.employee.code}</bdi>)</> : '—'}</Item>
          <Item label={t('leave.requests.kind')}>{kind}</Item>
          <Item label={t('leave.requests.dates')}>{ltrDates(t('leave.requests.range', { from: r.from_date, to: r.to_date }))}</Item>
          <Item label={t('leave.requests.days')}>{a?.days ?? r.days}</Item>
          <Item label={t('leave.decide.requestedBy')}>{r.requested_by?.name ?? '—'}</Item>
          <Item label={t('common.status')}><StatusBadge variant={STATUS[r.status] ?? 'neutral'} label={t(`leave.status.${r.status}`)} /></Item>
          {a?.balance && a.remaining_after !== null && (
            <>
              <Item label={t('leave.decide.before')}><Count n={a.balance.remaining} /></Item>
              {r.kind === 'casual' && <Item label={t('leave.decide.casualBefore')}><Count n={a.balance.casual_remaining} /></Item>}
              <Item label={t('leave.decide.after')}><Count n={a.remaining_after} /></Item>
            </>
          )}
          {r.decided_by && <Item label={t('leave.decide.decidedBy')}>{r.decided_by.name}</Item>}
          {r.decided_at && <Item label={t('leave.decide.decidedAt')}>{time(r.decided_at)}</Item>}
          {r.note && <Item label={t('leave.request.note')} wide>{r.note}</Item>}
          {r.decision_reason && <Item label={t(r.status === 'refused' ? 'leave.decide.refuse.reason' : 'leave.decide.escalationNote')} wide>{r.decision_reason}</Item>}
        </dl>
        {a && a.unpaid > 0 && (
          <Alert tone="warning">
            <p className="font-semibold">{t('leave.decide.split', { keep: a.keep, kind, unpaid: a.unpaid })}</p>
            <p>{t('leave.decide.short', { unpaid: t('leave.request.unpaid', { count: a.unpaid }) })}</p>
          </Alert>
        )}
        {a && a.remaining_after === null && (r.kind === 'annual' || r.kind === 'casual') && <Alert tone="warning">{t('leave.noHireDate')}</Alert>}
        {!!a?.blocked.length && (
          <Alert tone="danger">
            <p>{t('leave.decide.blocked')}</p>
            <ul className="flex flex-wrap gap-x-4">{a.blocked.map((d) => <li key={d}><bdi dir="ltr">{d}</bdi></li>)}</ul>
          </Alert>
        )}
      </DialogBody>
      {!!actions.length && (
        <DialogFooter>
          {actions.map((action) =>
            action === 'approve' ? (
              <Button key={action} type="button" disabled={busy} onClick={approve}>{t('leave.decide.approve.action')}</Button>
            ) : (
              <Button key={action} type="button" variant="outline" disabled={busy} onClick={() => setAsking(action)}>{t(`leave.decide.${action}.action`)}</Button>
            ),
          )}
        </DialogFooter>
      )}
      {(['refuse', 'escalate'] as const).filter((x) => actions.includes(x)).map((action) => (
        <FormDialog
          key={action}
          title={t(`leave.decide.${action}.title`)}
          open={asking === action}
          onOpenChange={(o) => setAsking(o ? action : undefined)}
          init={{ reason: '' }}
          reloadKey={r.version}
          validate={(f): Record<string, string> => (action === 'refuse' && !f.reason.trim() ? { reason: t('leave.decide.refuse.required') } : {})}
          submit={(f) => decide(action, f.reason.trim())}
          onDone={() => done(action)}
          shown={['reason']}
          footer={`leave.decide.${action}.submit`}
          destructive={action === 'refuse'}
          clearErrOnEdit
        >
          {(f) => (
            <Field name="reason" id={`${action}-reason`} label={t(`leave.decide.${action}.reason`)} errs={f.errs}>
              {(props) => <Textarea {...props} maxLength={1000} value={f.values.reason} onChange={(e) => f.set('reason', e.target.value)} />}
            </Field>
          )}
        </FormDialog>
      ))}
    </>
  )
}

function Item({ label, wide, children }: { label: string; wide?: boolean; children: React.ReactNode }) {
  return (
    <div className={cn('min-w-0', wide && 'sm:col-span-2')}>
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium [overflow-wrap:anywhere]">{children}</dd>
    </div>
  )
}

/** ltr: in Arabic text a leading minus would otherwise land after the digits. */
const Count = ({ n }: { n: number | null }) => <bdi dir="ltr" className={cn(n !== null && n < 0 && 'text-destructive')}>{n ?? '—'}</bdi>

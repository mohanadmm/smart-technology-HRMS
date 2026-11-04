import { useRef, useState } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { SearchInput } from '@/components/common/Filters'
import { type Controlled, ErrorBanner, Field, localName, type Row, SelectField, TextField, toErrs, useForm } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/input'
import { useDebounced } from '@/hooks/useDebounced'
import { ApiError } from '@/lib/api'
import { useApi, useList, useSave } from '@/lib/query'

const KINDS = ['annual', 'casual', 'sick', 'unpaid'] as const

/** `GET /leave/requests/preview`: the server's count and split; the dialog shows these numbers and computes none. */
type Preview = { days: number; unpaid: number; remaining_after: number | null }

const FIELDS = ['employee_id', 'kind', 'from_date', 'to_date', 'note']

/** Filing a leave request. `employee` fixes and hides the employee (the employee page); without it a picker searches the employees in reach. */
export function RequestDialog({ open, onOpenChange, employee }: Required<Controlled> & { employee?: Row }) {
  const { t } = useTranslation()
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t('leave.request.title')}</DialogTitle>
        </DialogHeader>
        <RequestForm employee={employee} onDone={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function RequestForm({ employee, onDone }: { employee?: Row; onDone: () => void }) {
  const { t } = useTranslation()
  const send = useSave()
  const { form, set } = useForm({ employee_id: employee?.id ?? '', kind: 'annual', from_date: '', to_date: '', note: '' })
  const [err, setErr] = useState<unknown>()
  const busy = useRef(false)
  const [, rerender] = useState(0)

  const complete = form.employee_id && form.kind && form.from_date && form.to_date
  const query = useDebounced(complete ? new URLSearchParams({ employee_id: form.employee_id, kind: form.kind, from_date: form.from_date, to_date: form.to_date }).toString() : null, 300)
  const preview = useApi<Preview>(query && `/leave/requests/preview?${query}`)
  const p = complete && query ? preview.data : undefined
  const refused = !!complete && preview.err instanceof ApiError && preview.err.status === 422

  async function submit() {
    if (busy.current) return
    busy.current = true
    rerender((n) => n + 1)
    setErr(undefined)
    try {
      const body = { employee_id: form.employee_id, kind: form.kind, from_date: form.from_date, to_date: form.to_date, note: form.note.trim() || null }
      await send('/leave/requests', { body }, ['/leave/requests', `/employees/${form.employee_id}/leave`])
      toast.success(t('leave.request.sent'))
      onDone()
    } catch (e) {
      setErr(e)
    } finally {
      busy.current = false
      rerender((n) => n + 1)
    }
  }

  const f = { values: form, set, errs: toErrs(err) }
  return (
    <>
      <DialogBody className="grid gap-4">
        <ErrorBanner err={err} shown={FIELDS} />
        {!employee && <EmployeePicker {...f} />}
        <SelectField name="kind" label={t('leave.request.kind')} options={KINDS.map((k) => [k, t(`attendance.leaveName.${k}`)])} {...f} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField name="from_date" label={t('leave.request.from')} type="date" {...f} />
          <TextField name="to_date" label={t('leave.request.to')} type="date" {...f} />
        </div>
        {complete && preview.err ? <ErrorBanner err={preview.err} shown={[]} /> : null}
        {p && (
          <p role="status" className="text-sm">
            {t('leave.request.days', { count: p.days })} {t('leave.request.excluded')}
            {/* ltr: in Arabic text a leading minus would otherwise land after the digits */}
            {p.remaining_after !== null && <Trans i18nKey="leave.request.after" components={{ n: <bdi dir="ltr">{String(p.remaining_after)}</bdi> }} />}
          </p>
        )}
        {p && p.unpaid > 0 && <Alert tone="warning">{t('leave.request.unpaid', { count: p.unpaid })}</Alert>}
        <Field name="note" id="note" label={t('leave.request.note')} errs={f.errs}>
          {(props) => <Textarea {...props} value={form.note} onChange={(e) => set('note', e.target.value)} />}
        </Field>
      </DialogBody>
      <DialogFooter>
        <Button type="button" disabled={busy.current || refused} onClick={submit}>
          {t('leave.request.submit')}
        </Button>
      </DialogFooter>
    </>
  )
}

/** A search box over `GET /employees` (the server keeps a manager to the employees in reach) and a select of the matches; day laborers have no leave, so they are not offered. */
function EmployeePicker({ values, set, errs }: { values: Row; set: (k: string, v: string) => void; errs: Record<string, string> }) {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const search = useDebounced(q, 250)
  const { rows } = useList(`/employees?${new URLSearchParams({ per_page: '20', ...(search && { search }) })}`)
  // the chosen employee stays an option when a later search no longer matches it
  const [picked, setPicked] = useState<Row>()
  const matches = rows.filter((r) => r.current_type !== 'day_laborer')
  const options = picked && !matches.some((r) => r.id === picked.id) ? [picked, ...matches] : matches
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

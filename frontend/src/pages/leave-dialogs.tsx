import { useState } from 'react'
import { Trans, useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert } from '@/components/common/Alert'
import { SearchInput } from '@/components/common/Filters'
import { type Controlled, ErrorBanner, Field, type FormApi, FormDialog, localName, type Row, SelectField, TextField } from '@/components/form'
import { Textarea } from '@/components/ui/input'
import { useDebounced } from '@/hooks/useDebounced'
import { useApi, useList, useSave } from '@/lib/query'

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

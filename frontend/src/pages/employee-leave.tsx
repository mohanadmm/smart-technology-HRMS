import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { cairoToday, ErrorBanner, Field, FormDialog, type Row, TextField } from '@/components/form'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Textarea } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/misc'
import { ApiError, type ApiErrorDetail } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useApi, useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

/** `GET /employees/{id}/leave`: every number is the server's (docs/calculations.md §7); `balance` is null for a day laborer. */
type Balance = { entitlement: number | null; casual_cap: number | null; used: number; casual_used: number; remaining: number | null; sick_days: number; unpaid_days: number }
type Adjustment = { entitlement: number | null; opening_used: number; opening_casual_used: number; reason: string | null; version: number }
type EmployeeLeave = { balance: Balance | null; adjustment: Adjustment | null }

/** The year's leave balance; the year selector offers the current Cairo year and one either side. */
export function Leave({ emp }: { emp: Row }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const thisYear = Number(cairoToday().slice(0, 4))
  const [year, setYear] = useState(thisYear)
  // fresh + ready: attendance edits change the balance but refresh only attendance queries, so a cached copy may be stale
  const { data, err, ready } = useApi<EmployeeLeave>(`/employees/${emp.id}/leave?year=${year}`, { fresh: true })
  const b = data?.balance
  const years = [thisYear - 1, thisYear, thisYear + 1].map(String)
  const stats: [string, React.ReactNode][] = b
    ? [
        [t('leave.entitlement'), b.entitlement ?? '—'],
        [t('leave.used'), b.used],
        [t('leave.casualUsed'), b.casual_cap === null ? b.casual_used : <span className={cn(b.casual_used > b.casual_cap && 'text-destructive')}>{t('leave.ofCap', { used: b.casual_used, cap: b.casual_cap })}</span>],
        [t('leave.sickDays'), b.sick_days],
        [t('leave.unpaidDays'), b.unpaid_days],
      ]
    : []

  return (
    <Card>
      <CardHeader className="flex-wrap items-center">
        <CardTitle>{t('leave.balance')}</CardTitle>
        <div className="flex flex-wrap items-center gap-2">
          <SegmentedControl
            label={t('leave.year')}
            value={String(year)}
            onChange={(y) => setYear(Number(y))}
            options={years.map((y) => ({ value: y, label: y, content: <span aria-hidden className="px-1.5">{y}</span> }))}
          />
          {can('leave.adjust') && ready && b && <AdjustDialog empId={emp.id} year={year} adjustment={data.adjustment} />}
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <ErrorBanner err={err} shown={[]} />
        {!(ready && data) ? (
          !err && <Skeleton className="h-48" />
        ) : (
          b && (
            <>
              {b.entitlement === null && <Alert tone="warning">{t('leave.noHireDate')}</Alert>}
              {/* one hero, the rest one hairline-divided strip (rules 1, 10): rows on a phone, five columns from md, so no tile sits alone */}
              <div role="group" aria-label={t('leave.balance')} className="rounded-xl border">
                <div role="group" className="p-4">
                  <div className="text-xs text-muted-foreground">{t('leave.remaining')}</div>
                  {/* ltr: in Arabic text a leading minus would otherwise land after the digits */}
                  <div className="mt-1 text-kpi whitespace-nowrap">{b.remaining === null ? '—' : <bdi dir="ltr" className={cn(b.remaining < 0 && 'text-destructive')}>{b.remaining}</bdi>}</div>
                  {data.adjustment?.reason && <p className="mt-1 text-xs text-muted-foreground">{t('leave.adjusted', { reason: data.adjustment.reason })}</p>}
                </div>
                <div className="grid border-t max-md:divide-y md:grid-cols-5 md:divide-x">
                  {stats.map(([label, value]) => (
                    <div role="group" key={label} className="flex items-baseline justify-between gap-4 px-4 py-2.5 md:flex-col md:items-start md:py-3">
                      <div className="text-xs text-muted-foreground">{label}</div>
                      <div className="font-semibold whitespace-nowrap md:text-lg md:leading-tight">{value}</div>
                    </div>
                  ))}
                </div>
              </div>
            </>
          )
        )}
      </CardContent>
    </Card>
  )
}

const FIELDS = ['entitlement', 'opening_used', 'opening_casual_used', 'reason']
// a whole number goes as a number; anything else goes as typed, so the server's 422 names the field
const whole = (v: string) => (/^\d+$/.test(v.trim()) ? Number(v) : v.trim())

/** HR's adjustment of one year: an entitlement override (blank = computed), the days used before the system, and why. */
function AdjustDialog({ empId, year, adjustment: a }: { empId: string; year: number; adjustment: Adjustment | null }) {
  const { t } = useTranslation()
  const send = useSave()

  function submit(f: Row) {
    const entitlement = f.entitlement.trim() === '' ? null : whole(f.entitlement)
    const opening_used = f.opening_used.trim() === '' ? 0 : whole(f.opening_used)
    const opening_casual_used = f.opening_casual_used.trim() === '' ? 0 : whole(f.opening_casual_used)
    const reason = f.reason.trim() || null
    const refused: ApiErrorDetail[] = []
    if (!reason && (entitlement !== null || opening_used !== 0 || opening_casual_used !== 0)) refused.push({ field: 'reason', code: 'required', message: t('leave.adjust.reasonRequired') })
    if (typeof opening_used === 'number' && typeof opening_casual_used === 'number' && opening_casual_used > opening_used)
      refused.push({ field: 'opening_casual_used', code: 'lte', message: t('leave.adjust.casualOverUsed') })
    // shaped as the server's 422, so the dialog shows each message under its field
    if (refused.length) return Promise.reject(new ApiError('validation_failed', '', 422, refused))
    // f.version is the one the dialog opened with: a refetch after a 409 must not turn the next Save into an overwrite
    return send(`/employees/${empId}/leave/${year}`, { method: 'PUT', body: { entitlement, opening_used, opening_casual_used, reason, version: f.version } }, ['/employees', '/leave'])
  }

  const ns = 'adjust-'
  return (
    <FormDialog
      title={t('leave.adjust.title', { year })}
      description={t('leave.adjust.description')}
      trigger={<Button type="button" variant="outline">{t('leave.adjust.open')}</Button>}
      init={{
        entitlement: a?.entitlement == null ? '' : String(a.entitlement),
        opening_used: a?.opening_used ? String(a.opening_used) : '',
        opening_casual_used: a?.opening_casual_used ? String(a.opening_casual_used) : '',
        reason: a?.reason ?? '',
        version: a?.version ?? null,
      }}
      submit={submit}
      onDone={() => {}}
      shown={FIELDS}
    >
      {({ values, set, errs }) => (
        <>
          <div className="grid gap-1.5">
            <TextField name="entitlement" ns={ns} label={t('leave.adjust.entitlement')} inputMode="numeric" enterKeyHint="next" values={values} set={set} errs={errs} />
            <p className="text-[13px] text-muted-foreground">{t('leave.adjust.entitlementHint')}</p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField name="opening_used" ns={ns} label={t('leave.adjust.openingUsed')} inputMode="numeric" enterKeyHint="next" placeholder="0" values={values} set={set} errs={errs} />
            <TextField name="opening_casual_used" ns={ns} label={t('leave.adjust.openingCasualUsed')} inputMode="numeric" enterKeyHint="next" placeholder="0" values={values} set={set} errs={errs} />
          </div>
          <Field name="reason" id={`${ns}reason`} label={t('leave.adjust.reason')} errs={errs}>
            {(p) => <Textarea {...p} value={values.reason} onChange={(e) => set('reason', e.target.value)} />}
          </Field>
        </>
      )}
    </FormDialog>
  )
}

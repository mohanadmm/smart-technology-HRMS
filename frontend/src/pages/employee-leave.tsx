import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '@/components/common/Alert'
import { StatRow } from '@/components/common/StatRow'
import { cairoToday, ErrorBanner, type Row } from '@/components/form'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/misc'
import { useApi } from '@/lib/query'
import { cn } from '@/lib/utils'

/** `GET /employees/{id}/leave`: every number is the server's (docs/calculations.md §7); `balance` is null for a day laborer. */
type Balance = { entitlement: number | null; casual_cap: number | null; used: number; casual_used: number; remaining: number | null; sick_days: number; unpaid_days: number }
type EmployeeLeave = { balance: Balance | null }

/** The year's leave balance; the year selector offers the current Cairo year and one either side. */
export function Leave({ emp }: { emp: Row }) {
  const { t } = useTranslation()
  const thisYear = Number(cairoToday().slice(0, 4))
  const [year, setYear] = useState(thisYear)
  // fresh + ready: attendance edits change the balance but refresh only attendance queries, so a cached copy may be stale
  const { data, err, ready } = useApi<EmployeeLeave>(`/employees/${emp.id}/leave?year=${year}`, { fresh: true })
  const b = data?.balance
  const years = [thisYear - 1, thisYear, thisYear + 1].map(String)

  return (
    <Card>
      <CardHeader className="flex-wrap items-center">
        <CardTitle>{t('leave.balance')}</CardTitle>
        <SegmentedControl
          label={t('leave.year')}
          value={String(year)}
          onChange={(y) => setYear(Number(y))}
          options={years.map((y) => ({ value: y, label: y, content: <span aria-hidden className="px-1.5">{y}</span> }))}
        />
      </CardHeader>
      <CardContent className="space-y-4">
        <ErrorBanner err={err} shown={[]} />
        {!(ready && data) ? (
          !err && <Skeleton className="h-48" />
        ) : (
          b && (
            <>
              {b.entitlement === null && <Alert tone="warning">{t('leave.noHireDate')}</Alert>}
              <StatRow
                label={t('leave.balance')}
                hero={{
                  label: t('leave.remaining'),
                  // ltr: in Arabic text a leading minus would otherwise land after the digits
                  value: b.remaining === null ? '—' : <bdi dir="ltr" className={cn(b.remaining < 0 && 'text-destructive')}>{b.remaining}</bdi>,
                }}
                stats={[
                  { label: t('leave.entitlement'), value: b.entitlement ?? '—' },
                  { label: t('leave.used'), value: b.used },
                  {
                    label: t('leave.casualUsed'),
                    value: b.casual_cap === null ? b.casual_used : <span className={cn(b.casual_used > b.casual_cap && 'text-destructive')}>{t('leave.ofCap', { used: b.casual_used, cap: b.casual_cap })}</span>,
                  },
                  { label: t('leave.sickDays'), value: b.sick_days },
                  { label: t('leave.unpaidDays'), value: b.unpaid_days },
                ]}
              />
            </>
          )
        )}
      </CardContent>
    </Card>
  )
}

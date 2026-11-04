import { CalendarDays } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { DataList } from '@/components/common/DataList'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { cairoToday, localName, ltrDates, type Row } from '@/components/form'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useDebounced } from '@/hooks/useDebounced'
import { useList } from '@/lib/query'
import { cn } from '@/lib/utils'

// the monthly types: a day laborer has no leave balance (the server refuses it), so it is not offered
const MONTHLY_TYPES = ['office', 'engineer', 'permanent_worker']
const PAGE = 20

/** `GET /leave/balances`: one row per monthly employee, every number the server's (docs/calculations.md §7). */
export function LeavePage() {
  const { t, i18n } = useTranslation()
  const today = cairoToday()
  const thisYear = Number(today.slice(0, 4))
  const [year, setYear] = useState(String(thisYear))
  const [type, setType] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const search = useDebounced(q, 250)
  const qs = new URLSearchParams({ year, page: String(page), per_page: String(PAGE), ...(type && { type }), ...(search && { q: search }) })
  const { rows, total, err, loading } = useList(`/leave/balances?${qs}`)
  const filter = (set: (v: string) => void) => (v: string) => {
    set(v)
    setPage(1)
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('leave.page')} description={t('leave.period', { year })} />
      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b p-4">
          <SearchInput value={q} onChange={filter(setQ)} placeholder={t('leave.search')} className="sm:w-full sm:max-w-md" />
          <FilterSelect value={type} onChange={filter(setType)} label={t('employee.type')} allLabel={t('employee.allTypes')} options={MONTHLY_TYPES.map((v) => [v, t(`enum.type.${v}`)])} />
          <SegmentedControl
            label={t('leave.year')}
            value={year}
            onChange={filter(setYear)}
            className="w-max"
            options={[thisYear - 1, thisYear, thisYear + 1].map(String).map((y) => ({ value: y, label: y, content: <span aria-hidden className="px-1.5">{y}</span> }))}
          />
          {(q || type) && (
            <Button variant="ghost" size="sm" onClick={() => { setQ(''); setType(''); setPage(1) }} className="lg:ms-auto">
              {t('common.clearFilters')}
            </Button>
          )}
        </div>
        <DataList<Row>
          label={t('leave.list')}
          rows={rows}
          rowKey={(r) => r.id}
          to={(r) => `/employees/${r.id}?tab=leave&year=${year}`}
          loading={loading}
          error={err}
          empty={{ icon: CalendarDays, title: t('common.noResults') }}
          page={{ page, pageSize: PAGE, total, onPageChange: setPage }}
          title={{
            header: t('employee.employee'),
            cell: (r) => (
              <>
                {localName(r, i18n.language)}
                {/* the termination day is still worked, so a leaver is one whose date is past */}
                {r.termination_date && <span className="block text-xs font-normal text-muted-foreground">{ltrDates(t(r.termination_date < today ? 'leave.leftOn' : 'employee.leavesOn', { date: r.termination_date }))}</span>}
              </>
            ),
          }}
          fields={[
            { header: t('employee.code'), tableFrom: 'xl', cell: (r) => <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">{r.code ?? '—'}</bdi> },
            { header: t('leave.entitlementShort'), numeric: true, cell: (r) => r.entitlement ?? '—' },
            { header: t('leave.used'), numeric: true, cell: (r) => r.used },
            { header: t('leave.casualUsed'), numeric: true, cell: (r) => (r.casual_cap === null ? r.casual_used : <span className={cn(r.casual_used > r.casual_cap && 'text-destructive')}>{t('leave.ofCap', { used: r.casual_used, cap: r.casual_cap })}</span>) },
            { header: t('leave.sickShort'), numeric: true, cell: (r) => r.sick_days },
            { header: t('leave.unpaidShort'), numeric: true, cell: (r) => r.unpaid_days },
          ]}
          status={{ header: t('employee.type'), tableFrom: 'xl', cell: (r) => <Badge variant="info">{t(`enum.type.${r.type}`)}</Badge> }}
          // ltr: in Arabic text a leading minus would otherwise land after the digits
          hero={{ header: t('leave.remaining'), cell: (r) => <bdi dir="ltr" className={cn(r.remaining < 0 && 'text-destructive')}>{r.remaining ?? '—'}</bdi> }}
        />
      </Card>
    </div>
  )
}

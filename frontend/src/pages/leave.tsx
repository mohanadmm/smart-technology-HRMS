import { CalendarDays } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { EmptyState } from '@/components/common/EmptyState'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { cairoToday, ErrorBanner, localName, type Row } from '@/components/form'
import { SegmentedControl } from '@/components/layout/SegmentedControl'
import { TableSkeleton } from '@/components/layout/PageSkeleton'
import { Pagination } from '@/components/tables/Pagination'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useDebounced } from '@/hooks/useDebounced'
import { useList } from '@/lib/query'
import { cn } from '@/lib/utils'

const TYPES = ['office', 'engineer', 'permanent_worker'] // day laborers have no balance
const PAGE = 20

// DataList's table-to-card switch (rule 19), laid out for a balance: [name | remaining] / [type | remaining] / [label … value]*.
// DataList has no row click, so the rows are drawn here.
const CELL = 'px-4 py-3 align-middle sm:whitespace-nowrap sm:first:ps-5 sm:last:pe-5 max-sm:p-0'
const CARD = 'max-sm:grid max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:items-center max-sm:gap-x-3 max-sm:gap-y-2 max-sm:px-4 max-sm:py-3'
const LINE = 'max-sm:col-span-2 max-sm:flex max-sm:items-baseline max-sm:justify-between max-sm:gap-4'

/** `GET /leave/balances`: one row per monthly employee, every number the server's (docs/calculations.md §7). */
export function LeavePage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const thisYear = Number(cairoToday().slice(0, 4))
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
  const open = (id: string) => navigate(`/employees/${id}?tab=leave`)

  // the card label is the column header, hidden from the table layout and from screen readers (the thead names it)
  const label = (text: string) => <span aria-hidden="true" className="shrink-0 text-muted-foreground sm:hidden">{text}</span>
  const numbers: [string, (r: Row) => React.ReactNode, string?][] = [
    [t('leave.entitlement'), (r) => r.entitlement ?? '—'],
    [t('leave.used'), (r) => r.used],
    [t('leave.casualUsed'), (r) => (r.casual_cap === null ? r.casual_used : <span className={cn(r.casual_used > r.casual_cap && 'text-destructive')}>{t('leave.ofCap', { used: r.casual_used, cap: r.casual_cap })}</span>)],
    [t('leave.sickShort'), (r) => r.sick_days],
    [t('leave.unpaidShort'), (r) => r.unpaid_days],
  ]

  return (
    <div className="space-y-6">
      <PageHeader title={t('leave.page')} description={t('leave.period', { year })} />
      <Card className="overflow-hidden">
        <div className="space-y-3 border-b p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
            <SearchInput value={q} onChange={filter(setQ)} placeholder={t('leave.search')} className="sm:w-full sm:max-w-md" />
            <FilterSelect value={type} onChange={filter(setType)} label={t('employee.type')} allLabel={t('employee.allTypes')} options={TYPES.map((v) => [v, t(`enum.type.${v}`)])} />
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
          <ErrorBanner err={err} shown={[]} />
        </div>

        {loading ? (
          <TableSkeleton rows={8} />
        ) : !rows.length ? (
          !err && <EmptyState icon={CalendarDays} title={t('common.noResults')} />
        ) : (
          <Table role="table" aria-label={t('leave.list')} className="max-sm:block">
            <TableHeader role="rowgroup" className="max-sm:sr-only">
              <TableRow role="row">
                <TableHead role="columnheader" className="sm:max-lg:hidden">{t('employee.code')}</TableHead>
                <TableHead role="columnheader">{t('employee.employee')}</TableHead>
                <TableHead role="columnheader" className="sm:max-md:hidden">{t('employee.type')}</TableHead>
                {numbers.map(([h]) => (
                  <TableHead role="columnheader" key={h} className="text-end">{h}</TableHead>
                ))}
                <TableHead role="columnheader" className="text-end text-foreground">{t('leave.remaining')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody role="rowgroup" className="max-sm:block">
              {rows.map((r) => (
                <TableRow
                  role="row"
                  key={r.id}
                  tabIndex={0}
                  className={cn(CARD, 'cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring')}
                  onClick={() => open(r.id)}
                  onKeyDown={(ev) => ev.key === 'Enter' && open(r.id)}
                >
                  <td role="cell" className={cn(CELL, LINE, 'sm:max-lg:hidden')}>
                    {label(t('employee.code'))}
                    <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">{r.code ?? '—'}</bdi>
                  </td>
                  <td role="cell" className={cn(CELL, 'font-medium max-sm:col-start-1 max-sm:row-start-1')}>
                    {localName(r, i18n.language)}
                  </td>
                  <td role="cell" className={cn(CELL, 'sm:max-md:hidden max-sm:col-start-1 max-sm:row-start-2')}>
                    <Badge variant="info">{t(`enum.type.${r.type}`)}</Badge>
                  </td>
                  {numbers.map(([h, cell]) => (
                    <td role="cell" key={h} className={cn(CELL, LINE, 'text-end whitespace-nowrap')}>
                      {label(h)}
                      {cell(r)}
                    </td>
                  ))}
                  <td role="cell" className={cn(CELL, 'text-end whitespace-nowrap max-sm:col-start-2 max-sm:row-span-2 max-sm:row-start-1')}>
                    <span aria-hidden="true" className="block text-xs text-muted-foreground sm:hidden">{t('leave.remaining')}</span>
                    {/* ltr: in Arabic text a leading minus would otherwise land after the digits */}
                    <bdi dir="ltr" className={cn('text-base font-semibold max-sm:text-2xl', r.remaining < 0 && 'text-destructive')}>{r.remaining ?? '—'}</bdi>
                  </td>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
        {total > PAGE && <Pagination page={page} pageSize={PAGE} total={total} onPageChange={setPage} />}
      </Card>
    </div>
  )
}

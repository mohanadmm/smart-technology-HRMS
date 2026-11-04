import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { LeavePage } from './leave'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-04T10:00:00Z'))
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const row = { id: 'e1', code: 'E-101', name_ar: 'سامي فؤاد', name_en: 'Sami Fouad', type: 'engineer', hire_date: '2020-03-01', entitlement: 21, casual_cap: 6, used: 10, casual_used: 3, remaining: 11, casual_remaining: 3, sick_days: 4, unpaid_days: 1 }
const BALANCES = 'GET /api/v1/leave/balances'
const balances = (rows: object[] = [row]) => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } })

function Opened() {
  const l = useLocation()
  return <p>{`opened ${l.pathname}${l.search}`}</p>
}
const page = (
  <Routes>
    <Route path="leave" element={<LeavePage />} />
    <Route path="employees/:id" element={<Opened />} />
  </Routes>
)
const render = () => renderWithAuth(page, ['leave.view.all'], { path: '/leave' })
const lastQuery = () => new URL(String(vi.mocked(fetch).mock.calls.at(-1)![0]), 'http://x').searchParams
const cellOf = (r: HTMLElement, header: string) => {
  const i = within(screen.getAllByRole('row')[0]).getAllByRole('columnheader').findIndex((h) => h.textContent === header)
  return within(r).getAllByRole('cell')[i]
}

test('each row shows the balance the server computed', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(r).toHaveTextContent('E-101')
  expect(r).toHaveTextContent('مهندس')
  expect(cellOf(r, 'الرصيد السنوي')).toHaveTextContent('21')
  expect(cellOf(r, 'المستخدم')).toHaveTextContent('10')
  expect(cellOf(r, 'العارضة المستخدمة')).toHaveTextContent('3 من 6')
  expect(cellOf(r, 'مرضي')).toHaveTextContent('4')
  expect(cellOf(r, 'بدون أجر')).toHaveTextContent('1')
  expect(cellOf(r, 'الرصيد المتبقي')).toHaveTextContent('11')
  expect(within(cellOf(r, 'الرصيد المتبقي')).getByText('11')).not.toHaveClass('text-destructive')
  expect(lastQuery().get('year')).toBe('2026')
})

test('an overdrawn balance shows in the destructive colour', async () => {
  mockFetch({ [BALANCES]: () => balances([{ ...row, used: 24, remaining: -3 }]) })
  render()
  const r = (await screen.findByText('سامي فؤاد')).closest('tr')!
  expect(within(cellOf(r, 'الرصيد المتبقي')).getByText('-3')).toHaveClass('text-destructive')
})

test('the year and type filters reach the request', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  await screen.findByText('سامي فؤاد')
  await user.click(screen.getByRole('button', { name: '2025' }))
  await waitFor(() => expect(lastQuery().get('year')).toBe('2025'))
  await user.selectOptions(screen.getByRole('combobox', { name: 'نوع الموظف' }), 'permanent_worker')
  await waitFor(() => expect(lastQuery().get('type')).toBe('permanent_worker'))
  expect(lastQuery().get('year')).toBe('2025')
  // day laborers have no balance, so they are not a choice
  expect(within(screen.getByRole('combobox', { name: 'نوع الموظف' })).queryByRole('option', { name: 'عامل باليومية' })).toBeNull()
})

test('the search waits for a pause in typing', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  await screen.findByText('سامي فؤاد')
  await user.type(screen.getByRole('textbox'), 'سامي')
  await waitFor(() => expect(lastQuery().get('q')).toBe('سامي'))
  expect(vi.mocked(fetch).mock.calls.map(([u]) => new URL(String(u), 'http://x').searchParams.get('q')).filter(Boolean)).toEqual(['سامي'])
})

test('a row opens the employee on the leave tab through a real link', async () => {
  mockFetch({ [BALANCES]: () => balances() })
  const user = userEvent.setup()
  render()
  const link = await screen.findByRole('link', { name: 'سامي فؤاد' })
  expect(link).toHaveAttribute('href', '/employees/e1?tab=leave')
  await user.click(link)
  expect(await screen.findByText('opened /employees/e1?tab=leave')).toBeInTheDocument()
})

test('no rows shows the empty state', async () => {
  mockFetch({ [BALANCES]: () => balances([]) })
  render()
  expect(await screen.findByText('لا توجد نتائج')).toBeInTheDocument()
})

import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import ar from '@/locales/ar.json'
import en from '@/locales/en.json'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { Leave } from './employee-leave'
import { EmployeeFormPage } from './employees'

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] }) // only the clock: user-event keeps its real timers
  vi.setSystemTime(new Date('2026-11-04T10:00:00Z'))
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const emp = { id: 'e1', name_ar: 'سامي فؤاد', current_type: 'office', type_changes: [], pay_changes: [], documents: [], current_manager: null, manager_assignments: [] }
const balance = { entitlement: 21, casual_cap: 6, used: 10, casual_used: 3, remaining: 11, casual_remaining: 3, sick_days: 4, unpaid_days: 1 }
const leave = (b: object | null = balance) => json(200, { data: { employee_id: 'e1', year: 2026, type: 'office', hire_date: '2020-03-01', balance: b, adjustment: null } })
const LEAVE = 'GET /api/v1/employees/e1/leave'
const VIEW = ['employee.view.all', 'leave.view.all']

// a stat tile is the group around its label
const tile = (label: string) => screen.getByText(label).closest('[role="group"]') as HTMLElement
const remainingTile = async () => (await screen.findByText('الرصيد المتبقي')).closest('[role="group"]') as HTMLElement

test('the card shows the balance the server computed', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  expect(await remainingTile()).toHaveTextContent('11')
  expect(tile('الرصيد السنوي')).toHaveTextContent('21')
  expect(tile('المستخدم')).toHaveTextContent('10')
  expect(tile('العارضة المستخدمة')).toHaveTextContent('3 من 6')
  expect(tile('أيام الإجازة المرضية')).toHaveTextContent('4')
  expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/api/v1/employees/e1/leave?year=2026')
})

test('an overdrawn balance shows in the destructive colour', async () => {
  mockFetch({ [LEAVE]: () => leave({ ...balance, used: 24, remaining: -3 }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  const remaining = await remainingTile()
  expect(within(remaining).getByText('-3')).toHaveClass('text-destructive')
})

test('a balance that is not overdrawn is not coloured', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  const remaining = await remainingTile()
  expect(within(remaining).getByText('11')).not.toHaveClass('text-destructive')
})

test('without a hire date the card asks for one', async () => {
  mockFetch({ [LEAVE]: () => leave({ ...balance, entitlement: null, casual_cap: null, remaining: null, casual_remaining: null }) })
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  expect(await screen.findByText(/سجّل تاريخ التعيين/)).toBeInTheDocument()
  expect(tile('الرصيد المتبقي')).toHaveTextContent('—')
  expect(tile('العارضة المستخدمة')).toHaveTextContent('3')
})

test('the year selector loads the previous and next year', async () => {
  mockFetch({ [LEAVE]: () => leave() })
  const user = userEvent.setup()
  renderWithAuth(<Leave emp={emp} />, VIEW, { router: false })
  await remainingTile()
  expect(screen.getByRole('button', { name: '2026' })).toHaveAttribute('aria-pressed', 'true')
  await user.click(screen.getByRole('button', { name: '2025' }))
  await user.click(screen.getByRole('button', { name: '2027' }))
  expect(vi.mocked(fetch).mock.calls.map(([u]) => u)).toEqual(['/api/v1/employees/e1/leave?year=2026', '/api/v1/employees/e1/leave?year=2025', '/api/v1/employees/e1/leave?year=2027'])
})

const detail = (
  <MemoryRouter initialEntries={['/employees/e1?tab=leave']}>
    <Routes><Route path="employees/:id" element={<EmployeeFormPage />} /></Routes>
  </MemoryRouter>
)

const page = (type: string) => mockFetch({
  'GET /api/v1/employees/e1': () => json(200, { data: { ...emp, current_type: type, current_site: null, site_assignments: [] } }),
  'GET /api/v1/residences': () => json(200, { data: [], meta: { pagination } }),
  [LEAVE]: () => leave(),
})

test('the employee page opens the leave tab', async () => {
  page('office')
  renderWithAuth(detail, VIEW, { router: false })
  expect(await screen.findByRole('tab', { name: 'الإجازات' })).toHaveAttribute('aria-selected', 'true')
  expect(await remainingTile()).toHaveTextContent('11')
})

test('a day laborer has no leave tab', async () => {
  page('day_laborer')
  renderWithAuth(detail, VIEW, { router: false })
  expect(await screen.findByRole('tab', { name: 'البيانات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'الإجازات' })).toBeNull()
})

test('no leave tab without leave.view', async () => {
  page('engineer')
  renderWithAuth(detail, ['employee.view.all'], { router: false })
  expect(await screen.findByRole('tab', { name: 'البيانات' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'الإجازات' })).toBeNull()
})

const keys = (o: object, p = ''): string[] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? keys(v, `${p}${k}.`) : [`${p}${k}`]))

test('the leave names, permissions and setting label exist in both languages', () => {
  const added = [
    ...['annual', 'casual', 'unpaid'].flatMap((c) => [`attendance.leaveName.${c}`, `attendance.short.${c}`, `enum.leave_code.${c}`]),
    ...['view', 'request', 'decide', 'decide_escalated', 'adjust'].map((a) => `perm.leave.${a}`),
    'perm.resource.leave',
    'rule.sick_leave_pay_percent',
  ]
  expect(keys(ar)).toEqual(expect.arrayContaining(added))
  expect(keys(en)).toEqual(expect.arrayContaining(added))
})

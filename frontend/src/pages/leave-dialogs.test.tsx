import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
vi.mock('sonner', () => ({ toast: { success: vi.fn() }, Toaster: () => null }))
import { toast } from 'sonner'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { RequestDialog } from './leave-dialogs'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.clearAllMocks()
})

const PREVIEW = 'GET /api/v1/leave/requests/preview'
const balance = { entitlement: 21, casual_cap: 6, used: 19, casual_used: 3, remaining: 2, casual_remaining: 3, sick_days: 0, unpaid_days: 0 }
const preview = (p: object = {}) => () => json(200, { data: { dates: ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-15'], days: 4, balance, keep: 2, unpaid: 2, remaining_after: 0, ...p } })
const sami = { id: 'e1', code: 101, name_ar: 'سامي فؤاد', name_en: 'Sami Fouad', current_type: 'engineer' }
const user = userEvent.setup()

const dates = (from: string, to: string) => {
  fireEvent.change(screen.getByLabelText('من'), { target: { value: from } })
  fireEvent.change(screen.getByLabelText('إلى'), { target: { value: to } })
}
const previewQuery = () => new URL(String(vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.includes('/preview')).at(-1)), 'http://x').searchParams
const open = (onOpenChange = vi.fn()) => renderWithAuth(<RequestDialog open onOpenChange={onOpenChange} employee={sami} />, ['leave.request.all'])

test('the preview shows the counted days, the balance after, and the unpaid days as a dual', async () => {
  mockFetch({ [PREVIEW]: preview() })
  open()
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/^4 أيام محسوبة \(الجمعة والإجازات الرسمية غير محسوبة\)، الرصيد بعدها 0$/))
  expect(screen.getByText('يومان بدون أجر')).toBeInTheDocument()
  expect(Object.fromEntries(previewQuery())).toEqual({ employee_id: 'e1', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-15' })
})

test('an overdrawn balance after reads with its minus sign first', async () => {
  mockFetch({ [PREVIEW]: preview({ keep: 0, unpaid: 4, remaining_after: -3 }) })
  open()
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/الرصيد بعدها -3$/))
  expect(within(screen.getByRole('status')).getByText('-3')).toHaveAttribute('dir', 'ltr')
})

test('one unpaid day and eleven unpaid days take their Arabic forms', async () => {
  mockFetch({ [PREVIEW]: preview({ unpaid: 1 }) })
  const first = open()
  dates('2026-11-10', '2026-11-15')
  expect(await screen.findByText('يوم واحد بدون أجر')).toBeInTheDocument()
  first.unmount()
  mockFetch({ [PREVIEW]: preview({ days: 11, unpaid: 11 }) })
  open()
  dates('2026-11-01', '2026-11-14')
  expect(await screen.findByText('11 يومًا بدون أجر')).toBeInTheDocument()
})

test('without a balance the line drops the balance clause, and nothing unpaid shows no warning', async () => {
  mockFetch({ [PREVIEW]: preview({ balance: null, keep: 4, unpaid: 0, remaining_after: null }) })
  open()
  await user.selectOptions(screen.getByLabelText('نوع الإجازة'), 'sick')
  dates('2026-11-10', '2026-11-15')
  await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/^4 أيام محسوبة \(الجمعة والإجازات الرسمية غير محسوبة\)$/))
  expect(screen.queryByText(/الرصيد بعدها/)).toBeNull()
  expect(screen.queryByText(/بدون أجر/, { ignore: 'option, script, style' })).toBeNull() // the kind list itself has «إجازة بدون أجر»
  expect(previewQuery().get('kind')).toBe('sick')
})

test('a refused preview shows the reason in the dialog and disables sending', async () => {
  mockFetch({ [PREVIEW]: () => json(422, { error: { code: 'leave_overlap', message: 'يتداخل هذا الطلب مع طلب إجازة آخر.' } }) })
  open()
  dates('2026-11-10', '2026-11-15')
  expect(await screen.findByRole('alert')).toHaveTextContent('يتداخل هذا الطلب مع طلب إجازة آخر.')
  expect(screen.getByRole('button', { name: 'إرسال الطلب' })).toBeDisabled()
})

test('sending posts the request, says so, and closes', async () => {
  const post = vi.fn(() => json(201, { data: { id: 'r1', status: 'pending' } }))
  const onOpenChange = vi.fn()
  mockFetch({ [PREVIEW]: preview(), 'POST /api/v1/leave/requests': post })
  open(onOpenChange)
  dates('2026-11-10', '2026-11-15')
  await user.type(screen.getByLabelText('ملاحظة'), 'سفر')
  await screen.findByText(/4 أيام محسوبة/)
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false))
  expect(JSON.parse(String((post.mock.calls[0] as unknown as [string, RequestInit])[1].body))).toEqual({ employee_id: 'e1', kind: 'annual', from_date: '2026-11-10', to_date: '2026-11-15', note: 'سفر' })
  expect(toast.success).toHaveBeenCalledWith('أُرسل طلب الإجازة.')
})

test('a refused submit keeps the dialog open with the reason', async () => {
  const onOpenChange = vi.fn()
  mockFetch({ [PREVIEW]: preview(), 'POST /api/v1/leave/requests': () => json(422, { error: { code: 'leave_overlap', message: 'يتداخل هذا الطلب مع طلب إجازة آخر.' } }) })
  open(onOpenChange)
  dates('2026-11-10', '2026-11-15')
  await screen.findByText(/4 أيام محسوبة/)
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('يتداخل هذا الطلب')
  expect(onOpenChange).not.toHaveBeenCalled()
})

test('with the employee fixed there is no employee picker', async () => {
  mockFetch({})
  open()
  expect(screen.queryByLabelText('الموظف')).toBeNull()
})

test('the employee picker searches the employees in reach and leaves out day laborers', async () => {
  const worker = { id: 'e2', code: 202, name_ar: 'سعيد حسن', name_en: null, current_type: 'day_laborer' }
  const post = vi.fn(() => json(201, { data: { id: 'r1' } }))
  mockFetch({
    'GET /api/v1/employees': () => json(200, { data: [sami, worker], meta: { pagination: { ...pagination, total: 2 } } }),
    [PREVIEW]: preview(),
    'POST /api/v1/leave/requests': post,
  })
  renderWithAuth(<RequestDialog open onOpenChange={vi.fn()} />, ['leave.request.own', 'employee.view.own'])
  await user.type(screen.getByRole('textbox', { name: 'بحث بالاسم أو الرقم الوظيفي' }), 'سا')
  await waitFor(() => expect(vi.mocked(fetch).mock.calls.some(([u]) => new URL(String(u), 'http://x').searchParams.get('search') === 'سا')).toBe(true))
  const picker = screen.getByLabelText('الموظف')
  await waitFor(() => expect(within(picker).getByRole('option', { name: 'سامي فؤاد (101)' })).toBeInTheDocument())
  expect(within(picker).queryByRole('option', { name: /سعيد حسن/ })).toBeNull()
  await user.selectOptions(picker, 'e1')
  dates('2026-11-10', '2026-11-15')
  await screen.findByText(/4 أيام محسوبة/)
  expect(previewQuery().get('employee_id')).toBe('e1')
  await user.click(screen.getByRole('button', { name: 'إرسال الطلب' }))
  await waitFor(() => expect(post).toHaveBeenCalled())
})

test('nothing is previewed until employee, from and to are all set', async () => {
  mockFetch({ [PREVIEW]: preview() })
  renderWithAuth(<RequestDialog open onOpenChange={vi.fn()} />, ['leave.request.own'])
  dates('2026-11-10', '2026-11-15')
  await new Promise((r) => setTimeout(r, 400))
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('/preview'))).toBe(false)
})

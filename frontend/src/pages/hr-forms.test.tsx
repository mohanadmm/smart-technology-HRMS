import { act, fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import type { PdfExport } from '@/components/PdfPanel'
import { navAbilities } from '@/components/layout/AppLayout'
import { json, me, mockFetch, pagination, renderApp, renderWithAuth } from '@/test-utils'
import { IssuedFormsPage } from './hr-forms'

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const HR = ['hr_forms.view.all', 'hr_forms.generate.all', 'employee.view.all']
const LIST = 'GET /api/v1/hr-form-exports'

const row = (over: Partial<PdfExport> = {}): PdfExport => ({
  id: 'r1', type: 'experience_certificate', serial_number: 14, stage: 'unsigned', status: 'ready', employee_id: 'e1',
  created_at: '2026-11-14T15:05:00Z', file_name: 'experience-certificate-14-unsigned.pdf', actions: ['download'],
  employee: { id: 'e1', code: 1042, name_ar: 'سارة علي', name_en: 'Sara Ali' }, ...over,
})
const answer = (rows: PdfExport[]) => vi.fn((_url: string) => json(200, { data: rows, meta: { pagination: { ...pagination, total: rows.length } } }))
const urls = (list: ReturnType<typeof answer>) => list.mock.calls.map(([url]) => url)
const table = () => screen.findByRole('table', { name: 'النماذج الصادرة' })
const SEARCH = 'ابحث بالمسلسل أو الموظف'
function Url() {
  return <output data-testid="url">{useLocation().search}</output>
}
const url = () => new URLSearchParams(screen.getByTestId('url').textContent ?? '')

test('each row shows its serial, type, employee (linking to the file), stage, status and issue time', async () => {
  const list = answer([row(), row({ id: 'r2', serial_number: 3, type: 'advance_request', stage: 'signed', status: 'failed', actions: [], employee_id: null, employee: null })])
  mockFetch({ [LIST]: list })
  renderWithAuth(<IssuedFormsPage />, HR)
  const [, first, second] = within(await table()).getAllByRole('row')
  expect(first).toHaveTextContent('مسلسل 14')
  expect(first).toHaveTextContent('شهادة خبرة')
  expect(within(first!).getByRole('link', { name: 'سارة علي' })).toHaveAttribute('href', '/employees/e1')
  expect(first).toHaveTextContent('1042')
  expect(first).toHaveTextContent('بدون توقيع')
  expect(first).toHaveTextContent('جاهز')
  expect(first).toHaveTextContent('14 نوفمبر 2026 في 5:05 م')
  expect(second).toHaveTextContent('نموذج طلب سلفة')
  expect(second).toHaveTextContent('موقّعة')
  expect(second).toHaveTextContent('تعذر إنشاء الملف')
  expect(within(second!).queryByRole('link')).toBeNull()
  expect(urls(list)[0]).toContain('/hr-form-exports?page=1&per_page=20')
})

test('the type, stage and status filters and the search each ask the server again', async () => {
  const user = userEvent.setup()
  const list = answer([row()])
  mockFetch({ [LIST]: list })
  renderWithAuth(<IssuedFormsPage />, HR)
  await table()
  const last = () => urls(list).at(-1)
  await user.selectOptions(screen.getByRole('combobox', { name: 'النموذج' }), 'leave_request')
  await waitFor(() => expect(last()).toContain('type=leave_request'))
  await user.selectOptions(screen.getByRole('combobox', { name: 'المرحلة' }), 'signed')
  await waitFor(() => expect(last()).toContain('stage=signed'))
  await user.selectOptions(screen.getByRole('combobox', { name: 'الحالة' }), 'failed')
  await waitFor(() => expect(last()).toContain('status=failed'))
  await user.type(screen.getByRole('textbox', { name: SEARCH }), '14')
  await waitFor(() => expect(last()).toContain('q=14'))
  expect(last()).toMatch(/type=leave_request.*stage=signed.*status=failed/)
})

test('a filter and a search changed in the same tick both reach the request, and the controls show them at once', async () => {
  const list = answer([row()])
  mockFetch({ [LIST]: list })
  renderWithAuth(<IssuedFormsPage />, HR)
  await table()
  const type = screen.getByRole('combobox', { name: 'النموذج' })
  const search = screen.getByRole('textbox', { name: SEARCH })
  act(() => {
    fireEvent.change(type, { target: { value: 'leave_request' } })
    fireEvent.change(search, { target: { value: '14' } })
  })
  expect(type).toHaveValue('leave_request')
  expect(search).toHaveValue('14')
  await waitFor(() => expect(urls(list).at(-1)).toMatch(/type=leave_request.*q=14/))
})

test('the filters and page come from the URL; a filter change writes the URL and goes back to page 1', async () => {
  const user = userEvent.setup()
  const list = answer([row()])
  mockFetch({ [LIST]: list })
  renderWithAuth(<><IssuedFormsPage /><Url /></>, HR, { path: '/hr-forms?stage=signed&page=2' })
  await table()
  expect(urls(list)[0]).toContain('page=2&per_page=20&stage=signed')
  expect(screen.getByRole('combobox', { name: 'المرحلة' })).toHaveValue('signed')
  await user.click(screen.getByRole('checkbox', { name: 'بانتظار اعتمادي' }))
  await waitFor(() => expect(url().get('awaiting_approval')).toBe('1'))
  expect(url().get('stage')).toBe('signed')
  expect(url().get('page')).toBeNull()
  await waitFor(() => expect(urls(list).at(-1)).toContain('page=1&per_page=20&stage=signed&awaiting_approval=1'))
})

test('the menu link to this page while it is open starts it again from a clean URL', async () => {
  const user = userEvent.setup()
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  const list = answer([row()])
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me(HR) }), [LIST]: list })
  renderApp('/hr-forms?type=leave_request')
  const nav = await screen.findByRole('navigation', { name: 'القائمة الرئيسية' })
  expect(await screen.findByRole('combobox', { name: 'النموذج' })).toHaveValue('leave_request')
  await user.click(within(nav).getByRole('link', { name: 'النماذج الصادرة' }))
  await waitFor(() => expect(screen.getByRole('combobox', { name: 'النموذج' })).toHaveValue(''))
  await waitFor(() => expect(urls(list).at(-1)).not.toContain('type='))
})

test('«بانتظار اعتمادي» asks for the rows awaiting the caller', async () => {
  const user = userEvent.setup()
  const list = answer([row()])
  mockFetch({ [LIST]: list })
  renderWithAuth(<IssuedFormsPage />, ['hr_forms.view.all', 'hr_forms.approve.all'])
  await table()
  expect(urls(list)[0]).not.toContain('awaiting_approval')
  await user.click(screen.getByRole('checkbox', { name: 'بانتظار اعتمادي' }))
  await waitFor(() => expect(urls(list).at(-1)).toContain('awaiting_approval=1'))
})

test('Approve on a row that allows it posts to its route and the list is asked again', async () => {
  const user = userEvent.setup()
  const list = answer([row({ actions: ['download', 'approve'] })])
  const approve = vi.fn(() => json(202, { data: row({ id: 's1', stage: 'signed', status: 'pending', actions: [] }) }))
  mockFetch({ [LIST]: list, 'POST /api/v1/hr-form-exports/r1/approve': approve })
  renderWithAuth(<IssuedFormsPage />, ['hr_forms.view.all', 'hr_forms.approve.all'])
  await user.click(within(await table()).getByRole('button', { name: 'اعتماد' }))
  expect(approve).toHaveBeenCalledOnce()
  await waitFor(() => expect(list.mock.calls.length).toBeGreaterThan(1))
})

test('a row whose actions are empty shows no button and «—» for its actions', async () => {
  mockFetch({ [LIST]: answer([row({ actions: [] })]) })
  renderWithAuth(<IssuedFormsPage />, HR)
  const [, only] = within(await table()).getAllByRole('row')
  expect(within(only!).queryAllByRole('button')).toEqual([])
  expect(within(only!).getAllByRole('cell').at(-1)).toHaveTextContent(/—$/)
})

test('an advance copy made without the salary carries «بدون الراتب»; the copy with it does not', async () => {
  mockFetch({ [LIST]: answer([row({ type: 'advance_request', shows_pay: false }), row({ id: 'r2', serial_number: 13, type: 'advance_request', shows_pay: true })]) })
  renderWithAuth(<IssuedFormsPage />, HR)
  const [, noPay, pay] = within(await table()).getAllByRole('row')
  expect(noPay).toHaveTextContent('بدون الراتب')
  expect(pay).not.toHaveTextContent('بدون الراتب')
})

test('a listed pending row makes the list ask again every 3 s; closing the viewer meanwhile gives focus back to View', async () => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime })
  vi.stubGlobal('URL', class extends URL { static createObjectURL = vi.fn(() => 'blob:pdf'); static revokeObjectURL = vi.fn() })
  const list = answer([row(), row({ id: 'r2', serial_number: 15, status: 'pending', actions: [] })])
  mockFetch({ [LIST]: list, 'GET /api/v1/hr-form-exports/r1/download': () => new Response('%PDF-1.7') })
  renderWithAuth(<IssuedFormsPage />, HR)
  const view = within(await table()).getByRole('button', { name: 'عرض' })
  await user.click(view)
  await screen.findByRole('dialog', { name: 'شهادة خبرة · مسلسل 14 · بدون توقيع' })
  const before = list.mock.calls.length
  await act(() => vi.advanceTimersByTimeAsync(3000))
  await waitFor(() => expect(list.mock.calls.length).toBeGreaterThan(before))
  await user.keyboard('{Escape}')
  await vi.waitFor(() => expect(document.activeElement).toBe(view))
})

test('a page with no pending row does not poll', async () => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  const list = answer([row()])
  mockFetch({ [LIST]: list })
  renderWithAuth(<IssuedFormsPage />, HR)
  await table()
  const before = list.mock.calls.length
  await act(() => vi.advanceTimersByTimeAsync(9000))
  expect(list.mock.calls.length).toBe(before)
})

test('no rows shows the empty state', async () => {
  mockFetch({ [LIST]: answer([]) })
  renderWithAuth(<IssuedFormsPage />, HR)
  expect(await screen.findByText('لا توجد نماذج صادرة')).toBeInTheDocument()
})

test('a list the server refuses shows its error', async () => {
  mockFetch({ [LIST]: () => json(500, { error: { code: 'server_error', message: 'تعذر تحميل القائمة' } }) })
  renderWithAuth(<IssuedFormsPage />, HR)
  expect(await screen.findByRole('alert')).toHaveTextContent('تعذر تحميل القائمة')
})

test.each([
  [['hr_forms.view.all'], true],
  [['hr_forms.view.own', 'hr_forms.generate.own'], false],
])('the nav entry and the page need hr_forms.view.all (%j)', async (permissions, shown) => {
  expect(navAbilities('/hr-forms')).toEqual(['hr_forms.view.all'])
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me(permissions) }), [LIST]: answer([row()]) })
  renderApp('/hr-forms')
  const nav = await screen.findByRole('navigation', { name: 'القائمة الرئيسية' })
  if (shown) {
    expect(await screen.findByRole('heading', { name: 'النماذج الصادرة' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'النماذج الصادرة' })).toHaveAttribute('href', '/hr-forms')
  } else {
    expect(await screen.findByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'النماذج الصادرة' })).toBeNull()
  }
})

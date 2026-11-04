import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import i18n from '@/i18n'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { LogsPage } from './logs'

const user = userEvent.setup()

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.unstubAllGlobals()
  return i18n.changeLanguage('ar')
})

const row = (over: Record<string, unknown> = {}) => ({
  id: 'l1', request_id: 'req-1', user_id: 'u1', method: 'POST', path: 'api/v1/sites/s1/allowance-rules', route: 'api/v1/sites/{site}/allowance-rules',
  query: null, status: 200, duration_ms: 12, ip_address: '172.18.0.1', user_agent: 'Mozilla/5.0', request_body: null, response_body: null,
  occurred_at: '2026-11-04T20:01:02Z', user: { id: 'u1', name: 'developer' }, ...over,
})
const list = (rows: unknown[]) => vi.fn<(url: string) => Response>(() => json(200, { data: rows, meta: { pagination } }))
const lastUrl = (fn: { mock: { lastCall?: unknown[] } }) => String(fn.mock.lastCall?.[0])

test('renders in Arabic by default, with the request line left-to-right', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row()]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByRole('heading', { name: 'سجلات النظام' })).toBeInTheDocument()
  const table = await screen.findByRole('table', { name: 'سجلات النظام' })
  expect(within(table).getByText('api/v1/sites/s1/allowance-rules').closest('[dir="ltr"]')).toHaveClass('font-mono')
  expect(within(table).getByText('developer')).toBeInTheDocument()
})

test('filters become the query string', async () => {
  const fn = list([row()])
  mockFetch({ 'GET /api/v1/request-log': fn })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  await i18n.changeLanguage('en')
  await user.selectOptions(await screen.findByLabelText('Status'), 'failed')
  await user.selectOptions(screen.getByLabelText('Method'), 'POST')
  await user.type(screen.getByLabelText('From'), '2026-11-04')
  await user.type(screen.getByLabelText('Path'), 'employees')
  await waitFor(() => expect(lastUrl(fn)).toContain('path=employees'))
  expect(lastUrl(fn)).toContain('status=failed')
  expect(lastUrl(fn)).toContain('method=POST')
  expect(lastUrl(fn)).toContain('from=2026-11-04')
  expect(lastUrl(fn)).toContain('per_page=20')
})

test('a request_id in the URL filters the list and clear filters drops it', async () => {
  const fn = list([row()])
  mockFetch({ 'GET /api/v1/request-log': fn })
  renderWithAuth(<LogsPage />, ['system_log.view.all'], { path: '/console/logs?tab=requests&request_id=req-9' })
  await waitFor(() => expect(lastUrl(fn)).toContain('request_id=req-9'))
  await user.click(screen.getByRole('button', { name: 'مسح عوامل التصفية' }))
  await waitFor(() => expect(lastUrl(fn)).not.toContain('request_id'))
})

test('the status badge tone follows the code', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row(), row({ id: 'l2', status: 422 }), row({ id: 'l3', status: 500 })]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByText('200')).toHaveClass('text-success')
  expect(screen.getByText('422')).toHaveClass('text-warning')
  expect(screen.getByText('500')).toHaveClass('text-destructive')
})

test('a 422 details sheet shows the request metadata and its masked body as it is', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([row({ status: 422, query: { search: '[masked]' }, request_body: { kind: 'fixed', value: '[masked]' }, response_body: { message: 'invalid' } })]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  await user.click(await screen.findByRole('button', { name: 'عرض التفاصيل' }))
  const sheet = await screen.findByRole('dialog')
  expect(within(sheet).getByText('req-1')).toBeInTheDocument()
  expect(within(sheet).getByText('api/v1/sites/{site}/allowance-rules')).toBeInTheDocument()
  expect(within(sheet).getByText('172.18.0.1')).toBeInTheDocument()
  expect(within(sheet).getByText('Mozilla/5.0')).toBeInTheDocument()
  const body = within(sheet).getByRole('region', { name: 'محتوى الطلب' })
  expect(within(body).getByText(/"value": "\[masked\]"/)).toHaveAttribute('dir', 'ltr')
  expect(within(sheet).getByRole('region', { name: 'معاملات الاستعلام' })).toHaveTextContent('"search": "[masked]"')
  expect(within(sheet).getByRole('region', { name: 'محتوى الاستجابة' })).toHaveTextContent('"message": "invalid"')
  expect(sheet).not.toHaveTextContent('[object Object]')
})

test('without user.view there is no account filter and no account list call', async () => {
  mockFetch({ 'GET /api/v1/request-log': list([]) })
  renderWithAuth(<LogsPage />, ['system_log.view.all'])
  expect(await screen.findByLabelText('المسار')).toBeInTheDocument()
  expect(screen.queryByLabelText('الحساب')).toBeNull()
  expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('/users'))).toBe(false)
})

test('with user.view the account filter sends user_id', async () => {
  const fn = list([row()])
  mockFetch({ 'GET /api/v1/request-log': fn, 'GET /api/v1/users': () => json(200, { data: [{ id: 'u1', name: 'developer' }], meta: { pagination } }) })
  renderWithAuth(<LogsPage />, ['system_log.view.all', 'user.view.all'])
  await screen.findByRole('option', { name: 'developer' })
  await user.selectOptions(screen.getByLabelText('الحساب'), 'u1')
  await waitFor(() => expect(lastUrl(fn)).toContain('user_id=u1'))
})

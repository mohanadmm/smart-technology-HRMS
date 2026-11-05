import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, expect, test, vi } from 'vitest'
import { queryClient } from '@/lib/query'
import { json, me, mockFetch, renderApp, renderWithAuth } from '@/test-utils'
import { type TeamFareRow, TeamFaresPage } from './team-fares'

const user = userEvent.setup()
const LEAD = ['fare.manage.own', 'site.view.own']
const HR = ['fare.manage.all', 'site.view.all', 'site.manage.all', 'site.assign_manager.all']
const fmt = (v: number) => new Intl.NumberFormat('ar-EG-u-nu-latn', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
const urls = (part: string) => vi.mocked(fetch).mock.calls.map(([u]) => String(u)).filter((u) => u.includes(part))

const S1 = { id: 's1', name: 'العامرية' }
const S2 = { id: 's2', name: 'برج العرب' }
const R1 = { id: 'r1', name: 'كفر الدوار' }
const R2 = { id: 'r2', name: 'القاهرة' }
const row = (employee_id: string, name: string, type: TeamFareRow['type'], extra: Partial<TeamFareRow>): TeamFareRow => ({
  employee_id, code: 1, name, type, residence: R1, site: S1, amount: null, source: 'none', default_amount: null, overrides: [], ...extra,
})
const ROWS = [
  row('w1', 'محمود عبد الله', 'permanent_worker', { amount: '35.000000', source: 'default', default_amount: '35.000000' }),
  row('w2', 'سيد إبراهيم', 'permanent_worker', { amount: '30.000000', source: 'override', default_amount: '35.000000' }),
  row('w3', 'عادل منصور', 'day_laborer', { residence: R2, site: S2 }),
  row('w4', 'فتحي سالم', 'day_laborer', { residence: null, source: 'no_residence' }),
  row('e1', 'م. كريم فتحي', 'engineer', { residence: R2, site: null }),
]
const team = (rows = ROWS) => () => json(200, { data: { sites: [S1, S2], rows }, meta: { request_id: 'x' } })
const bodyRows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1)

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-05T10:00:00Z'))
  queryClient.clear()
  localStorage.clear()
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

test('lists the team with each source label', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, LEAD)
  expect(await screen.findByRole('heading', { level: 1, name: 'مواصلات التيم' })).toBeInTheDocument()
  await screen.findByText('محمود عبد الله')
  for (const label of ['تلقائي', 'معدّل للعامل', 'مفيش مبلغ للسكن ده', 'اكتب المبلغ أو اطلب من HR السكن', 'المهندس بيتحدد له بالاسم', 'السكن مش متسجل', 'حسب اليوم'])
    expect(screen.getByText(label)).toBeInTheDocument()
  expect(within(bodyRows()[0]).getByText(fmt(35))).toBeInTheDocument()
  expect(within(bodyRows()[1]).getByText(fmt(30))).toBeInTheDocument()
  expect(within(bodyRows()[2]).getByText('—')).toBeInTheDocument()
  expect(within(bodyRows()[0]).getByText('عامل مثبت')).toBeInTheDocument()
  expect(urls('/team-fares')).toEqual(['/api/v1/team-fares'])
})

test('chips count and filter the rows', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, LEAD)
  const all = await screen.findByRole('button', { name: 'كل التيم (5)' })
  expect(all).toHaveAttribute('aria-pressed', 'true')
  expect(bodyRows()).toHaveLength(5)
  const site2 = screen.getByRole('button', { name: 'برج العرب' })
  expect(screen.getByRole('button', { name: 'العامرية' })).toHaveAttribute('aria-pressed', 'false')
  await user.click(site2)
  expect(site2).toHaveAttribute('aria-pressed', 'true')
  expect(bodyRows()).toHaveLength(1)
  expect(within(bodyRows()[0]).getByText('عادل منصور')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'من غير مبلغ (2)' }))
  expect(bodyRows().map((r) => within(r).getAllByRole('cell')[0].textContent)).toEqual([expect.stringContaining('عادل منصور'), expect.stringContaining('فتحي سالم')])
  await user.click(all)
  expect(bodyRows()).toHaveLength(5)
})

test('an empty team says so', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team([]) })
  renderWithAuth(<TeamFaresPage />, LEAD)
  expect(await screen.findByText('مفيش عمال في التيم النهارده.')).toBeInTheDocument()
})

test('a user without fare.manage gets not found', async () => {
  mockFetch({ 'GET /api/v1/team-fares': team() })
  renderWithAuth(<TeamFaresPage />, ['site.view.own'])
  expect(screen.getByRole('heading', { name: 'الصفحة غير موجودة' })).toBeInTheDocument()
  expect(urls('/team-fares')).toEqual([])
})

test('an all-scope user picks a team lead first', async () => {
  mockFetch({
    'GET /api/v1/sites/manager-candidates': () => json(200, { data: [{ id: 'm1', name: 'م. أحمد سامي' }] }),
    'GET /api/v1/team-fares': team(),
  })
  renderWithAuth(<TeamFaresPage />, HR)
  const pick = await screen.findByRole('combobox', { name: 'مسؤول التيم' })
  await screen.findByRole('option', { name: 'م. أحمد سامي' })
  expect(urls('/team-fares')).toEqual([])
  await user.selectOptions(pick, 'm1')
  await screen.findByText('محمود عبد الله')
  expect(urls('/team-fares')).toEqual(['/api/v1/team-fares?manager_id=m1'])
})

test('the route renders the page', async () => {
  localStorage.setItem('hrms.auth.token.v1', 't'.repeat(64))
  mockFetch({ 'GET /api/v1/auth/me': () => json(200, { data: me(LEAD) }), 'GET /api/v1/team-fares': team() })
  renderApp('/team-fares')
  expect(await screen.findByRole('heading', { level: 1, name: 'مواصلات التيم' })).toBeInTheDocument()
  await waitFor(() => expect(urls('/team-fares')).toHaveLength(1))
})

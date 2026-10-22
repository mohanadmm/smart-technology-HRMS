import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { SiteDayPage } from './site-day'

const user = userEvent.setup()
afterEach(() => vi.unstubAllGlobals())

const MANAGER = ['attendance.record.own', 'site.view.own']
const site = (id: string, name: string) => ({ id, name_ar: name, name_en: name, kind: 'work', active: true })
const day = (extra = {}) => ({ state: 'missing', version: null, site_id: null, leave_code: null, in_time: null, out_time: null, note: null, ...extra })
const row = (id: string, name: string, type: string, section: string, d = {}) => ({ employee: { id, code: 1, name_ar: name, name_en: name, type }, section, day: day(d) })
const answer = (s: { id: string; name_ar: string }, rows: unknown[]) => json(200, { data: { site: { ...s, name_en: s.name_ar }, date: '2026-10-22', today: '2026-10-22', rows } })

const S1 = site('s1', 'Site-1')
const S2 = site('s2', 'Site-2')
const S1_ROWS = [
  row('w1', 'Worker-1', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1' }),
  row('w2', 'Worker-2', 'permanent_worker', 'workers', { state: 'stored', version: 1, site_id: 'leave-absent', leave_code: 'absent' }),
  row('e1', 'Engineer-1', 'engineer', 'engineers'),
]

function Url() {
  return <output data-testid="url">{useLocation().search}</output>
}
const render = (path = '/site-day') => renderWithAuth(<><SiteDayPage /><Url /></>, MANAGER, { path })

function routes(sites: unknown[], days: Record<string, unknown[]>) {
  const siteDay = vi.fn((url: string) => {
    const id = new URL(url, 'http://x').searchParams.get('site_id')!
    return answer(id === 's2' ? S2 : S1, days[id] ?? [])
  })
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination: { ...pagination, total: sites.length } } }),
    'GET /api/v1/attendance/site-day': siteDay,
  })
  return siteDay
}

test('one site: no picker, workers then the engineers section', async () => {
  const siteDay = routes([S1], { s1: S1_ROWS })
  render()
  const list = await screen.findByRole('list', { name: 'العاملون بالموقع' })
  expect(screen.getByRole('heading', { level: 1, name: 'Site-1' })).toBeInTheDocument()
  expect(screen.queryByRole('combobox', { name: 'الموقع' })).toBeNull()
  expect(siteDay.mock.calls[0][0]).toContain('site_id=s1')
  const items = within(list).getAllByRole('listitem').map((li) => li.textContent)
  expect(items[0]).toContain('Worker-1')
  expect(items[1]).toContain('Worker-2')
  expect(items[2]).toBe('مهندسين')
  expect(items[3]).toContain('Engineer-1')
  const worker1 = within(list).getByRole('group', { name: 'Worker-1' })
  expect(within(worker1).getByText('عامل باليومية')).toBeInTheDocument()
  expect(within(worker1).getByRole('button', { name: 'حاضر' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(worker1).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'false')
  for (const b of within(worker1).getAllByRole('button')) expect(b).toBeDisabled()
  expect(within(list).getByRole('group', { name: 'Worker-2' }).querySelector('[aria-pressed="true"]')).toHaveTextContent('غياب')
  expect(within(list).getByRole('group', { name: 'Engineer-1' }).querySelector('[aria-pressed="true"]')).toBeNull()
  expect(within(list).getAllByRole('button', { name: 'أنواع إجازات أخرى' })).toHaveLength(3)
})

test('two sites: the picker switches the list', async () => {
  const siteDay = routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render('/site-day?date=2026-10-21')
  const picker = await screen.findByRole('combobox', { name: 'الموقع' })
  expect(await screen.findByText('Worker-1')).toBeInTheDocument()
  expect(siteDay.mock.calls[0][0]).toContain('date=2026-10-21')
  await user.selectOptions(picker, 's2')
  expect(await screen.findByText('Worker-3')).toBeInTheDocument()
  expect(screen.getByRole('heading', { level: 1, name: 'Site-2' })).toBeInTheDocument()
  expect(screen.getByTestId('url')).toHaveTextContent('site=s2')
  expect(screen.getByTestId('url')).toHaveTextContent('date=2026-10-21')
})

test('an engineer at another site shows that site', async () => {
  routes([S1, S2], {
    s1: [
      row('e1', 'Engineer-1', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's2' }),
      row('e2', 'Engineer-2', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's9' }),
    ],
  })
  render('/site-day?site=s1')
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByText('في موقع: Site-2')).toBeInTheDocument()
  expect(within(screen.getByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'حاضر' })).toHaveAttribute('aria-pressed', 'false')
  expect(within(screen.getByRole('group', { name: 'Engineer-2' })).getByText('في موقع آخر')).toBeInTheDocument()
})

test('a worker stored at another site shows the hint and nothing pressed', async () => {
  routes([S1, S2], { s1: [row('w1', 'Worker-1', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's2' })] })
  render('/site-day?site=s1')
  const worker = await screen.findByRole('group', { name: 'Worker-1' })
  expect(within(worker).getByText('في موقع: Site-2')).toBeInTheDocument()
  expect(worker.querySelector('[aria-pressed="true"]')).toBeNull()
})

test('no sites shows the empty state', async () => {
  const siteDay = routes([], {})
  render()
  expect(await screen.findByText('لا توجد مواقع مسندة إليك. تواصل مع الموارد البشرية.')).toBeInTheDocument()
  await waitFor(() => expect(siteDay).not.toHaveBeenCalled())
  expect(screen.queryByRole('list')).toBeNull()
})

test('a site with nobody on the day shows the empty list state', async () => {
  routes([S1], { s1: [] })
  render()
  expect(await screen.findByText('لا يوجد عاملون مسجلون بهذا الموقع في هذا اليوم.')).toBeInTheDocument()
})

test('a refused day shows the error', async () => {
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: [S1], meta: { pagination } }),
    'GET /api/v1/attendance/site-day': () => json(422, { error: { code: 'future_date', message: 'لا يمكن تسجيل يوم في المستقبل.', details: [] } }),
  })
  render('/site-day?date=2026-10-30')
  expect(await screen.findByRole('alert')).toHaveTextContent('لا يمكن تسجيل يوم في المستقبل.')
})

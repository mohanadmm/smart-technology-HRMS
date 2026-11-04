import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { SiteDayPage } from './site-day'

const user = userEvent.setup()
afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

const MANAGER = ['attendance.record.own', 'site.view.own', 'employee.crew.own', 'employee.propose_laborer.own']
const LEAVE = { absent: 'leave-absent', sick: 'leave-sick', rest: 'leave-rest', other: 'leave-other' }
const site = (id: string, name: string) => ({ id, name_ar: name, name_en: name, kind: 'work', active: true })
const day = (extra = {}) => ({ state: 'missing', version: null, site_id: null, leave_code: null, in_time: null, out_time: null, note: null, ...extra })
const row = (id: string, name: string, type: string, section: string, d = {}, extra = {}) => ({
  employee: { id, code: 1, name_ar: name, name_en: name, type }, section, shift: { start: '08:00', end: '16:00' }, day: day(d), can_edit: true, why_not: null, ...extra,
})
const answer = (s: { id: string; name_ar: string }, rows: unknown[]) => ({ site: { ...s, name_en: s.name_ar }, date: '2026-10-22', today: '2026-10-22', leave_sites: LEAVE, rows })

const S1 = site('s1', 'Site-1')
const S2 = site('s2', 'Site-2')
const S1_ROWS = [
  row('w1', 'Worker-1', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
  row('w2', 'Worker-2', 'permanent_worker', 'workers', { state: 'stored', version: 1, site_id: 'leave-absent', leave_code: 'absent' }),
  row('e1', 'Engineer-1', 'engineer', 'engineers'),
]

function Url() {
  return <output data-testid="url">{useLocation().search}</output>
}
const render = (path = '/site-day', perms = MANAGER) => renderWithAuth(<><SiteDayPage /><Url /></>, perms, { path })
/** The entry step: toggle these sites in the picker, then «متابعة». */
async function enter(toggle: string[] = []) {
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  for (const name of toggle) await user.click(within(list).getByRole('checkbox', { name }))
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
}

type Handler = (url: string, init?: RequestInit) => Response
function routes(sites: unknown[], days: Record<string, unknown[]>, more: Record<string, Handler> = {}) {
  const siteDay = vi.fn((url: string) => {
    const id = new URL(url, 'http://x').searchParams.get('site_id')!
    return json(200, { data: answer(id === 's2' ? S2 : S1, days[id] ?? []) })
  })
  const post = vi.fn((_url: string, init?: RequestInit) => {
    const body = JSON.parse(String(init?.body))
    return json(200, { data: answer(body.site_id === 's2' ? S2 : S1, days[body.site_id] ?? []) })
  })
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: sites, meta: { pagination: { ...pagination, total: sites.length } } }),
    'GET /api/v1/attendance/site-day': siteDay,
    'POST /api/v1/attendance/site-day': post,
    ...more,
  })
  return { siteDay, post }
}
const bodyOf = (fn: { mock: { calls: unknown[][] } }, i = 0) => JSON.parse(String((fn.mock.calls[i][1] as RequestInit).body))
const group = (name: string) => screen.getByRole('group', { name })

test('one site: no switcher, workers then the engineers section, and the stored marks pressed', async () => {
  const { siteDay } = routes([S1], { s1: S1_ROWS })
  render()
  await enter()
  const list = await screen.findByRole('list', { name: 'العاملون بالموقع' })
  expect(screen.getByRole('heading', { level: 1, name: 'Site-1' })).toBeInTheDocument()
  expect(within(screen.getByRole('navigation', { name: 'الموقع' })).queryAllByRole('button')).toHaveLength(1) // «تغيير المواقع» only
  expect(siteDay.mock.calls[0][0]).toContain('site_id=s1')
  const items = within(list).getAllByRole('listitem').map((li) => li.textContent)
  expect(items[0]).toContain('Worker-1')
  expect(items[2]).toBe('مهندسين')
  expect(items[3]).toContain('Engineer-1')
  expect(within(group('Worker-1')).getByRole('button', { name: 'حاضر' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Worker-2')).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
  expect(group('Engineer-1').querySelector('[aria-pressed="true"]')).toBeNull()
  expect(screen.getByRole('button', { name: 'حفظ اليوم' })).toBeDisabled()
})

test('morning: «حضور الكل» fills the shift start, keeps a typed arrival, an absence and approved leave, and saves only the changes', async () => {
  const { post } = routes([S1], {
    s1: [
      row('a', 'Laborer-A', 'day_laborer', 'workers'),
      row('b', 'Laborer-B', 'day_laborer', 'workers'),
      row('c', 'Worker-C', 'permanent_worker', 'workers', { state: 'stored', version: 2, site_id: 'leave-absent', leave_code: 'absent' }),
      row('d', 'Worker-D', 'permanent_worker', 'workers', { state: 'stored', version: 3, site_id: 'leave-annual', leave_code: 'annual' }, { can_edit: false, why_not: 'hr_day' }),
      row('e', 'Engineer-E', 'engineer', 'engineers'),
    ],
  })
  render()
  await enter()
  await screen.findByRole('group', { name: 'Laborer-A' })
  await user.click(within(group('Laborer-B')).getByRole('button', { name: 'حاضر' }))
  const arrivalB = within(group('Laborer-B')).getByRole('textbox', { name: 'حضور Laborer-B' })
  await user.clear(arrivalB)
  await user.type(arrivalB, '9:15')
  expect(within(group('Laborer-B')).getByText('تأخير 75 دقيقة')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'حضور الكل (2)' }))
  expect(screen.getByRole('button', { name: 'حضور الكل (0)' })).toBeDisabled()
  expect(within(group('Laborer-A')).getByRole('textbox', { name: 'حضور Laborer-A' })).toHaveValue('8:00')
  expect(within(group('Laborer-A')).getByRole('textbox', { name: 'انصراف Laborer-A' })).toHaveValue('')
  expect(within(group('Laborer-A')).getByText('بلا انصراف')).toBeInTheDocument()
  expect(within(group('Worker-C')).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Worker-D')).getByText(/سجّلته الموارد البشرية؛ تعدّله هي فقط/)).toBeInTheDocument()
  for (const b of within(group('Worker-D')).getAllByRole('button')) expect(b).toBeDisabled()
  expect(screen.getByText('تغييرات غير محفوظة: 3')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post)).toEqual({
    site_id: 's1',
    date: '2026-10-22',
    rows: [
      { employee_id: 'a', version: null, site_id: 's1', in_time: '08:00', out_time: null },
      { employee_id: 'b', version: null, site_id: 's1', in_time: '09:15', out_time: null },
      { employee_id: 'e', version: null, site_id: 's1', in_time: '08:00', out_time: null },
    ],
  })
  expect(await screen.findByText('تم الحفظ.')).toBeInTheDocument()
})

test('evening: «انصراف الكل» fills the shift end, keeps a typed departure, and shows early and late departures', async () => {
  const { post } = routes([S1], {
    s1: [
      row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
      row('b', 'Laborer-B', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
      row('c', 'Laborer-C', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' }),
      row('d', 'Worker-D', 'permanent_worker', 'workers', { state: 'stored', version: 1, site_id: 'leave-sick', leave_code: 'sick' }),
    ],
  })
  render()
  await enter()
  await screen.findByRole('group', { name: 'Laborer-A' })
  expect(screen.getByText('بلا انصراف: 3')).toBeInTheDocument()
  const outB = within(group('Laborer-B')).getByRole('textbox', { name: 'انصراف Laborer-B' })
  await user.type(outB, '3:00')
  await user.click(within(group('Laborer-B')).getAllByRole('button', { name: 'مساءً' })[1])
  expect(within(group('Laborer-B')).getByText('انصراف مبكر 60 دقيقة')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'انصراف الكل (2)' }))
  const outC = within(group('Laborer-C')).getByRole('textbox', { name: 'انصراف Laborer-C' })
  expect(outC).toHaveValue('4:00')
  await user.clear(outC)
  await user.type(outC, '5:30')
  expect(within(group('Laborer-C')).getByText(/إضافي بعد الموعد 90 دقيقة/)).toBeInTheDocument()
  expect(screen.getByText('بلا انصراف: 0')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([
    { employee_id: 'a', version: 1, site_id: 's1', in_time: '08:00', out_time: '16:00' },
    { employee_id: 'b', version: 1, site_id: 's1', in_time: '08:00', out_time: '15:00' },
    { employee_id: 'c', version: 1, site_id: 's1', in_time: '08:00', out_time: '17:30' },
  ])
})

test('entry: the picker comes first, takes several sites, and the working list opens one site at a time', async () => {
  const { siteDay } = routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  expect(screen.queryByRole('list', { name: 'العاملون بالموقع' })).toBeNull()
  expect(siteDay).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'متابعة' })).toBeDisabled()
  await user.click(within(list).getByRole('checkbox', { name: 'Site-1' }))
  await user.click(within(list).getByRole('checkbox', { name: 'Site-2' }))
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  expect(await screen.findByRole('heading', { level: 1, name: 'Site-1' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.siteDay.sites')).toBe('["s1","s2"]')
  expect(siteDay.mock.calls.every(([url]) => String(url).includes('site_id=s1') || String(url).includes('site_id=s2'))).toBe(true)
})

test('two sites: switching keeps each site its own roster and unsaved changes', async () => {
  routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  await enter(['Site-1', 'Site-2'])
  const nav = await screen.findByRole('navigation', { name: 'الموقع' })
  await screen.findByRole('group', { name: 'Engineer-1' })
  await user.click(within(group('Engineer-1')).getByRole('button', { name: 'غياب' }))
  expect(within(nav).getByRole('button', { name: /Site-1/ })).toHaveTextContent('غير محفوظ')

  await user.click(within(nav).getByRole('button', { name: /Site-2/ }))
  expect(await screen.findByRole('group', { name: 'Worker-3' })).toBeInTheDocument()
  expect(screen.queryByRole('group', { name: 'Engineer-1' })).toBeNull()
  expect(screen.getByTestId('url')).toHaveTextContent('site=s2')
  expect(screen.getByRole('button', { name: 'حفظ اليوم' })).toBeDisabled()

  await user.click(within(nav).getByRole('button', { name: /Site-1/ }))
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
})

test('«تغيير المواقع» reopens the picker; a site left out keeps its draft, which comes back when chosen again', async () => {
  routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  await enter(['Site-1', 'Site-2'])
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'غياب' }))
  await user.click(screen.getByRole('button', { name: 'تغيير المواقع' }))
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  expect(within(list).getByRole('checkbox', { name: /Site-1/ })).toHaveAttribute('aria-checked', 'true')
  await user.click(within(list).getByRole('checkbox', { name: /Site-1/ }))
  expect(screen.getByText(/في Site-1 تغييرات غير محفوظة/)).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  expect(await screen.findByRole('heading', { level: 1, name: 'Site-2' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Site-1/ })).toBeNull()

  await user.click(screen.getByRole('button', { name: 'تغيير المواقع' }))
  await user.click(within(await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })).getByRole('checkbox', { name: /Site-1/ }))
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  await user.click(await screen.findByRole('button', { name: /^Site-1/ }))
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'غياب' })).toHaveAttribute('aria-pressed', 'true')
})

test('the last working list is preselected, without sites no longer assigned', async () => {
  localStorage.setItem('hrms.siteDay.sites', '["s2","gone"]')
  routes([S1, S2], { s1: S1_ROWS, s2: [row('w3', 'Worker-3', 'day_laborer', 'workers')] })
  render()
  const list = await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })
  expect(within(list).getByRole('checkbox', { name: 'Site-2' })).toHaveAttribute('aria-checked', 'true')
  expect(within(list).getByRole('checkbox', { name: 'Site-1' })).toHaveAttribute('aria-checked', 'false')
  await user.click(screen.getByRole('button', { name: 'متابعة' }))
  expect(await screen.findByRole('heading', { level: 1, name: 'Site-2' })).toBeInTheDocument()
  expect(localStorage.getItem('hrms.siteDay.sites')).toBe('["s2"]')
})

test('with one assigned site it is preselected: one tap continues', async () => {
  routes([S1], { s1: S1_ROWS })
  render()
  expect(within(await screen.findByRole('list', { name: 'المواقع التي ستعمل عليها' })).getByRole('checkbox', { name: 'Site-1' })).toHaveAttribute('aria-checked', 'true')
})

test('an empty site still offers «إضافة عامل يومية», with the bulk actions off', async () => {
  routes([S1], { s1: [] })
  render()
  await enter()
  expect(await screen.findByText('لا يوجد عاملون مسجلون بهذا الموقع في هذا اليوم.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'إضافة عامل يومية' })).toBeEnabled()
  expect(screen.getByRole('button', { name: 'حضور الكل (0)' })).toBeDisabled()
})

test('while a save runs the rows are read-only, and a failed save keeps the draft', async () => {
  let finish: (r: Response) => void = () => {}
  const post = vi.fn(() => new Promise<Response>((resolve) => { finish = resolve }) as unknown as Response)
  routes([S1], { s1: S1_ROWS }, { 'POST /api/v1/attendance/site-day': post })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'مرضي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText('جارٍ الحفظ…')).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'غياب' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'حفظ اليوم' })).toBeDisabled()
  finish(json(500, { error: { code: 'server_error', message: 'تعذّر الحفظ', details: [] } }))
  expect(await screen.findByText('تعذّر الحفظ')).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  expect(within(group('Engineer-1')).getByRole('button', { name: 'غياب' })).toBeEnabled()
})

test('«⋯» offers rest and other only, never leave that needs a request', async () => {
  routes([S1], { s1: S1_ROWS })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'أنواع إجازات أخرى' }))
  const items = (await screen.findAllByRole('menuitem')).map((i) => i.textContent)
  expect(items).toEqual(['راحة', 'أخرى'])
})

test('a 409 keeps the changes and offers the latest', async () => {
  const { siteDay } = routes([S1], { s1: S1_ROWS }, {
    'POST /api/v1/attendance/site-day': () => json(409, { error: { code: 'concurrency_conflict', message: 'تغيّر', details: [{ field: 'rows.0', code: 'concurrency_conflict', message: 'تغيّر' }] } }),
  })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByRole('button', { name: 'مرضي' }))
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText(/عدّل شخص آخر هذا اليوم/)).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  const calls = siteDay.mock.calls.length
  await user.click(screen.getByRole('button', { name: 'تحميل الأحدث' }))
  await waitFor(() => expect(siteDay.mock.calls.length).toBeGreaterThan(calls))
  expect(within(group('Engineer-1')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'false')
})

test('a day laborer with nothing recorded can be removed from today; one already recorded cannot', async () => {
  const crew = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: { employee_id: 'x', action: 'remove' } }))
  routes([S1], { s1: [row('x', 'Laborer-X', 'day_laborer', 'workers'), ...S1_ROWS] }, { 'POST /api/v1/attendance/site-day/crew': crew })
  render()
  await enter()
  await user.click(within(await screen.findByRole('group', { name: 'Laborer-X' })).getByRole('button', { name: 'إخراج Laborer-X من طاقم اليوم' }))
  await waitFor(() => expect(crew).toHaveBeenCalledTimes(1))
  expect(bodyOf(crew)).toEqual({ site_id: 's1', employee_id: 'x', action: 'remove' })
  expect(within(group('Worker-1')).queryByRole('button', { name: /إخراج/ })).toBeNull()
  expect(within(group('Engineer-1')).queryByRole('button', { name: /إخراج/ })).toBeNull()
})

test('«إضافة عامل يومية» finds existing laborers first, then proposes a new one', async () => {
  const crew = vi.fn((_url: string, _init?: RequestInit) => json(200, { data: {} }))
  const create = vi.fn((_url: string, _init?: RequestInit) => json(201, { data: { id: 'n1', code: 77, name_ar: 'سعيد' } }))
  routes([S1], { s1: S1_ROWS }, {
    'GET /api/v1/attendance/site-day/laborers': () => json(200, {
      data: [
        { id: 'f1', code: 12, name_ar: 'سعيد علي', name_en: null, site: null, here: false, can_add: true },
        { id: 'f2', code: 13, name_ar: 'سعيد حسن', name_en: null, site: { id: 's9', name_ar: 'Site-9', name_en: null }, here: false, can_add: false },
      ],
    }),
    'POST /api/v1/attendance/site-day/crew': crew,
    'POST /api/v1/attendance/site-day/laborers': create,
  })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة عامل يومية' }))
  const dialog = await screen.findByRole('dialog')
  await user.type(within(dialog).getByRole('textbox', { name: 'اسم العامل' }), 'سعيد')
  const matches = await within(dialog).findByRole('region', { name: 'عمال مسجلون بهذا الاسم' })
  expect(await within(matches).findByText('على موقع آخر؛ تواصل مع الموارد البشرية')).toBeInTheDocument()
  expect(within(matches).getAllByRole('button', { name: 'إضافة لطاقم اليوم' })).toHaveLength(1)

  await user.type(within(dialog).getByRole('textbox', { name: 'الأجر اليومي المقترح' }), '350')
  await user.type(within(dialog).getByRole('textbox', { name: 'رقم الموبايل (اختياري)' }), '01012345678')
  await user.click(within(dialog).getByRole('button', { name: 'إضافة العامل الجديد' }))
  await waitFor(() => expect(create).toHaveBeenCalledTimes(1))
  expect(bodyOf(create)).toEqual({ site_id: 's1', name_ar: 'سعيد', daily_wage: '350', phone: '01012345678' })
  await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())

  await user.click(screen.getByRole('button', { name: 'إضافة عامل يومية' }))
  await user.type(within(await screen.findByRole('dialog')).getByRole('textbox', { name: 'اسم العامل' }), 'سعيد')
  await user.click(await screen.findByRole('button', { name: 'إضافة لطاقم اليوم' }))
  await waitFor(() => expect(crew).toHaveBeenCalledTimes(1))
  expect(bodyOf(crew)).toEqual({ site_id: 's1', employee_id: 'f1', action: 'add' })
})

test('without the crew permissions there is no add or remove', async () => {
  routes([S1], { s1: [row('x', 'Laborer-X', 'day_laborer', 'workers')] })
  render('/site-day', ['attendance.record.own', 'site.view.own'])
  await enter()
  await screen.findByRole('group', { name: 'Laborer-X' })
  expect(screen.queryByRole('button', { name: 'إضافة عامل يومية' })).toBeNull()
  expect(screen.queryByRole('button', { name: /إخراج/ })).toBeNull()
})

test('an engineer at another site shows that site', async () => {
  routes([S1, S2], {
    s1: [
      row('e1', 'Engineer-1', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's2' }),
      row('e2', 'Engineer-2', 'engineer', 'engineers', { state: 'stored', version: 1, site_id: 's9' }),
    ],
  })
  render('/site-day?site=s1')
  await enter(['Site-1'])
  expect(within(await screen.findByRole('group', { name: 'Engineer-1' })).getByText('في موقع: Site-2')).toBeInTheDocument()
  expect(within(group('Engineer-1')).getByRole('button', { name: 'حاضر' })).toHaveAttribute('aria-pressed', 'false')
  expect(within(group('Engineer-2')).getByText('في موقع آخر')).toBeInTheDocument()
})

test('no sites shows the empty state with HR as the contact', async () => {
  const { siteDay } = routes([], {})
  render()
  expect(await screen.findByText('لا توجد مواقع مسندة إليك. تواصل مع الموارد البشرية.')).toBeInTheDocument()
  await waitFor(() => expect(siteDay).not.toHaveBeenCalled())
})

test('a site with nobody on the day shows the empty list state', async () => {
  routes([S1], { s1: [] })
  render()
  await enter()
  expect(await screen.findByText('لا يوجد عاملون مسجلون بهذا الموقع في هذا اليوم.')).toBeInTheDocument()
})

test('a refused day shows the error', async () => {
  mockFetch({
    'GET /api/v1/sites': () => json(200, { data: [S1], meta: { pagination } }),
    'GET /api/v1/attendance/site-day': () => json(422, { error: { code: 'future_date', message: 'لا يمكن تسجيل يوم في المستقبل.', details: [] } }),
  })
  render('/site-day?date=2026-10-30')
  await enter()
  expect(await screen.findByRole('alert')).toHaveTextContent('لا يمكن تسجيل يوم في المستقبل.')
})

test('a time that is not a time stops the save and names the person', async () => {
  const { post } = routes([S1], { s1: [row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '08:00' })] })
  render()
  await enter()
  await user.type(await screen.findByRole('textbox', { name: 'انصراف Laborer-A' }), '3:303:30')
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  expect(await screen.findByText(/وقت غير صحيح عند: Laborer-A/)).toBeInTheDocument()
  expect(post).not.toHaveBeenCalled()
})

test('an earlier day without a departure locks the row, says why, and opens that day', async () => {
  const { siteDay } = routes([S1], { s1: [row('e1', 'Engineer-1', 'engineer', 'engineers', {}, { can_edit: false, why_not: 'open_day', open_date: '2026-10-21', open_editable: true }), row('e2', 'Engineer-2', 'engineer', 'engineers', {}, { can_edit: false, why_not: 'open_day', open_date: '2026-10-20', open_editable: false })] })
  render()
  await enter()
  const g = await screen.findByRole('group', { name: 'Engineer-1' })
  expect(within(g).getByText(/انصراف يوم 2026-10-21 ناقص/)).toBeInTheDocument()
  expect(within(g).getByRole('button', { name: 'حاضر' })).toBeDisabled()
  const g2 = screen.getByRole('group', { name: 'Engineer-2' })
  expect(within(g2).getByText(/انصراف يوم 2026-10-20 ناقص؛ تكمّله الموارد البشرية/)).toBeInTheDocument()
  expect(within(g2).queryByRole('button', { name: /افتح يوم/ })).toBeNull()
  await user.click(within(g).getByRole('button', { name: 'افتح يوم 2026-10-21 وسجّل الانصراف' }))
  expect(screen.getByTestId('url')).toHaveTextContent('date=2026-10-21')
  await waitFor(() => expect(siteDay.mock.calls.some(([u]) => String(u).includes('date=2026-10-21'))).toBe(true))
})

test('«زي امبارح» fills only the empty rows from yesterday, and one save sends them', async () => {
  const prev = (p = {}) => ({ previous: { date: '2026-10-21', site_id: 's1', leave_code: null, in_time: '08:10', out_time: '16:00', ...p } })
  const { post } = routes([S1], {
    s1: [
      row('a', 'Laborer-A', 'day_laborer', 'workers', {}, prev()),
      row('b', 'Laborer-B', 'day_laborer', 'workers', {}, prev({ site_id: 'leave-sick', leave_code: 'sick', in_time: null, out_time: null })),
      row('c', 'Laborer-C', 'day_laborer', 'workers', {}, prev({ site_id: 'leave-annual', leave_code: 'annual', in_time: null, out_time: null })),
      row('d', 'Laborer-D', 'day_laborer', 'workers', { state: 'stored', version: 1, site_id: 's1', in_time: '09:00' }, prev()),
    ],
  })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'زي امبارح (2)' }))
  expect(within(group('Laborer-A')).getByRole('textbox', { name: 'حضور Laborer-A' })).toHaveValue('8:10')
  expect(within(group('Laborer-A')).getByRole('textbox', { name: 'انصراف Laborer-A' })).toHaveValue('')
  expect(within(group('Laborer-B')).getByRole('button', { name: 'مرضي' })).toHaveAttribute('aria-pressed', 'true')
  expect(group('Laborer-C').querySelector('[aria-pressed="true"]')).toBeNull()
  expect(post).not.toHaveBeenCalled()
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([
    { employee_id: 'a', version: null, site_id: 's1', in_time: '08:10', out_time: null },
    { employee_id: 'b', version: null, site_id: 'leave-sick', in_time: null, out_time: null },
  ])
})

test('a note is typed on the row and saved with it', async () => {
  const { post } = routes([S1], { s1: [row('a', 'Laborer-A', 'day_laborer', 'workers', { state: 'stored', version: 2, site_id: 's1', in_time: '09:00' })] })
  render()
  await enter()
  await user.click(await screen.findByRole('button', { name: 'إضافة ملاحظة' }))
  await user.type(screen.getByRole('textbox', { name: 'ملاحظة Laborer-A' }), 'وصل متأخر بسبب المواصلات')
  await user.click(screen.getByRole('button', { name: 'حفظ اليوم' }))
  await waitFor(() => expect(post).toHaveBeenCalledTimes(1))
  expect(bodyOf(post).rows).toEqual([{ employee_id: 'a', version: 2, site_id: 's1', in_time: '09:00', out_time: null, note: 'وصل متأخر بسبب المواصلات' }])
})

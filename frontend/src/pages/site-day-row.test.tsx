import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import type { Draft, SiteDayRow } from '@/lib/site-day'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { PersonRow } from './site-day-row'

afterEach(() => vi.unstubAllGlobals())

const MANAGER = ['attendance.record.own', 'hr_forms.view.own', 'hr_forms.generate.own']
const row: SiteDayRow = {
  employee: { id: 'w1', code: 1043, name_ar: 'عامل موقع', name_en: 'Site worker', type: 'permanent_worker' },
  section: 'workers',
  shift: { start: '08:00', end: '16:00' },
  day: { state: 'stored', version: 1, site_id: 's1', leave_code: null, in_time: '08:00', out_time: '16:00', note: null },
  requests: { overtime: null, advance: { id: 'adv1', status: 'pending', minutes: null, amount: '250.500000', version: 2 } },
  can_edit: true,
  why_not: null,
  departure_only: false,
  open_date: null,
  open_site_id: null,
}
const stored: Draft = { site_id: 's1', in_time: '08:00', out_time: '16:00' }

function render(permissions: string[], draft: Draft = stored) {
  const list = vi.fn((_url: string) => json(200, { data: [], meta: { pagination } }))
  mockFetch({ 'GET /api/v1/hr-form-exports': list })
  renderWithAuth(
    <ul>
      <PersonRow r={row} draft={draft} siteId="s1" leave={{}} sites={[]} set={() => {}} removable={false} onRemoved={() => {}} locked={false} onOpenDay={() => {}} />
    </ul>,
    permissions,
  )
  return list
}

test('a saved advance gives the site manager «نموذج السلفة», a dialog with the advance PDF panel that hands focus back on close', async () => {
  const user = userEvent.setup()
  const list = render(MANAGER)
  const button = screen.getByRole('button', { name: 'نموذج السلفة' })
  expect(list).not.toHaveBeenCalled()
  await user.click(button)
  const dialog = await screen.findByRole('dialog', { name: 'نموذج السلفة' })
  expect(await within(dialog).findByText('لم يُنشأ ملف بعد')).toBeInTheDocument()
  expect(list.mock.calls[0]?.[0]).toContain('/hr-form-exports?type=advance_request&subject_id=adv1&per_page=100')
  await user.keyboard('{Escape}')
  expect(screen.queryByRole('dialog')).toBeNull()
  await vi.waitFor(() => expect(document.activeElement).toBe(button))
})

test('the button waits while the typed amount is unsaved, and the hint says to save the day first', async () => {
  const user = userEvent.setup()
  render(MANAGER, { ...stored, advance_request: '300' })
  expect(screen.queryByRole('button', { name: 'نموذج السلفة' })).toBeNull()
  await user.click(screen.getByRole('button', { name: 'طلب سلفة' }))
  expect(screen.getByText('احفظ اليوم لتسجيل الطلب وإنشاء النموذج')).toBeInTheDocument()
})

test('no button without the advance PDF permission', () => {
  render(['attendance.record.own'])
  expect(screen.queryByRole('button', { name: 'نموذج السلفة' })).toBeNull()
  expect(screen.queryByRole('button', { name: /طباعة/ })).toBeNull()
})

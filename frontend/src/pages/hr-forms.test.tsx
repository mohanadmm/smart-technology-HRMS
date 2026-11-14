import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, pagination, renderWithAuth } from '@/test-utils'
import { FormApprovalsPage } from './hr-forms'

afterEach(() => vi.unstubAllGlobals())

test('the CEO can retry a failed signed export from the approval screen', async () => {
  const user = userEvent.setup()
  const retry = vi.fn(() => json(202, { data: { id: 'signed-1' } }))
  const list = vi.fn((_url: string) => json(200, {
    data: [{ id: 'form-1', employee_id: 'e1', serial_number: 1, file_name: 'experience-certificate-1-unsigned.pdf', actions: ['download', 'approve'] }],
    meta: { pagination },
  }))
  mockFetch({
    'GET /api/v1/hr-form-exports': list,
    'POST /api/v1/hr-form-exports/form-1/approve': () => json(202, { data: { id: 'signed-1' } }),
    'GET /api/v1/hr-form-exports/signed-1': () => json(200, {
      data: { serial_number: 1, status: retry.mock.calls.length ? 'ready' : 'failed' },
    }),
    'POST /api/v1/hr-form-exports/signed-1/retry': retry,
  })
  renderWithAuth(<FormApprovalsPage />, ['hr_forms.view.all', 'hr_forms.approve.all'])
  await user.click(await screen.findByRole('button', { name: 'اعتماد' }))
  expect(list.mock.calls[0]?.[0]).toContain('/hr-form-exports?awaiting_approval=1&type=experience_certificate&page=1')
  await user.click(await screen.findByRole('button', { name: 'إعادة المحاولة' }))
  expect(await screen.findByText(/مسلسل 1 · جاهز/)).toBeInTheDocument()
  expect(retry).toHaveBeenCalledOnce()
})

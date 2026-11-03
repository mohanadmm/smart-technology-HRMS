import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, renderWithAuth } from '@/test-utils'
import { UploadDialog } from './employee-documents'

afterEach(() => vi.unstubAllGlobals())

const perms = ['employee_document.view.all', 'employee_document.edit.all']
const POST = 'POST /api/v1/employees/e1/documents'
const created = () => json(201, { data: { id: 'd1', kind: 'work_stub' } })
const body = (f: ReturnType<typeof vi.fn>, i = 0) => (f.mock.calls[i] as [string, RequestInit])[1].body as FormData
const pdf = (bytes: BlobPart = '%PDF', name = 'عقد.pdf') => new File([bytes], name, { type: 'application/pdf' })
const TOO_LARGE = /حجم الملف .+ والحد الأقصى 10/

function open(preset: Parameters<typeof UploadDialog>[0]['preset'] = {}, user = userEvent.setup()) {
  const onClose = vi.fn()
  renderWithAuth(<UploadDialog empId="e1" preset={preset} onClose={onClose} />, perms, { router: false })
  return { user, onClose }
}

test('upload posts the file, kind, expiry and note as multipart', async () => {
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user, onClose } = open()
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.selectOptions(screen.getByLabelText('المستند'), 'work_stub')
  await user.type(screen.getByLabelText('تاريخ الانتهاء'), '2027-01-31')
  await user.type(screen.getByLabelText('ملاحظات'), 'نسخة موقعة')
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  const sent = body(post)
  expect([sent.get('kind'), sent.get('expires_at'), sent.get('note'), sent.has('replaces')]).toEqual(['work_stub', '2027-01-31', 'نسخة موقعة', false])
  expect((sent.get('file') as File).name).toBe('عقد.pdf')
})

test('replace prefills kind, expiry and note and sends replaces', async () => {
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const { user, onClose } = open({ replaces: { id: 'd0', kind: 'criminal_record', expires_at: '2026-12-01', note: 'قديم' } })
  expect(screen.getByLabelText('المستند')).toHaveValue('criminal_record')
  expect(screen.getByLabelText('تاريخ الانتهاء')).toHaveValue('2026-12-01')
  expect(screen.getByLabelText('ملاحظات')).toHaveValue('قديم')
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  expect([body(post).get('replaces'), body(post).get('kind')]).toEqual(['d0', 'criminal_record'])
})

test('a file over 10 MB is refused before sending', async () => {
  mockFetch({})
  const { user } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf(new Uint8Array(11 * 1024 * 1024), 'scan.pdf'))
  expect(await screen.findByText(TOO_LARGE)).toHaveTextContent('امسحه ضوئيًا بجودة أقل أو قسّمه.')
  expect(screen.getByRole('button', { name: 'رفع مستند' })).toBeDisabled()
  expect(fetch).not.toHaveBeenCalled()
})

test('HEIC and other types are refused', async () => {
  mockFetch({})
  const { user } = open({ kind: 'photos' }, userEvent.setup({ applyAccept: false }))
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'photo.heic', { type: 'image/heic' }))
  expect(await screen.findByText('هذه الصورة بصيغة HEIC. احفظها بصيغة JPG أو PDF ثم أعد المحاولة.')).toBeInTheDocument()
  await user.upload(screen.getByLabelText('الملف'), new File(['x'], 'notes.docx', { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }))
  expect(await screen.findByText('يُقبل فقط ملف PDF أو JPG أو PNG أو WEBP.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'رفع مستند' })).toBeDisabled()
  expect(fetch).not.toHaveBeenCalled()
})

test('a 413 answer shows the too-large message', async () => {
  mockFetch({ [POST]: () => new Response('<html>', { status: 413 }) })
  const { user, onClose } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  expect(await screen.findByText(TOO_LARGE)).toBeInTheDocument()
  expect(onClose).not.toHaveBeenCalled()
})

test('a network failure offers Retry', async () => {
  const post = vi.fn().mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce(created())
  vi.stubGlobal('fetch', post)
  const { user, onClose } = open({ kind: 'other' })
  await user.upload(screen.getByLabelText('الملف'), pdf())
  await user.click(screen.getByRole('button', { name: 'رفع مستند' }))
  expect(await screen.findByText('تعذّر الاتصال.')).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))
  await vi.waitFor(() => expect(onClose).toHaveBeenCalled())
  expect(post).toHaveBeenCalledTimes(2)
})

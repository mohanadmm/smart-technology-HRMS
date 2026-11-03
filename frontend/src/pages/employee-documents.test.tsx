import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, test, vi } from 'vitest'
import { json, mockFetch, renderWithAuth } from '@/test-utils'
import { Documents, UploadDialog } from './employee-documents'

afterEach(() => {
  vi.unstubAllGlobals()
  delete (navigator as { pdfViewerEnabled?: boolean }).pdfViewerEnabled // own property set by view(); jsdom has none
})

const perms = ['employee_document.view.all', 'employee_document.edit.all']
const POST = 'POST /api/v1/employees/e1/documents'
const created = () => json(201, { data: { id: 'd1', kind: 'work_stub' } })
const body = (f: ReturnType<typeof vi.fn>, i = 0) => (f.mock.calls[i] as [string, RequestInit])[1].body as FormData
const pdf = (bytes: BlobPart = '%PDF', name = 'عقد.pdf') => new File([bytes], name, { type: 'application/pdf' })
const TOO_LARGE = /حجم الملف [\d.]+ ميجابايت والحد الأقصى 10 ميجابايت\./

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

const VIEW = ['employee_document.view.all']
const doc = (kind: string, expires_at: string | null = null) => ({ id: `d-${kind}-${expires_at}`, kind, expires_at, original_name: 'scan.pdf', mime: 'application/pdf', size_bytes: 250_000, note: null, created_at: '2026-11-01T08:00:00Z', uploader_name: 'HR' })
const missingLine = (_: string, el: Element | null) => el?.tagName === 'P' && !!el.textContent?.startsWith('لم يُرفع بعد:')
const showDocs = (documents: object[], permissions = perms) => renderWithAuth(<Documents emp={{ id: 'e1', documents }} />, permissions, { router: false })

test('an empty list shows one sentence and one Upload button', () => {
  const { unmount } = showDocs([])
  expect(screen.getByText('لم يُرفع أي مستند بعد.')).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'رفع مستند' })).toHaveLength(1)
  unmount()
  showDocs([], VIEW)
  expect(screen.getByText('لم يُرفع أي مستند بعد.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'رفع مستند' })).toBeNull()
})

test('the header counts documents and the missing line lists the other kinds', async () => {
  const user = userEvent.setup()
  const { unmount } = showDocs([doc('national_id_copy')])
  expect(screen.getByRole('heading', { name: 'المستندات · 1' })).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'رفع مستند' })).toHaveLength(1)
  const line = screen.getByText(missingLine)
  expect(within(line).getAllByRole('button').map((b) => b.textContent)).toEqual(['عقد عمل', 'صحيفة الحالة الجنائية', 'المؤهل الدراسي', 'شهادة الميلاد', 'استمارة رقم 6 (التأمينات الاجتماعية)', 'صور شخصية', 'الموقف من التجنيد'])
  await user.click(within(line).getByRole('button', { name: 'صحيفة الحالة الجنائية' }))
  expect(await screen.findByLabelText('المستند')).toHaveValue('criminal_record')
  unmount()
  showDocs([doc('national_id_copy')], VIEW)
  expect(screen.queryByText(missingLine)).toBeNull()
  expect(screen.queryByRole('button', { name: 'رفع مستند' })).toBeNull()
})

test('expiry badges', () => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-11-03T10:00:00Z'))
  try {
    const badge = (expires: string) => {
      const { unmount } = showDocs([doc('work_stub', expires)])
      const text = screen.getByRole('row', { name: /عقد عمل/ }).textContent
      unmount()
      return text
    }
    expect(badge('2026-11-02')).toContain('منتهي')
    expect(badge('2026-11-03')).toContain('ينتهي اليوم')
    expect(badge('2026-12-03')).toContain('ينتهي خلال 30 يومًا')
    expect(badge('2026-12-04')).not.toMatch(/ينتهي|منتهي/)
  } finally {
    vi.useRealTimers()
  }
})

test('without the view permission the kind is plain text', () => {
  const { unmount } = showDocs([{ id: 'd1', kind: 'work_stub', expires_at: null }], [])
  expect(screen.getByText('عقد عمل')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'عقد عمل' })).toBeNull()
  expect(screen.queryByText('scan.pdf')).toBeNull()
  unmount()
  showDocs([doc('work_stub')], VIEW)
  expect(screen.getByRole('button', { name: 'عقد عمل' })).toBeInTheDocument()
  expect(screen.getByText('scan.pdf')).toBeInTheDocument()
})

const DOC_PATH = '/api/v1/employees/e1/documents/d1'
const d1 = { ...doc('criminal_record', '2027-01-31'), id: 'd1', note: 'قديم' }
async function rowAction(item: string, user = userEvent.setup()) {
  showDocs([d1])
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: item }))
  return user
}

test('edit details patches kind, expiry and note', async () => {
  const patch = vi.fn(() => json(200, { data: d1 }))
  mockFetch({ [`PATCH ${DOC_PATH}`]: patch })
  const user = await rowAction('تعديل البيانات')
  const dialog = await screen.findByRole('dialog', { name: 'تعديل البيانات' })
  expect(within(dialog).getByLabelText('المستند')).toHaveValue('criminal_record')
  await user.clear(within(dialog).getByLabelText('تاريخ الانتهاء'))
  await user.clear(within(dialog).getByLabelText('ملاحظات'))
  await user.type(within(dialog).getByLabelText('ملاحظات'), 'نسخة جديدة')
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await vi.waitFor(() => expect(patch).toHaveBeenCalled())
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ kind: 'criminal_record', expires_at: null, note: 'نسخة جديدة' })
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('delete asks first and sends DELETE', async () => {
  const del = vi.fn(() => new Response(null, { status: 204 }))
  mockFetch({ [`DELETE ${DOC_PATH}`]: del })
  const user = await rowAction('حذف')
  const dialog = await screen.findByRole('dialog')
  expect(dialog).toHaveTextContent('حذف هذا المستند؟ سيختفي من القائمة ويبقى سجله في سجل التدقيق.')
  expect(del).not.toHaveBeenCalled()
  expect(within(dialog).getByRole('button', { name: 'حذف' })).toHaveClass('bg-destructive')
  await user.click(within(dialog).getByRole('button', { name: 'حذف' }))
  await vi.waitFor(() => expect(del).toHaveBeenCalledTimes(1))
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
})

test('replace sends replaces with the prefilled kind', async () => {
  const post = vi.fn(created)
  mockFetch({ [POST]: post })
  const user = await rowAction('استبدال الملف')
  const dialog = await screen.findByRole('dialog', { name: 'استبدال الملف' })
  expect(within(dialog).getByLabelText('المستند')).toHaveValue('criminal_record')
  await user.upload(within(dialog).getByLabelText('الملف'), pdf())
  await user.click(within(dialog).getByRole('button', { name: 'رفع مستند' }))
  await vi.waitFor(() => expect(post).toHaveBeenCalled())
  expect([body(post).get('replaces'), body(post).get('kind')]).toEqual(['d1', 'criminal_record'])
})

test('no row actions without employee_document.edit', () => {
  showDocs([d1], VIEW)
  expect(screen.queryByRole('button', { name: 'الإجراءات' })).toBeNull()
})

test('edit details without the view permission leaves the unseen note out', async () => {
  const patch = vi.fn(() => json(200, { data: d1 }))
  mockFetch({ [`PATCH ${DOC_PATH}`]: patch })
  const user = userEvent.setup()
  showDocs([{ id: 'd1', kind: 'work_stub', expires_at: null }], ['employee_document.edit.all'])
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل البيانات' }))
  const dialog = await screen.findByRole('dialog', { name: 'تعديل البيانات' })
  expect(within(dialog).queryByLabelText('ملاحظات')).toBeNull()
  await user.click(within(dialog).getByRole('button', { name: 'حفظ' }))
  await vi.waitFor(() => expect(patch).toHaveBeenCalled())
  expect(JSON.parse((patch.mock.calls[0] as unknown as [string, RequestInit])[1].body as string)).toEqual({ kind: 'work_stub', expires_at: null })
})

const FILE = `GET ${DOC_PATH}/file`
const html = () => new Response('x', { headers: { 'Content-Type': 'text/html' } })
async function view(over: object = {}, file: () => Response = html, pdfViewer = true) {
  Object.defineProperty(navigator, 'pdfViewerEnabled', { value: pdfViewer, configurable: true })
  // jsdom has neither; stubbed so afterEach puts the real URL back
  const urls = { create: vi.fn(() => 'blob:doc-1'), revoke: vi.fn() }
  vi.stubGlobal('URL', class extends URL { static createObjectURL = urls.create; static revokeObjectURL = urls.revoke })
  const get = vi.fn(file)
  mockFetch({ [FILE]: get })
  const user = userEvent.setup()
  showDocs([{ ...d1, ...over }], VIEW)
  await user.click(screen.getByRole('button', { name: 'صحيفة الحالة الجنائية' }))
  const dialog = await screen.findByRole('dialog', { name: 'صحيفة الحالة الجنائية' })
  const blobType = () => (urls.create.mock.calls[0] as unknown as [Blob])[0].type
  return { user, dialog, urls, get, blobType }
}

test('an image opens as an img built from the stored mime', async () => {
  const { dialog, blobType } = await view({ mime: 'image/png', original_name: 'id.png' })
  const img = await within(dialog).findByRole('img')
  expect(img).toHaveAttribute('src', 'blob:doc-1')
  expect(img.getAttribute('alt')).toContain('صحيفة الحالة الجنائية')
  expect(img.getAttribute('alt')).toContain('id.png')
  expect(blobType()).toBe('image/png')
})

test('a PDF opens in an iframe when the browser shows PDFs', async () => {
  const { dialog, blobType } = await view()
  const frame = await vi.waitFor(() => dialog.querySelector('iframe')!)
  expect(frame).toHaveAttribute('title', 'صحيفة الحالة الجنائية')
  expect(frame).toHaveAttribute('src', 'blob:doc-1')
  expect(blobType()).toBe('application/pdf')
  expect(within(dialog).queryByRole('img')).toBeNull()
})

test('a PDF without an in-page viewer gets an Open link', async () => {
  const { dialog } = await view({}, html, false)
  expect(await within(dialog).findByRole('link', { name: 'فتح' })).toHaveAttribute('href', 'blob:doc-1')
  expect(dialog.querySelector('iframe')).toBeNull()
})

test('an unknown stored mime never renders inline', async () => {
  const { dialog, blobType } = await view({ mime: 'text/html' })
  await within(dialog).findByRole('link', { name: 'تنزيل' })
  expect(blobType()).toBe('application/octet-stream')
  expect(within(dialog).queryByRole('img')).toBeNull()
  expect(dialog.querySelector('iframe')).toBeNull()
})

test('a stored mime named like an object key is not allow-listed', async () => {
  const { dialog, blobType } = await view({ mime: 'constructor' })
  await within(dialog).findByRole('link', { name: 'تنزيل' })
  expect(blobType()).toBe('application/octet-stream')
  expect(within(dialog).queryByRole('img')).toBeNull()
  expect(dialog.querySelector('iframe')).toBeNull()
})

test('the download name takes its extension from the stored mime', async () => {
  const { dialog } = await view({ original_name: 'x.html' })
  expect(await within(dialog).findByRole('link', { name: 'تنزيل' })).toHaveAttribute('download', 'x.pdf')
})

test('a name with no base downloads as document', async () => {
  const { dialog } = await view({ original_name: '.pdf', mime: 'image/jpeg' })
  expect(await within(dialog).findByRole('link', { name: 'تنزيل' })).toHaveAttribute('download', 'document.jpg')
})

test('closing revokes the URL', async () => {
  const { user, dialog, urls } = await view()
  await within(dialog).findByRole('link', { name: 'تنزيل' })
  await user.click(within(dialog).getByRole('button', { name: 'إغلاق' }))
  await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
  expect(urls.revoke).toHaveBeenCalledWith('blob:doc-1')
})

test('a failed fetch shows Retry', async () => {
  const { user, dialog, get } = await view({}, () => json(500, { error: { code: 'x', message: 'x' } }))
  expect(await within(dialog).findByText('تعذّر تحميل الملف.')).toBeInTheDocument()
  get.mockImplementation(html)
  await user.click(within(dialog).getByRole('button', { name: 'إعادة المحاولة' }))
  await vi.waitFor(() => expect(dialog.querySelector('iframe')).not.toBeNull())
  expect(get).toHaveBeenCalledTimes(2)
})

test('the viewer says who uploaded the file and when', async () => {
  const { dialog } = await view()
  expect(dialog).toHaveTextContent(/scan\.pdf.*رُفع في .*2026.* بواسطة HR/)
})

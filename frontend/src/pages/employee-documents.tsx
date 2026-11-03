import { FileText, Upload } from 'lucide-react'
import { Fragment, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { DataList } from '@/components/common/DataList'
import { RowActions } from '@/components/common/RowActions'
import { cairoToday, ErrorBanner, Field, FormDialog, intlLocale, type Row, SelectField, TextField, toErrs, useForm } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Card, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ApiError } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

export const DOC_KINDS = ['national_id_copy', 'work_stub', 'criminal_record', 'qualification', 'birth_certificate', 'insurance_form_6', 'photos', 'military_status', 'other']
/** The only types the app accepts, uploads and renders (the server checks the bytes against the same list). */
export const ACCEPT = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
const MAX_BYTES = 10 * 1024 * 1024 // EmployeeDocumentController::MAX_KB
const SHRINK_OVER = 1.5 * 1024 * 1024
const EXPIRY_SOON_DAYS = 30
const isHeic = (f: File) => /^image\/hei[cf]$/.test(f.type) || /\.hei[cf]$/i.test(f.name)

/** A big phone photo as a JPEG of at most 2400 px on its long edge (transparency on white); the original when that is not smaller or anything fails. */
async function shrinkPhoto(file: File): Promise<File> {
  try {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' })
    const scale = Math.min(1, 2400 / Math.max(bmp.width, bmp.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(bmp.width * scale)
    canvas.height = Math.round(bmp.height * scale)
    const ctx = canvas.getContext('2d')!
    ctx.fillStyle = '#fff' // JPEG has no alpha: a PNG's transparent parts would turn black
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height)
    bmp.close()
    const blob = await new Promise<Blob | null>((done) => canvas.toBlob(done, 'image/jpeg', 0.85))
    return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.[^.]*$/, '') + '.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

type Preset = { kind?: string; replaces?: Row } | null
/** A document row from `GET /employees/{id}`: only id, kind and expiry without `employee_document.view`. */
type Doc = { id: string; kind: string; expires_at: string | null } & Partial<{ original_name: string; mime: string; size_bytes: number; note: string | null; created_at: string; uploader_name: string | null }>

/** The employee's documents (API order: kind, then newest); editors upload, and see which kinds are still missing. */
export function Documents({ emp }: { emp: Row }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [preset, setPreset] = useState<Preset>(null)
  const [dlg, setDlg] = useState<{ type: 'edit' | 'delete'; doc: Doc } | null>(null)
  const send = useSave()
  const docs: Doc[] = emp.documents
  const viewer = can('employee_document.view')
  const editor = can('employee_document.edit')
  const lng = intlLocale(i18n.language)
  const date = new Intl.DateTimeFormat(lng, { dateStyle: 'medium', timeZone: 'UTC' })
  const mb = new Intl.NumberFormat(lng, { maximumSignificantDigits: 2 })
  const today = Date.parse(cairoToday())
  const kindName = (d: Doc) => t(`enum.document.${d.kind}`)
  const missing = DOC_KINDS.filter((k) => k !== 'other' && !docs.some((d) => d.kind === k))
  const upload = (
    <Button type="button" onClick={() => setPreset({})}>
      <Upload aria-hidden /> {t('document.upload')}
    </Button>
  )

  function expiry(d: Doc) {
    if (!d.expires_at) return '—'
    const days = (Date.parse(d.expires_at) - today) / 86_400_000
    const badge =
      days < 0 ? <Badge variant="danger">{t('document.expired')}</Badge>
      : days === 0 ? <Badge variant="warning">{t('document.expiresToday')}</Badge>
      : days <= EXPIRY_SOON_DAYS && <Badge variant="warning">{t('document.expiresIn', { count: days })}</Badge>
    return (
      <span className="inline-flex flex-wrap items-center justify-end gap-2">
        {date.format(new Date(d.expires_at))}
        {badge}
      </span>
    )
  }

  return (
    <Card className="overflow-hidden">
      <CardHeader>
        <CardTitle>{t('document.title', { count: docs.length })}</CardTitle>
        {docs.length > 0 && editor && upload}
      </CardHeader>
      <DataList
        label={t('document.title', { count: docs.length })}
        rows={docs}
        rowKey={(d) => d.id}
        title={{
          header: t('document.kind'),
          // Task 11 opens the viewer from this button
          cell: (d) => (viewer ? <button type="button" className="text-start font-medium hover:underline">{kindName(d)}</button> : kindName(d)),
        }}
        fields={[
          ...(viewer
            ? [{ header: t('document.file'), cell: (d: Doc) => (
                <span className="inline-flex min-w-0 items-baseline gap-2">
                  <bdi className="block max-w-56 truncate" title={d.original_name}>{d.original_name}</bdi>
                  <span className="shrink-0 text-muted-foreground">{t('document.size', { size: mb.format((d.size_bytes ?? 0) / 1024 / 1024) })}</span>
                </span>
              ) }]
            : []),
          { header: t('document.expires_at'), cell: expiry },
        ]}
        actions={editor ? (d) => (
          <RowActions
            items={[
              { label: t('document.replace'), onSelect: () => setPreset({ replaces: d }) },
              { label: t('document.editDetails'), onSelect: () => setDlg({ type: 'edit', doc: d }) },
              { label: t('document.delete'), onSelect: () => setDlg({ type: 'delete', doc: d }), destructive: true },
            ]}
          />
        ) : undefined}
        empty={{ icon: FileText, title: t('document.empty'), action: editor ? upload : undefined }}
      />
      {docs.length > 0 && editor && missing.length > 0 && (
        <p className="border-t px-5 py-3 text-sm text-muted-foreground">
          {t('document.missing')}{' '}
          {new Intl.ListFormat(lng, { type: 'conjunction' }).formatToParts(missing).map((part, i) =>
            part.type === 'literal' ? (
              <Fragment key={i}>{part.value}</Fragment>
            ) : (
              // a link inside a sentence: exempt from the 44 px target (WCAG 2.5.8)
              <button key={i} type="button" className="text-primary underline-offset-4 hover:underline" onClick={() => setPreset({ kind: part.value })}>
                {t(`enum.document.${part.value}`)}
              </button>
            ),
          )}
        </p>
      )}
      <UploadDialog empId={emp.id} preset={preset} onClose={() => setPreset(null)} />
      {dlg?.type === 'edit' && (
        <FormDialog
          title={t('document.editDetails')}
          open
          onOpenChange={(o) => !o && setDlg(null)}
          init={{ kind: dlg.doc.kind, expires_at: dlg.doc.expires_at ?? '', note: dlg.doc.note ?? '' }}
          submit={(f) => send(`/employees/${emp.id}/documents/${dlg.doc.id}`, { method: 'PATCH', body: { kind: f.kind, expires_at: f.expires_at || null, note: f.note || null } })}
          onDone={() => setDlg(null)}
          shown={['kind', 'expires_at', 'note']}
        >
          {(p) => (
            <>
              <SelectField name="kind" label={t('document.kind')} options={DOC_KINDS.map((k) => [k, t(`enum.document.${k}`)])} {...p} />
              <TextField name="expires_at" label={t('document.expires_at')} type="date" {...p} />
              <TextField name="note" label={t('document.note')} enterKeyHint="done" {...p} />
            </>
          )}
        </FormDialog>
      )}
      {dlg?.type === 'delete' && (
        <FormDialog
          title={t('document.delete')}
          open
          onOpenChange={(o) => !o && setDlg(null)}
          init={{}}
          submit={() => send(`/employees/${emp.id}/documents/${dlg.doc.id}`, { method: 'DELETE' })}
          onDone={() => setDlg(null)}
          shown={[]}
          footer="document.delete"
          description={t('document.deleteConfirm')}
        >
          {() => null}
        </FormDialog>
      )}
    </Card>
  )
}

/** Uploads one file for an employee; open while `preset` is not null. `replaces` prefills kind, expiry and note and sends the old document's id. */
export function UploadDialog({ empId, preset, onClose }: { empId: string; preset: Preset; onClose: () => void }) {
  const { t } = useTranslation()
  return (
    <Dialog open={preset !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>{t(preset?.replaces ? 'document.replace' : 'document.upload')}</DialogTitle>
        </DialogHeader>
        {preset && <UploadForm empId={empId} preset={preset} onClose={onClose} />}
      </DialogContent>
    </Dialog>
  )
}

/** The dialog's body: mounted only while open, so every open starts empty (or from the preset). */
function UploadForm({ empId, preset, onClose }: { empId: string; preset: NonNullable<Preset>; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const send = useSave()
  const old = preset.replaces
  const { form, set } = useForm({ kind: old?.kind ?? preset.kind ?? '', expires_at: old?.expires_at ?? '', note: old?.note ?? '' })
  const [file, setFile] = useState<File>()
  const [fileErr, setFileErr] = useState<string>()
  const [err, setErr] = useState<unknown>()
  const [network, setNetwork] = useState(false)
  const [busy, setBusy] = useState(false)
  const [preview, setPreview] = useState<string>()

  useEffect(() => {
    if (!file?.type.startsWith('image/')) return setPreview(undefined)
    const url = URL.createObjectURL(file)
    setPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [file])

  const mb = new Intl.NumberFormat(intlLocale(i18n.language), { maximumFractionDigits: 1 })
  const tooLarge = (f: File) =>
    [t('document.tooLarge', { size: mb.format(f.size / 1024 / 1024), max: mb.format(MAX_BYTES / 1024 / 1024) }), f.type === 'application/pdf' && t('document.tooLargePdf')].filter(Boolean).join(' ')

  // check the type, then shrink a big photo, then check the size: the limit must not refuse the photos the shrink is for
  async function pick(picked: File | undefined) {
    setFile(undefined)
    setErr(undefined)
    setNetwork(false)
    if (!picked) return setFileErr(undefined)
    if (isHeic(picked)) return setFileErr(t('document.heic'))
    if (!ACCEPT.includes(picked.type)) return setFileErr(t('document.badType'))
    setBusy(true)
    const f = picked.type.startsWith('image/') && picked.size > SHRINK_OVER ? await shrinkPhoto(picked) : picked
    setBusy(false)
    if (f.size > MAX_BYTES) return setFileErr(tooLarge(f))
    setFileErr(undefined)
    setFile(f)
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!file || busy) return
    const body = new FormData()
    body.set('file', file)
    body.set('kind', form.kind)
    if (form.expires_at) body.set('expires_at', form.expires_at)
    if (form.note) body.set('note', form.note)
    if (old) body.set('replaces', old.id)
    setBusy(true)
    setErr(undefined)
    setNetwork(false)
    try {
      await send(`/employees/${empId}/documents`, { body })
      onClose()
    } catch (e) {
      if (e instanceof ApiError && e.status === 413) setFileErr(tooLarge(file))
      else if (e instanceof ApiError) setErr(e)
      else setNetwork(true) // no answer: the upload may still have landed, so a retry can add a duplicate or 404 on a replace
    } finally {
      setBusy(false)
    }
  }

  const errs = { ...toErrs(err), ...(fileErr && { file: fileErr }) }
  return (
    <form className="contents" onSubmit={submit}>
      <DialogBody className="grid gap-4">
        <ErrorBanner err={network ? new Error(t('document.network')) : err} shown={['file', 'kind', 'expires_at', 'note']} />
        <Field name="file" label={t('document.file')} errs={errs}>
          {(p) => (
            // the real input stays (named by the field label) but hidden: its own "Choose File" text is in the browser's language
            <div className="flex min-w-0 items-center gap-3">
              <input {...p} type="file" accept={ACCEPT.join(',')} className="peer sr-only" disabled={busy} onChange={(e) => void pick(e.target.files?.[0])} />
              <label htmlFor={p.id} aria-hidden className={cn(buttonVariants({ variant: 'outline' }), 'shrink-0 cursor-pointer peer-focus-visible:outline-2 peer-focus-visible:outline-solid peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring peer-disabled:pointer-events-none peer-disabled:opacity-50 peer-aria-invalid:border-destructive')}>
                <Upload aria-hidden /> {t('document.choose')}
              </label>
              <bdi className="min-w-0 truncate text-sm text-muted-foreground" title={file?.name}>{file?.name ?? t('document.noFile')}</bdi>
            </div>
          )}
        </Field>
        {preview && <img src={preview} alt="" className="max-h-48 w-full rounded-lg border object-contain" />}
        <SelectField name="kind" label={t('document.kind')} options={DOC_KINDS.map((k) => [k, t(`enum.document.${k}`)])} blank values={form} set={set} errs={errs} />
        <TextField name="expires_at" label={t('document.expires_at')} type="date" values={form} set={set} errs={errs} />
        <TextField name="note" label={t('document.note')} enterKeyHint="done" values={form} set={set} errs={errs} />
      </DialogBody>
      <DialogFooter>
        <Button type="submit" disabled={busy || !file}>
          {t(network ? 'document.retry' : 'document.upload')}
        </Button>
      </DialogFooter>
    </form>
  )
}

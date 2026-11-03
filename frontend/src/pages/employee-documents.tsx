import { Upload } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ErrorBanner, Field, intlLocale, type Row, SelectField, TextField, toErrs, useForm } from '@/components/form'
import { Button, buttonVariants } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ApiError } from '@/lib/api'
import { useSave } from '@/lib/query'
import { cn } from '@/lib/utils'

export const DOC_KINDS = ['national_id_copy', 'work_stub', 'criminal_record', 'qualification', 'birth_certificate', 'insurance_form_6', 'photos', 'military_status', 'other']
/** The only types the app accepts, uploads and renders (the server checks the bytes against the same list). */
export const ACCEPT = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
const MAX_BYTES = 10 * 1024 * 1024 // EmployeeDocumentController::MAX_KB
const SHRINK_OVER = 1.5 * 1024 * 1024
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

import { type ReactNode, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, buttonVariants } from '@/components/ui/button'
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/misc'
import { apiBlob } from '@/lib/api'
import { cn } from '@/lib/utils'

type Props = { path: string; type: string; name: string; title: string; alt?: string; description?: ReactNode; onClose: () => void }

/**
 * Shows one file in the app, open while mounted: photos as an img, PDFs in an iframe where the browser can, otherwise an Open link; always a Download link.
 * `path` is the API route of the bytes; `type` an allow-listed mime the Blob is built with (never the response's, so a file can't turn itself into HTML); `name` the download file name.
 */
export function FileViewer({ path, type, name, title, alt, description, onClose }: Props) {
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      {/* full screen under sm, below the notch (the close button sits at the top) */}
      <DialogContent className="top-[env(safe-area-inset-top)] max-h-none rounded-none sm:h-[85vh] sm:max-h-none sm:max-w-4xl" {...(!description && { 'aria-describedby': undefined })}>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description && <DialogDescription className="[overflow-wrap:anywhere]">{description}</DialogDescription>}
        </DialogHeader>
        <FileBody key={path} path={path} type={type} name={name} title={title} alt={alt ?? title} />
      </DialogContent>
    </Dialog>
  )
}

/** The dialog's body, keyed by `path`, so the object URL lives exactly as long as its file shows. */
function FileBody({ path, type, name, title, alt }: { path: string; type: string; name: string; title: string; alt: string }) {
  const { t } = useTranslation()
  const [url, setUrl] = useState<string>()
  const [failed, setFailed] = useState(false)
  const [tries, setTries] = useState(0)

  useEffect(() => {
    let made: string | undefined
    let live = true
    setFailed(false)
    apiBlob(path).then(
      (b) => live && setUrl((made = URL.createObjectURL(new Blob([b], { type })))),
      () => live && setFailed(true),
    )
    return () => {
      live = false
      if (made) URL.revokeObjectURL(made)
    }
  }, [path, type, tries])

  const center = 'm-auto grid justify-items-center gap-3 text-center'
  return (
    <>
      <DialogBody className="flex min-h-0 flex-col p-2 sm:p-4">
        {failed ? (
          <div className={center}>
            <p className="text-sm text-muted-foreground">{t('document.loadFailed')}</p>
            <Button type="button" variant="outline" onClick={() => setTries((n) => n + 1)}>{t('document.retry')}</Button>
          </div>
        ) : !url ? (
          <Skeleton aria-busy="true" aria-label={t('auth.loading')} className="min-h-64 flex-1" />
        ) : type.startsWith('image/') ? (
          <img src={url} alt={alt} className="min-h-0 w-full flex-1 object-contain" />
        ) : type === 'application/pdf' && navigator.pdfViewerEnabled ? (
          // no sandbox: Chromium refuses to show a PDF inside one
          <iframe src={url} title={title} className="min-h-0 w-full flex-1 rounded-lg border" />
        ) : type === 'application/pdf' ? (
          // a link the user taps, not window.open: iOS blocks a window opened after an await
          <a href={url} target="_blank" rel="noopener" className={cn(buttonVariants(), center)}>{t('document.open')}</a>
        ) : null}
      </DialogBody>
      {url && (
        <DialogFooter>
          {/* iOS Safari may draw only page 1 of a PDF inside the iframe; Open shows the whole file */}
          {type === 'application/pdf' && navigator.pdfViewerEnabled && <a href={url} target="_blank" rel="noopener" className={buttonVariants({ variant: 'secondary' })}>{t('document.open')}</a>}
          <a href={url} download={name} className={buttonVariants({ variant: 'secondary' })}>{t('document.download')}</a>
        </DialogFooter>
      )}
    </>
  )
}

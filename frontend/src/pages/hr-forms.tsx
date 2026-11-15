import { ScrollText } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigationType, useSearchParams } from 'react-router-dom'
import { DataList } from '@/components/common/DataList'
import { FilterSelect, SearchInput } from '@/components/common/Filters'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { ErrorBanner, localName, useCairoTime } from '@/components/form'
import { NoPayBadge, PDF_TYPES, PdfRowActions, STATUS, type PdfExport, type PdfType } from '@/components/PdfPanel'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { useDebounced } from '@/hooks/useDebounced'
import { apiBlob } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { useApi, useList, useSave } from '@/lib/query'
import { cn, FOCUS } from '@/lib/utils'

const exportsPath = '/hr-form-exports'
const signaturePath = '/me/hr-signature'
const PAGE = 20
const KEYS = ['type', 'stage', 'status', 'awaiting_approval', 'q', 'page'] as const
type Filters = Record<(typeof KEYS)[number], string>
// The table's cells keep one line; these may wrap between words, never inside one, so the row's buttons keep their room.
const WRAP = 'whitespace-normal wrap-normal'
type Signature = { signature: { version: number } | null }

/** `/hr-forms`: every issued form the caller may read, newest first (the server's order). The filters live in the URL, so Back from an employee keeps the list. */
export function IssuedFormsPage() {
  // A link or Back/forward to this page starts it again from the URL; the page's own writes replace the entry and keep it.
  const { key } = useLocation()
  const own = useNavigationType() === 'REPLACE'
  const [entry, setEntry] = useState(key)
  if (!own && key !== entry) setEntry(key)
  return <IssuedForms key={entry} />
}

function IssuedForms() {
  const { t, i18n } = useTranslation()
  const time = useCairoTime({ dateStyle: 'long', timeStyle: 'short' })
  const [params, setParams] = useSearchParams()
  // The controls and the request read this state and the URL follows it: the router writes the URL in a transition (a control
  // bound to it lags) and builds each write from the last render's params (two writes in one tick keep only the second).
  const [filters, setFilters] = useState(() => Object.fromEntries(KEYS.map((k) => [k, params.get(k) ?? ''])) as Filters)
  const url = new URLSearchParams(Object.entries(filters).filter(([, v]) => v)).toString()
  useEffect(() => {
    if (url !== params.toString()) setParams(url, { replace: true })
  }, [url, params, setParams])
  const set = (patch: Partial<Filters>) => setFilters((f) => ({ ...f, ...patch }))
  const filter = (patch: Partial<Filters>) => set({ ...patch, page: '' })
  const { q, page: pageParam, ...chosen } = filters
  const search = useDebounced(q, 250)
  const page = Number(pageParam) || 1
  const qs = new URLSearchParams(Object.entries({ page: String(page), per_page: String(PAGE), ...chosen, q: search }).filter(([, v]) => v))
  const { rows, total, err, loading, reload } = useList(`${exportsPath}?${qs}`)
  const polling = rows.some((r) => r.status === 'pending')
  useEffect(() => {
    if (!polling) return
    const timer = window.setInterval(reload, 3000)
    return () => window.clearInterval(timer)
  }, [polling, reload])

  return (
    <div className="space-y-6">
      <PageHeader title={t('hrForms.issued')} />
      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center gap-3 border-b p-4">
          <SearchInput value={q} onChange={(v) => filter({ q: v })} placeholder={t('hrForms.search')} maxLength={100} className="sm:w-full sm:max-w-md" />
          <FilterSelect value={chosen.type} onChange={(v) => filter({ type: v })} label={t('hrForms.form')} allLabel={t('hrForms.allForms')} options={(Object.keys(PDF_TYPES) as PdfType[]).map((k) => [k, t(PDF_TYPES[k].title)])} />
          <FilterSelect value={chosen.stage} onChange={(v) => filter({ stage: v })} label={t('hrForms.stage')} allLabel={t('hrForms.allStages')} options={['unsigned', 'signed'].map((s) => [s, t(`hrForms.${s}`)])} />
          <FilterSelect value={chosen.status} onChange={(v) => filter({ status: v })} label={t('common.status')} allLabel={t('hrForms.allStatuses')} options={Object.keys(STATUS).map((s) => [s, t(`hrForms.${s}`)])} />
          <label className="flex min-h-tap items-center gap-3 text-sm font-medium">
            <input type="checkbox" className={cn(FOCUS, 'size-4 accent-primary')} checked={!!chosen.awaiting_approval} onChange={(e) => filter({ awaiting_approval: e.target.checked ? '1' : '' })} />
            {t('hrForms.awaitingMine')}
          </label>
          {(q || Object.values(chosen).some(Boolean)) && (
            <Button variant="ghost" size="sm" onClick={() => filter({ q: '', type: '', stage: '', status: '', awaiting_approval: '' })} className="lg:ms-auto">
              {t('common.clearFilters')}
            </Button>
          )}
        </div>
        <DataList<PdfExport>
          label={t('hrForms.issued')}
          rows={rows as PdfExport[]}
          rowKey={(r) => r.id}
          loading={loading}
          error={err}
          empty={{ icon: ScrollText, title: t('hrForms.none') }}
          page={{ page, pageSize: PAGE, total, onPageChange: (p) => set({ page: p > 1 ? String(p) : '' }) }}
          title={{ header: t('hrForms.serialNumber'), cell: (r) => t('hrForms.serial', { serial: r.serial_number }) }}
          fields={[
            { header: t('hrForms.form'), cell: (r) => <span className={cn('inline-flex flex-wrap items-center gap-2', WRAP)}>{t(PDF_TYPES[r.type].title)}<NoPayBadge row={r} /></span> },
            {
              header: t('employee.employee'),
              cell: (r) => r.employee ? (
                <span className="inline-flex flex-wrap items-center gap-x-2">
                  <Link to={`/employees/${r.employee.id}`} className={cn('inline-flex min-h-tap items-center rounded font-medium text-primary hover:underline', WRAP, FOCUS)}>{localName(r.employee, i18n.language)}</Link>
                  <bdi dir="ltr" className="font-mono text-xs text-muted-foreground">{r.employee.code}</bdi>
                </span>
              ) : '—',
            },
            // the table shows the stage and the issue time from 1280 px only (with the stage at 1024 a long English row
            // scrolls), so a narrower table keeps room for the buttons; cards show both
            { header: t('hrForms.stage'), tableFrom: 'xl', cell: (r) => (r.stage === 'signed' ? <Badge variant="success">{t('hrForms.signed')}</Badge> : <span className="text-muted-foreground">{t('hrForms.unsigned')}</span>) },
            { header: t('common.status'), cell: (r) => <StatusBadge variant={STATUS[r.status]} label={t(`hrForms.${r.status}`)} /> },
            { header: t('hrForms.issuedAt'), tableFrom: 'xl', cell: (r) => <span className={WRAP}>{time(r.created_at)}</span> },
            // a field, not the actions slot: on a phone card the buttons need the full width. One line from 1440 px, where the
            // widest English row still fits; narrower, they wrap rather than push the table sideways.
            { header: t('common.actions'), cell: (r) => (r.actions.length ? <PdfRowActions row={r} onChanged={reload} className="min-[1440px]:flex-nowrap" /> : '—') },
          ]}
        />
      </Card>
    </div>
  )
}

export function SignaturePage() {
  const { t } = useTranslation()
  const { me } = useAuth()
  const { data, err } = useApi<Signature>(me?.view_as ? null : signaturePath)
  const save = useSave()
  const [error, setError] = useState<Error>()
  const [busy, setBusy] = useState(false)
  const [image, setImage] = useState('')
  const version = data?.signature?.version
  useEffect(() => {
    if (!version || me?.view_as) return
    let active = true
    let url = ''
    void apiBlob(`${signaturePath}/file`).then((blob) => {
      if (!active) return
      url = URL.createObjectURL(blob)
      setImage(url)
    }).catch((err: Error) => { if (active) setError(err) })
    return () => { active = false; if (url) URL.revokeObjectURL(url) }
  }, [version, me?.view_as])

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    setBusy(true)
    setError(undefined)
    try {
      await save(signaturePath, { body: new FormData(form) })
      form.reset()
    } catch (err) {
      setError(err as Error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-xl font-semibold">{t('hrForms.signature')}</h1>
      {me?.view_as ? <p>{t('hrForms.viewAsBlocked')}</p> : (
        <>
          <ErrorBanner shown={[]} err={error ?? err} />
          {version && image && <img src={image} alt={t('hrForms.signature')} className="h-28 max-w-full rounded border bg-white p-2 object-contain" />}
          <form onSubmit={upload} className="flex flex-wrap items-end gap-3">
            <label className="space-y-1">
              <span>{t('hrForms.signatureFile')}</span>
              <Input type="file" name="file" accept="image/png,image/jpeg" required />
            </label>
            <Button disabled={busy}>{t('hrForms.upload')}</Button>
          </form>
        </>
      )}
    </section>
  )
}

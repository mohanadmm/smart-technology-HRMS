import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'

/** `disabled` applies to an onClick action only; a link cannot be disabled. */
export type HeaderAction = { label: string; icon?: LucideIcon; disabled?: boolean } & (
  | { onClick: () => void; to?: never }
  | { to: string; onClick?: never }
)
type Current = {
  /** The screen's one main action: always a solid default Button, full width below 640 px (rules 5, 6). */
  action?: HeaderAction
  /** At most two alternatives, rendered as ghost buttons before the main one. */
  secondary?: [HeaderAction] | [HeaderAction, HeaderAction]
  actions?: never
}
/** @deprecated Pass `action`. Kept only until employees.tsx moves (hand-over item 3); delete in that PR. */
type Legacy = { actions: ReactNode; action?: never; secondary?: never }
export type PageHeaderProps = {
  title: string
  /** One plain line under the title. Put the explicit period or "as of" date here (rule 13). */
  description?: ReactNode
} & (Current | Legacy)

/** A dialog opened from the header is a controlled FormDialog (`Controlled` in form.tsx), as sites.tsx does for rows. */
function HeaderButton({ a, main }: { a: HeaderAction; main?: boolean }) {
  const body = (
    <>
      {a.icon && <a.icon aria-hidden />}
      {a.label}
    </>
  )
  const look = main ? ({ className: 'max-sm:w-full' } as const) : ({ variant: 'ghost' } as const)
  return a.to !== undefined ? (
    <Button asChild {...look}>
      <Link to={a.to}>{body}</Link>
    </Button>
  ) : (
    <Button type="button" disabled={a.disabled} onClick={a.onClick} {...look}>
      {body}
    </Button>
  )
}

export function PageHeader({ title, description, action, secondary, actions }: PageHeaderProps) {
  const buttons = (action || secondary) && (
    <div className="flex flex-wrap items-center gap-2">
      {secondary?.map((a) => <HeaderButton key={a.label} a={a} />)}
      {action && <HeaderButton a={action} main />}
    </div>
  )
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        <h1 className="text-page-title text-balance">{title}</h1>
        {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : buttons}
    </div>
  )
}

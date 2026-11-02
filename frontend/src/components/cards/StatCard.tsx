import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

/** @deprecated → StatRow (UI-2). Every tone now draws the same neutral tile (rule 9); the names stay until employees.tsx moves. */
export type Tone = 'indigo' | 'emerald' | 'amber' | 'sky' | 'rose' | 'violet' | 'slate'

/** @deprecated → StatRow (UI-2). Delete with `Tone` once employees.tsx stops importing it. */
export function MiniStat({ label, value, icon: Icon }: { label: string; value: ReactNode; tone?: Tone; icon?: LucideIcon }) {
  return (
    <div role="group" aria-label={label} className="flex items-center gap-3 rounded-xl border bg-card p-3.5">
      {Icon && (
        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
          <Icon className="size-4" />
        </div>
      )}
      <div className="min-w-0">
        <div className="text-lg leading-tight font-semibold">{value}</div>
        <div className="truncate text-xs text-muted-foreground">{label}</div>
      </div>
    </div>
  )
}

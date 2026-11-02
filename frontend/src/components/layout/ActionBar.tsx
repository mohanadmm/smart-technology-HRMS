import type { ReactNode } from 'react'

/**
 * A bar stuck to the bottom of the screen (rule 22): bulk actions, a long form's Save. Children are the bar's content in
 * reading order, e.g. a count, Clear, the actions, a button that opens a dialog.
 *
 * Render it as a sibling AFTER the list's or form's Card, never inside it:
 *
 *   <div className="space-y-4">
 *     <Card className="overflow-hidden">…rows…</Card>
 *     {selected.length > 0 && <ActionBar label={t('…')}>…</ActionBar>}
 *   </div>
 *
 * Sticky stops working under any overflow box (overflow-hidden Cards, Table's scroll wrapper). Sticky rather than fixed:
 * it keeps its height in the flow, so the last row always scrolls clear of it with no spacer.
 */
export function ActionBar({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div
      role="group"
      aria-label={label}
      className="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 border-t bg-card px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]"
    >
      {children}
    </div>
  )
}

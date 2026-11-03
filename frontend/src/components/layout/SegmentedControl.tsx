import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export type SegmentedOption<T extends string> = { value: T; label: string; content: ReactNode }

/** The CRM context bar's segmented control: a muted track, the selected option raised on a surface. */
export function SegmentedControl<T extends string>({
  value,
  options,
  onChange,
  label,
  disabled,
  className,
}: {
  value: T
  options: SegmentedOption<T>[]
  onChange: (value: T) => void
  label: string
  disabled?: boolean
  className?: string
}) {
  return (
    <div role="group" aria-label={label} className={cn('flex items-center gap-0.5 rounded-xl border bg-muted p-1', className)} >
      {options.map((option) => {
        const on = option.value === value
        return (
          <button
            key={option.value}
            type="button"
            aria-pressed={on}
            disabled={disabled}
            title={option.label}
            onClick={() => onChange(option.value)}
            className={cn(
              'grid h-8 min-h-tap min-w-tap place-items-center rounded-lg px-1.5 text-table font-medium text-muted-foreground transition-colors enabled:hover:text-foreground',
              'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
              on && 'bg-card text-foreground ring-1 ring-border',
            )}
          >
            {option.content}
            <span className="sr-only">{option.label}</span>
          </button>
        )
      })}
    </div>
  )
}

import { clsx, type ClassValue } from 'clsx'
import { extendTailwindMerge } from 'tailwind-merge'

/** Every custom @theme name the kit uses. theme.test.ts fails when index.css and this list disagree. */
export const THEME_NAMES = {
  text: ['body', 'table', 'page-title', 'section-title', 'card-title', 'kpi'],
  shadow: ['float'],
  spacing: ['tap'],
}

const merge = extendTailwindMerge({ extend: { theme: THEME_NAMES } })

export function cn(...inputs: ClassValue[]) {
  return merge(clsx(inputs))
}

/** The visible focus ring for controls without one of their own. */
export const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring'

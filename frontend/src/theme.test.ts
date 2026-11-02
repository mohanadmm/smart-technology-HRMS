/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { expect, test } from 'vitest'
import { cn, THEME_NAMES } from '@/lib/utils'

// vitest stubs every CSS import (even ?raw) as '', so the stylesheet is read from disk
const css = readFileSync(`${import.meta.dirname}/index.css`, 'utf8')

/** The body of the `@theme inline { … }` block, found by brace depth. */
function themeInline() {
  const start = css.indexOf('@theme inline {') + '@theme inline {'.length
  let end = start
  for (let open = 1; open; end++) open += css[end] === '{' ? 1 : css[end] === '}' ? -1 : 0
  return css.slice(start, end - 1)
}

// tailwind-merge only knows the default theme; an unregistered name like text-table is read as a colour and dropped.
test('cn() knows every custom text, shadow and spacing name in index.css, and no other', () => {
  const found: Record<string, Set<string>> = { text: new Set(), shadow: new Set(), spacing: new Set() }
  for (const [, group, name] of themeInline().matchAll(/--(text|shadow|spacing)-([a-z0-9]+(?:-[a-z0-9]+)*)(?:--[a-z-]+)?\s*:/g)) found[group].add(name)
  expect(Object.fromEntries(Object.entries(found).map(([g, s]) => [g, [...s].sort()]))).toEqual(
    Object.fromEntries(Object.entries(THEME_NAMES ?? {}).map(([g, s]) => [g, [...s].sort()])),
  )
})

test('cn() keeps a theme size next to a colour', () => {
  expect(cn('text-table text-muted-foreground')).toBe('text-table text-muted-foreground')
  expect(cn('text-card-title', 'text-destructive')).toBe('text-card-title text-destructive')
})

test('cn() keeps the touch floor next to a caller size, and an explicit opt-out still wins', () => {
  expect(cn('size-9 min-h-tap min-w-tap', 'max-sm:size-9')).toBe('size-9 min-h-tap min-w-tap max-sm:size-9')
  expect(cn('min-h-tap', 'min-h-0')).toBe('min-h-0')
  expect(cn('shadow-float', 'shadow-none')).toBe('shadow-none')
})

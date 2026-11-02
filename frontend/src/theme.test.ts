/// <reference types="node" />
import { readdirSync, readFileSync } from 'node:fs'
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
    Object.fromEntries(Object.entries(THEME_NAMES).map(([g, s]) => [g, [...s].sort()])),
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

// The touch floor is min-h-tap / min-w-tap, the only shadow is shadow-float, and figures are tabular everywhere (index.css sets it on
// html), so the old spellings must not come back. Pages join as UI-3 sweeps them: each slice deletes its own SKIP entries, and
// pages/site-day.tsx stays until its owner's hand-over.
const SKIP = [
  'pages/attendance-day.tsx',
  'pages/attendance-import.tsx',
  'pages/attendance.tsx',
  'pages/holidays.tsx',
  'pages/settings.tsx',
  'pages/site-day.tsx',
  'pages/sites.tsx',
]
test('the kit and the swept pages use no fixed 44 px, no shadow but shadow-float, no tabular class and no backdrop filter', () => {
  const banned = /(?:[a-z-]+:)*(?:min-)?(?:h|w|size)-11\b|(?<![\w-])(?:min-)?(?:h|size)-\[[\d.]+(?:px|rem)\]|\bshadow-(?!float\b|none\b)[\w[(-]+|backdrop-brightness|(?<![\w-])tabular(?:-nums)?\b/g
  const hits = (f: string) => [...readFileSync(`${import.meta.dirname}/${f}`, 'utf8').matchAll(banned)].map((m) => `${f}: ${m[0]}`)
  const files = ['components', 'pages'].flatMap((dir) => readdirSync(`${import.meta.dirname}/${dir}`, { recursive: true, encoding: 'utf8' }).map((f) => `${dir}/${f}`))
    .filter((f) => /\.tsx?$/.test(f) && !f.includes('.test.'))
  expect(files.filter((f) => !SKIP.includes(f)).flatMap(hits)).toEqual([])
  expect(SKIP.filter((f) => !hits(f).length)).toEqual([]) // a swept page leaves SKIP
})

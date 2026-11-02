/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { act, render, renderHook, screen } from '@testing-library/react'
import { afterEach, expect, test } from 'vitest'
import i18n from '@/i18n'
import { SearchInput } from '@/components/common/Filters'
import { selectCls } from '@/components/form'
import { Button } from '@/components/ui/button'
import { Input, Textarea } from '@/components/ui/input'
import { Select, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useTheme } from '@/hooks/useTheme'
import indexHtml from '../index.html?raw'
import manifest from '../public/manifest.webmanifest?raw'

// vitest stubs every CSS import (even ?raw) as '', so the stylesheet is read from disk
const css = readFileSync(`${import.meta.dirname}/index.css`, 'utf8')

// the keys only: the files in public/, which the build copies to the site root
const published = Object.keys(import.meta.glob('../public/*')).map((f) => f.replace('../public', ''))

// Each `@media (pointer: coarse)` block: its body and its brace depth in the stylesheet (0 = outside every @layer).
const bare = css.replace(/\/\*[\s\S]*?\*\//g, '')
const coarse = [...bare.matchAll(/@media \(pointer: coarse\)\s*\{/g)].map((m) => {
  const start = m.index + m[0].length
  let depth = 0
  for (const c of bare.slice(0, m.index)) depth += c === '{' ? 1 : c === '}' ? -1 : 0
  let end = start
  for (let open = 1; open; end++) open += bare[end] === '{' ? 1 : bare[end] === '}' ? -1 : 0
  return { depth, body: bare.slice(start, end - 1) }
})
const fieldRule = coarse.find((b) => /\binput\b[\s\S]*\btextarea\b[\s\S]*\bselect\b[\s\S]*font-size: 16px;/.test(b.body))

// iPhone and iPad zoom into a field whose font is under 16 px; one stylesheet rule sets 16 px on a touch screen, a mouse keeps 14 px.
test('every field is 16 px on a coarse pointer', () => {
  render(
    <>
      <Input aria-label="input" />
      <Textarea aria-label="textarea" />
      <Select><SelectTrigger aria-label="trigger"><SelectValue /></SelectTrigger></Select>
      <select aria-label="native" className={selectCls} />
    </>,
  )
  for (const name of ['input', 'textarea', 'trigger', 'native']) {
    const cls = (screen.getByLabelText(name) as HTMLElement).className.split(' ')
    expect(cls, name).not.toContain('max-sm:text-base')
    expect(cls, name).toContain('text-sm')
  }
  expect(fieldRule, 'the @media (pointer: coarse) field rule').toBeDefined()
})

// Unlayered CSS beats every utility, so a caller's text-xs cannot undo it.
test('a caller that shrinks a field still gets 16 px on a coarse pointer', () => {
  expect(fieldRule?.depth).toBe(0)
})

test('every kit control has the touch floor, whatever the size', () => {
  render(
    <>
      <Button>default</Button>
      <Button size="sm">sm</Button>
      <Button size="xs">xs</Button>
      <Button size="lg">lg</Button>
      <Button size="icon" aria-label="icon" />
      <Button size="icon-sm" aria-label="icon-sm" />
      <Button size="sm" className="h-7">override</Button>
      <Button size="icon" className="size-8 max-sm:size-9" aria-label="shrunk" />
      <Input aria-label="input" />
      <Select><SelectTrigger aria-label="trigger"><SelectValue /></SelectTrigger></Select>
      <select aria-label="native" className={selectCls} />
      <Tabs defaultValue="a"><TabsList><TabsTrigger value="a">tab</TabsTrigger></TabsList></Tabs>
      <SearchInput value="x" onChange={() => {}} placeholder="search" />
    </>,
  )
  const cls = (el: HTMLElement) => el.className.split(' ')
  for (const name of ['default', 'sm', 'xs', 'lg', 'override', 'icon', 'icon-sm', 'shrunk']) expect(cls(screen.getByRole('button', { name })), name).toContain('min-h-tap')
  for (const name of ['icon', 'icon-sm', 'shrunk']) expect(cls(screen.getByRole('button', { name })), name).toContain('min-w-tap')
  for (const name of ['input', 'trigger', 'native']) expect(cls(screen.getByLabelText(name)), name).toContain('min-h-tap')
  expect(cls(screen.getByRole('tab', { name: 'tab' }))).toContain('min-h-tap')
  const clear = cls(screen.getByRole('button', { name: i18n.t('common.clearSearch') }))
  expect(clear).toEqual(expect.arrayContaining(['min-h-tap', 'min-w-tap']))
})

// 24 px is the WCAG 2.2 AA floor on any pointer; 44 px on touch only, so mouse layouts stay dense.
test('the tap size is 24 px, and 44 px on a coarse pointer', () => {
  expect(css.match(/\n:root \{[^}]*?--tap: ([^;]+);/)?.[1]).toBe('1.5rem')
  expect(coarse.some((b) => /:root \{\s*--tap: 2\.75rem;/.test(b.body))).toBe(true)
})

test('the manifest is valid and its icons exist', () => {
  const m = JSON.parse(manifest)
  expect(m).toMatchObject({ name: 'سمارت تكنولوجي — الموارد البشرية', short_name: 'HR سمارت', start_url: '/', scope: '/', display: 'standalone', dir: 'rtl', lang: 'ar' })
  expect(m.orientation).toBeUndefined()
  expect(m.theme_color).toMatch(/^#[0-9a-f]{6}$/i)
  expect(m.background_color).toMatch(/^#[0-9a-f]{6}$/i)
  expect(m.icons.map((i: { sizes: string; purpose: string }) => `${i.sizes} ${i.purpose}`)).toEqual(expect.arrayContaining(['192x192 any', '512x512 any', '512x512 maskable']))
  for (const icon of m.icons) expect(published, icon.src).toContain(icon.src)
})

test('index.html links the manifest, allows pinch zoom and asks for a full-screen app', () => {
  const doc = new DOMParser().parseFromString(indexHtml, 'text/html')
  const meta = (name: string) => doc.querySelector(`meta[name="${name}"]`)?.getAttribute('content')
  expect(doc.querySelector('link[rel="manifest"]')?.getAttribute('href')).toBe('/manifest.webmanifest')
  expect(meta('viewport')).toBe('width=device-width, initial-scale=1, viewport-fit=cover')
  expect(meta('apple-mobile-web-app-capable')).toBe('yes')
  expect(meta('mobile-web-app-capable')).toBe('yes')
  expect(meta('apple-mobile-web-app-status-bar-style')).toBe('default')
  expect(meta('apple-mobile-web-app-title')).toBe('HR سمارت')
  const media = [...doc.querySelectorAll('meta[name="theme-color"]')].map((m) => m.getAttribute('media'))
  expect(media).toEqual(['(prefers-color-scheme: light)', '(prefers-color-scheme: dark)'])
})

// index.html and the manifest cannot read CSS, so they repeat the token values; this keeps the copies equal to index.css.
test('the colours in index.html and the manifest are the tokens in index.css', () => {
  const token = (block: string, name: string) => css.match(new RegExp(`\\n${block} \\{[^}]*?--${name}: (#[0-9a-f]{6});`, 'i'))?.[1]
  const doc = new DOMParser().parseFromString(indexHtml, 'text/html')
  const bars = [...doc.querySelectorAll('meta[name="theme-color"]')].map((m) => m.getAttribute('content'))
  expect(bars).toEqual([token(':root', 'card'), token('.dark', 'card')])
  expect(doc.querySelector('head script')?.textContent, 'the bootstrap copies a meta, never its own colour').not.toMatch(/#[0-9a-f]{3,6}\b/i)
  const m = JSON.parse(manifest)
  expect([m.theme_color, m.background_color]).toEqual([token(':root', 'card'), token(':root', 'background')])
})

// The bar colour is the header's (--card), read from the stylesheet, so it never drifts from the tokens.
const tokens = Object.assign(document.createElement('style'), { textContent: ':root { --card: #ffffff; } .dark { --card: #111827; }' })
afterEach(() => {
  tokens.remove()
  document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.remove())
  document.documentElement.classList.remove('dark')
})

test('the browser bar colour follows the in-app theme toggle, whatever the OS asks for', () => {
  document.head.append(tokens)
  for (const media of ['(prefers-color-scheme: light)', '(prefers-color-scheme: dark)']) {
    document.head.append(Object.assign(document.createElement('meta'), { name: 'theme-color', content: '#000000', media }))
  }
  const bars = () => [...document.querySelectorAll<HTMLMetaElement>('meta[name="theme-color"]')].map((m) => m.content)
  const { result } = renderHook(() => useTheme())
  act(() => result.current.setTheme('dark'))
  expect(bars()).toEqual(['#111827', '#111827'])
  act(() => result.current.setTheme('light'))
  expect(bars()).toEqual(['#ffffff', '#ffffff'])
})

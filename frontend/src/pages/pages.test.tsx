import { screen } from '@testing-library/react'
import { expect, test } from 'vitest'
import { renderWithAuth } from '@/test-utils'
import { NotFoundPage } from './pages'

test('the not-found page sends you back with a link drawn as the solid button', () => {
  renderWithAuth(<NotFoundPage />, [])
  const back = screen.getByRole('link', { name: 'العودة إلى الموظفين' })
  expect(back).toHaveAttribute('href', '/employees')
  expect(back).toHaveClass('bg-primary', 'text-primary-foreground')
})

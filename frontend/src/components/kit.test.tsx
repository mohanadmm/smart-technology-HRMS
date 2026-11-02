import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { expect, test, vi } from 'vitest'
import '@/i18n'
import { RowActions } from '@/components/common/RowActions'
import { StatusBadge } from '@/components/common/StatusBadge'
import { Alert } from '@/components/common/Alert'
import { ErrorBanner, intlLocale } from '@/components/form'
import { Pagination } from '@/components/tables/Pagination'
import { ActionBar } from '@/components/layout/ActionBar'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ApiError } from '@/lib/api'

const user = userEvent.setup()

test('Card, StatusBadge and Tabs render and switch panels', async () => {
  render(
    <Card>
      <CardHeader><CardTitle>عنوان</CardTitle></CardHeader>
      <CardContent>
        <StatusBadge variant="success" label="نشط" />
        <Tabs defaultValue="a">
          <TabsList>
            <TabsTrigger value="a">أ</TabsTrigger>
            <TabsTrigger value="b">ب</TabsTrigger>
          </TabsList>
          <TabsContent value="a">لوحة أ</TabsContent>
          <TabsContent value="b">لوحة ب</TabsContent>
        </Tabs>
      </CardContent>
    </Card>,
  )
  expect(screen.getByText('عنوان')).toBeInTheDocument()
  expect(screen.getByText('نشط')).toBeInTheDocument()
  expect(screen.getByText('لوحة أ')).toBeInTheDocument()
  await user.click(screen.getByRole('tab', { name: 'ب' }))
  expect(screen.getByText('لوحة ب')).toBeInTheDocument()
  expect(screen.queryByText('لوحة أ')).toBeNull()
})

test('Pagination shows the range in Arabic and pages forward', async () => {
  const onPageChange = vi.fn()
  render(<Pagination page={1} pageSize={12} total={30} onPageChange={onPageChange} />)
  expect(screen.getByText('عرض 1–12 من 30')).toBeInTheDocument()
  expect(screen.getByText('صفحة 1 من 3')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'السابق' })).toBeDisabled()
  await user.click(screen.getByRole('button', { name: 'التالي' }))
  expect(onPageChange).toHaveBeenCalledWith(2)
})

test('RowActions opens its menu and runs the chosen item', async () => {
  const onSelect = vi.fn()
  render(<RowActions items={[{ label: 'تعديل', onSelect }]} />)
  await user.click(screen.getByRole('button', { name: 'الإجراءات' }))
  await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }))
  expect(onSelect).toHaveBeenCalledOnce()
})

test('Arabic numbers and dates use Latin digits', () => {
  expect(intlLocale('ar')).toBe('ar-EG-u-nu-latn')
  expect(intlLocale('en')).toBe('en')
  expect(new Intl.NumberFormat(intlLocale('ar'), { minimumFractionDigits: 2 }).format(7.5)).toBe('7.50')
})

test('Alert announces danger and success but leaves a static note silent', () => {
  const { rerender } = render(<Alert tone="danger">تعذّر الحفظ</Alert>)
  expect(screen.getByRole('alert')).toHaveTextContent('تعذّر الحفظ')
  rerender(<Alert tone="success" title="تم">تم الاستيراد</Alert>)
  expect(screen.getByRole('status')).toHaveTextContent('تمتم الاستيراد')
  for (const tone of ['info', 'neutral', 'warning'] as const) {
    rerender(<Alert tone={tone}>ملاحظة</Alert>)
    expect(screen.queryByRole('alert')).toBeNull()
    expect(screen.queryByRole('status')).toBeNull()
    expect(screen.getByText('ملاحظة')).toBeInTheDocument()
  }
})

test('ErrorBanner draws a danger Alert with only the messages no field shows', () => {
  render(<ErrorBanner err={new ApiError('VALIDATION', 'invalid', 422, [{ field: 'name', message: 'الاسم مطلوب' }, { field: 'x', message: 'خطأ آخر' }])} shown={['name']} />)
  const alert = screen.getByRole('alert')
  expect(alert).toHaveTextContent('خطأ آخر')
  expect(alert).not.toHaveTextContent('الاسم مطلوب')
  expect(alert).toHaveClass('bg-destructive-bg')
})

test('SheetHeader leaves room for the close button and SheetFooter sits at the bottom', () => {
  render(
    <Sheet open>
      <SheetContent>
        <SheetHeader data-testid="head"><SheetTitle>سجل</SheetTitle></SheetHeader>
        <SheetFooter data-testid="foot"><Button>حفظ</Button></SheetFooter>
      </SheetContent>
    </Sheet>,
  )
  expect(screen.getByTestId('head')).toHaveClass('border-b', 'pe-14')
  expect(screen.getByTestId('head')).toHaveTextContent('سجل')
  expect(screen.getByTestId('foot')).toHaveClass('mt-auto', 'border-t')
  expect(within(screen.getByTestId('foot')).getByRole('button', { name: 'حفظ' })).toBeInTheDocument()
})

/** The documented usage (S2a bulk bar): a sibling after the list's Card, so sticky binds to the page scroll. */
function BulkBar() {
  const [times, setTimes] = useState(false)
  return (
    <div className="space-y-4">
      <Card className="overflow-hidden">الصفوف</Card>
      <ActionBar label="إجراءات المحدد">
        <span>2 محدد</span>
        <Button variant="ghost">مسح</Button>
        <Button variant="outline">حاضر</Button>
        <Button variant="outline" onClick={() => setTimes(true)}>تعديل الأوقات</Button>
      </ActionBar>
      <Dialog open={times} onOpenChange={setTimes}>
        <DialogContent aria-describedby={undefined}><DialogTitle>تعديل الأوقات</DialogTitle></DialogContent>
      </Dialog>
    </div>
  )
}

test('ActionBar sticks to the bottom with the safe area and no clipping ancestor in its documented usage', () => {
  render(<BulkBar />)
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(bar).toHaveClass('sticky', 'bottom-0', 'pb-[calc(0.75rem+env(safe-area-inset-bottom))]')
  // sticky stops working inside any overflow box between the bar and the page scroll
  for (let el = bar.parentElement; el; el = el.parentElement) expect(el.className).not.toMatch(/\boverflow-(?:hidden|auto|scroll|clip)\b/)
})

test('ActionBar holds a count, Clear, actions and a button that opens a dialog', async () => {
  render(<BulkBar />)
  const bar = screen.getByRole('group', { name: 'إجراءات المحدد' })
  expect(bar).toHaveTextContent('2 محدد')
  expect(within(bar).getByRole('button', { name: 'مسح' })).toBeInTheDocument()
  await user.click(within(bar).getByRole('button', { name: 'تعديل الأوقات' }))
  expect(await screen.findByRole('dialog', { name: 'تعديل الأوقات' })).toBeInTheDocument()
})

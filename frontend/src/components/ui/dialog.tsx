import * as React from 'react'
import { useTranslation } from 'react-i18next'
import * as DialogPrimitive from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import { cn } from '@/lib/utils'

export const Dialog = DialogPrimitive.Root
export const DialogTrigger = DialogPrimitive.Trigger
export const DialogClose = DialogPrimitive.Close

export function DialogOverlay({ className, ...props }: React.ComponentProps<typeof DialogPrimitive.Overlay>) {
  return (
    <DialogPrimitive.Overlay
      className={cn('fixed inset-0 z-50 bg-scrim data-[state=open]:animate-fade-in', className)}
      {...props}
    />
  )
}

export function DialogContent({
  className,
  children,
  hideClose,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & { hideClose?: boolean }) {
  const { t } = useTranslation()
  return (
    <DialogPrimitive.Portal>
      <DialogOverlay />
      <DialogPrimitive.Content
        className={cn(
          'fixed z-50 flex max-h-[92dvh] w-full flex-col overflow-hidden border bg-card shadow-float outline-none data-[state=open]:animate-fade-in',
          'inset-x-0 bottom-0 rounded-t-xl pb-[env(safe-area-inset-bottom)] sm:inset-auto sm:pb-0 sm:top-1/2 sm:left-1/2 sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-xl sm:max-w-lg',
          className,
        )}
        {...props}
      >
        {children}
        {!hideClose && (
          <DialogPrimitive.Close className="absolute top-3 end-3 grid size-8 min-h-tap min-w-tap place-items-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
            <X className="size-4" />
            <span className="sr-only">{t('common.close')}</span>
          </DialogPrimitive.Close>
        )}
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  )
}

export function DialogHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return <div className={cn('flex flex-col gap-1 border-b px-6 pt-5 pb-4 pe-14', className)} {...props} />
}

export function DialogBody({ className, ...props }: React.ComponentProps<'div'>) {
  return <div className={cn('flex-1 overflow-y-auto overscroll-contain px-6 py-5 scrollbar-thin', className)} {...props} />
}

export function DialogFooter({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      className={cn('flex flex-col-reverse gap-2 border-t bg-muted/40 px-6 py-3.5 sm:flex-row sm:items-center sm:justify-end', className)}
      {...props}
    />
  )
}

export function DialogTitle({ className, ...props }: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return <DialogPrimitive.Title className={cn('text-base font-semibold tracking-tight', className)} {...props} />
}

export function DialogDescription({ className, ...props }: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return <DialogPrimitive.Description className={cn('text-[13px] text-muted-foreground', className)} {...props} />
}

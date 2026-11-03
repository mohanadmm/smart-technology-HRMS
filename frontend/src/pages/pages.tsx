import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'

export function NotFoundPage() {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-start gap-3">
      <h1 className="text-page-title">{t('notFound.title')}</h1>
      <Button asChild>
        <Link to="/employees">{t('notFound.back')}</Link>
      </Button>
    </div>
  )
}

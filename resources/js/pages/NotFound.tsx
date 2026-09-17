import { Compass } from 'lucide-react'
import { EmptyState } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'

export default function NotFound() {
  return (
    <EmptyState
      icon={<Compass />}
      title="Sayfa bulunamadı"
      description="Aradığınız sayfa taşınmış ya da hiç var olmamış olabilir."
      action={
        <ButtonLink to="/" variant="primary">
          Kontrol Merkezi'ne dön
        </ButtonLink>
      }
    />
  )
}

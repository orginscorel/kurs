import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Tabs } from '@/components/ui/layout'
import { Skeleton } from '@/components/ui/feedback'
import type { Catalog } from './types'
import BatchesPanel from './BatchesPanel'
import TemplatesPanel from './TemplatesPanel'
import SettingsPanel from './SettingsPanel'

type Tab = 'gonderimler' | 'sablonlar' | 'ayarlar'

/**
 * Bildirim Merkezi: tek menü altında üç sekme — Gönderimler (olay bildirimleri), Şablonlar (olay×kitle metinleri),
 * Ayarlar (olay bazlı aç/kapa + onay + kitleler). Katalog bir kez yüklenip alt panellere verilir.
 */
export default function NotificationCenter() {
  const can = useCan()
  const canManage = can('templates.manage')
  const [tab, setTab] = useState<Tab>('gonderimler')

  const { data: catalog, isLoading } = useQuery({ queryKey: ['notif', 'catalog'], queryFn: () => api.get<Catalog>('/notifications/catalog'), staleTime: 10 * 60_000 })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Bildirim Merkezi"
        description="Ders programı, rehberlik, koçluk, ödeme gibi olaylarda öğrenci, veli, öğretmen ve yöneticilere ayrı şablonlarla WhatsApp bildirimi gönderin."
      />

      <Tabs
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'gonderimler', label: 'Gönderimler' },
          { value: 'sablonlar', label: 'Şablonlar', hidden: !canManage },
          { value: 'ayarlar', label: 'Ayarlar', hidden: !canManage },
        ]}
        className="mb-4"
      />

      {isLoading ? (
        <Panel><Skeleton className="h-64" /></Panel>
      ) : tab === 'gonderimler' ? (
        <BatchesPanel catalog={catalog} />
      ) : tab === 'sablonlar' ? (
        <TemplatesPanel catalog={catalog} />
      ) : (
        <SettingsPanel catalog={catalog} />
      )}
    </div>
  )
}

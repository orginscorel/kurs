import { useNavigate } from 'react-router-dom'
import { Tabs } from '@/components/ui/layout'

type Key = 'bot' | 'structure' | 'templates' | 'substitute'
const paths: Record<Key, string> = { bot: '/program-botu', structure: '/program-botu/sinif-yapisi', templates: '/program-botu/sablonlar', substitute: '/program-botu/yedek' }

/** Program botu alanının sayfaları arasında üst sekme. */
export function BotTabs({ active }: { active: Key }) {
  const navigate = useNavigate()
  return (
    <Tabs<Key>
      className="mb-5"
      value={active}
      onChange={(v) => navigate(paths[v])}
      tabs={[
        { value: 'bot', label: 'Program botu' },
        { value: 'structure', label: 'Sınıf yapısı' },
        { value: 'templates', label: 'Zaman şablonları' },
        { value: 'substitute', label: 'Yedek öğretmen' },
      ]}
    />
  )
}

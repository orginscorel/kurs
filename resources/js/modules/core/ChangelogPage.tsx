import { History } from 'lucide-react'
import { PageHeader, Panel } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ChangelogList } from '@/components/app/ChangelogList'
import { APP_VERSION, useChangelog } from '@/components/app/version'

/** Yenilikler: tüm sürümlerin değişiklik günlüğü. */
export default function ChangelogPage() {
  const { data, isLoading } = useChangelog()
  return (
    <div className="mx-auto max-w-[900px] animate-fade-in">
      <PageHeader title="Yenilikler" description={`Kullandığınız sürüm: v${APP_VERSION}. Sistemdeki tüm değişiklikler en yeniden eskiye listelenir.`} />
      <Panel>
        {isLoading ? <Skeleton className="h-64" /> : data?.length ? <ChangelogList entries={data} /> : <EmptyState icon={<History />} title="Değişiklik kaydı yok" />}
      </Panel>
    </div>
  )
}

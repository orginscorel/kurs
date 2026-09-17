import { Link } from 'react-router-dom'
import { BarChart3, ChevronRight, FileSpreadsheet, Lock } from 'lucide-react'
import { useCan } from '@/app/auth'
import { PageHeader } from '@/components/ui/layout'
import { EmptyState } from '@/components/ui/feedback'
import { REPORTS, useAllowed } from './shared'

/** Rapor merkezi: yetkili olunan raporların listesi. */
export default function ReportsHome() {
  const allowed = useAllowed()
  const can = useCan()
  const visible = REPORTS.filter(allowed)

  return (
    <div className="animate-fade-in">
      <PageHeader title="Raporlar" description="Kurumun öğrenci, yoklama, tahsilat, sınav, ön kayıt ve öğretmen raporları. Her rapor filtrelenebilir ve dışa aktarılabilir." />
      {visible.length === 0 ? (
        <div className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          <EmptyState icon={<Lock />} title="Görebileceğiniz rapor yok" description="Rapor görmek için ilgili modül yetkisi gerekir. Kurum yöneticinizle görüşün." />
        </div>
      ) : (
        <ul className="divide-y divide-line overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
          {visible.map((r) => (
            <li key={r.key}>
              <Link to={r.to} className="group flex items-center gap-3.5 px-4 py-3.5 transition-colors hover:bg-surface-2">
                <span className="grid size-9 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2 ring-1 ring-line [&_svg]:size-[18px]"><r.icon /></span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[14px] font-medium text-ink">{r.title}</span>
                  <span className="block text-[12.5px] text-ink-3">{r.description}</span>
                </span>
                <ChevronRight className="size-4 shrink-0 text-ink-3 transition-transform group-hover:translate-x-0.5" />
              </Link>
            </li>
          ))}
        </ul>
      )}
      {visible.length > 0 && (
        <p className="mt-3 flex items-center gap-1.5 px-1 text-[12.5px] text-ink-3">
          {can('reports.export') ? <><FileSpreadsheet className="size-3.5" />Excel ve PDF dosyaları ekrandaki filtrelerle hazırlanır; her dışa aktarma denetim kaydına yazılır.</> : <><BarChart3 className="size-3.5" />Dışa aktarma için "Rapor dışa aktarma" yetkisi gerekir.</>}
        </p>
      )}
    </div>
  )
}

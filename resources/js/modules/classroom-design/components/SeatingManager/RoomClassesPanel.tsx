import { Link } from 'react-router-dom'
import { ArrowRight, Users } from 'lucide-react'
import { useRoster } from '../../api'
import { useClassroom } from '../../state/classroomStore'
import { EmptyState, Skeleton } from '@/components/ui/feedback'

/**
 * Oda düzenleyicisinde "Sınıflar" sekmesi: oturma planı SINIF bazındadır (aynı derslik farklı saatlerde farklı
 * sınıflarca kullanılır). Bu dersliği kullanan sınıflar ve oturma planlarına bağlantı.
 */
export function RoomClassesPanel() {
  const classroomId = useClassroom((s) => s.classroomId)
  const roster = useRoster(classroomId, [])
  if (!classroomId) {
    return <EmptyState compact icon={<Users />} title="Derslik bağlı değil" description="Üst çubuktan bu tasarımı bir dersliğe bağlayın; o dersliği kullanan sınıfların oturma planları burada listelenir." />
  }
  if (roster.isLoading) return <div className="space-y-2 p-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-12" />)}</div>
  const groups = (roster.data?.groups ?? []).filter((g) => roster.data?.using_group_ids.includes(g.id))
  return (
    <div className="space-y-3 p-3">
      <p className="text-[12.5px] leading-snug text-ink-2">
        Öğrenci yerleşimi <b>sınıf bazındadır</b>: aynı derslik farklı saatlerde farklı sınıflarca kullanılır. Oda düzenini burada, oturma planını sınıfın sayfasında düzenleyin.
      </p>
      {groups.length === 0 ? (
        <EmptyState compact icon={<Users />} title="Bu dersliği kullanan sınıf yok" description="Sınıfa ana derslik atayın ya da ders programında bu dersliği seçin." />
      ) : (
        <ul className="divide-y divide-line rounded-[var(--radius-sm)] border border-line">
          {groups.map((g) => (
            <li key={g.id}>
              <Link to={`/siniflar/${g.id}?sekme=oturma`} className="flex items-center justify-between gap-2 px-3 py-2.5 hover:bg-surface-2" data-testid={`room-class-${g.id}`}>
                <span>
                  <span className="block text-[13px] font-medium text-ink">{g.name}</span>
                  <span className="block text-[11.5px] text-ink-3">{g.student_count} öğrenci · {g.homeroom ? 'sınıf dersliği' : `haftada ${g.weekly_lessons} ders`}</span>
                </span>
                <span className="inline-flex items-center gap-1 text-[12px] font-medium text-primary">Oturma düzeni <ArrowRight className="size-3.5" /></span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

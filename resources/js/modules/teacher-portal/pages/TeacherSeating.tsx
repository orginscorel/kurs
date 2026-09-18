import { Suspense, lazy } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { Skeleton } from '@/components/ui/feedback'

// 3D oturma düzeni (three.js yalnız bu sayfada iner) — öğretmen portalında SALT OKUNUR
const ClassSeatingPanel = lazy(() => import('@/modules/classroom-design/seating/ClassSeatingPanel'))

export default function TeacherSeating() {
  const { id } = useParams()
  return (
    <div className="animate-fade-in">
      <div className="mb-3 flex items-center gap-2">
        <Link to={`/ogretmen/siniflar/${id}`} className="inline-flex items-center gap-1 text-[12.5px] font-medium text-ink-3 hover:text-ink"><ArrowLeft className="size-3.5" /> Sınıfa dön</Link>
      </div>
      <h1 className="mb-3 text-[20px] font-semibold tracking-[-0.015em]">Oturma düzeni</h1>
      <Suspense fallback={<Skeleton className="h-[560px]" />}>
        <ClassSeatingPanel groupId={Number(id)} source="teacher" />
      </Suspense>
    </div>
  )
}

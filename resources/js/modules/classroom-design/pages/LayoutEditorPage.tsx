import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { useParams } from 'react-router-dom'
import { Box } from 'lucide-react'
import { useCan } from '@/app/auth'
import { ButtonLink } from '@/components/ui/Button'
import { EmptyState, Spinner } from '@/components/ui/feedback'
import { useLayout } from '../api'
import { useClassroom } from '../state/classroomStore'
import { useSelection } from '../state/selectionStore'
import { ClassroomEditor } from '../components/ClassroomEditor/ClassroomEditor'

/** Tasarım düzenleyicisi: sunucudaki JSON birebir yüklenir (oda, açıklıklar, nesneler, oturma, kamera, ayarlar). */
export default function LayoutEditorPage() {
  const { id } = useParams()
  const layoutId = Number(id)
  const q = useLayout(Number.isFinite(layoutId) ? layoutId : null)
  const can = useCan()
  const loadedId = useClassroom((s) => s.layoutId)

  useEffect(() => {
    const d = q.data
    if (!d) return
    useClassroom.getState().load(
      {
        layoutId: d.id,
        name: d.name,
        classroomId: d.classroom?.id ?? null,
        classroomName: d.classroom?.name ?? null,
        classroomCapacity: d.classroom?.capacity ?? null,
        version: d.version,
        isDemo: d.is_demo,
        hasThumbnail: d.has_thumbnail,
      },
      d.data,
    )
    const s = useSelection.getState()
    s.clear()
    s.clearMeasurements()
    s.setTool('select')
    s.setTab('library')
  }, [q.data])

  if (q.isError) {
    return (
      <EmptyState
        icon={<Box />}
        title="Tasarım bulunamadı"
        description="Silinmiş ya da erişim yetkiniz olmayan bir tasarım olabilir."
        action={<ButtonLink to="/derslik-tasarimi">Derslik listesine dön</ButtonLink>}
      />
    )
  }
  if (!q.data || loadedId !== q.data.id) {
    return createPortal(
      <div className="fixed inset-0 z-40 grid place-items-center bg-bg">
        <div className="flex items-center gap-2 text-[13px] text-ink-2">
          <Spinner /> 3D derslik yükleniyor…
        </div>
      </div>,
      document.body,
    )
  }
  return <ClassroomEditor canManage={can('classroom_layouts.manage')} onReload={() => q.refetch()} />
}

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeftRight, Clock, Pin } from 'lucide-react'
import { api } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Button } from '@/components/ui/Button'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ChangeClassDialog } from './ChangeClassDialog'
import type { StudentPanelData } from './types'

/**
 * Öğrenci profili için sınıf paneli: mevcut şube, bekleme listesi, "Sınıf değiştir", tüm sınıf geçmişi.
 * Kullanım: <StudentClassPanel studentId={student.id} />
 */
export function StudentClassPanel({ studentId }: { studentId: number }) {
  const can = useCan()
  const canChange = can(['academic.manage', 'students.update'])
  const [open, setOpen] = useState(false)
  const { data, isLoading, error } = useQuery({
    queryKey: ['placement', 'student', studentId],
    queryFn: () => api.get<StudentPanelData>(`/placement/students/${studentId}`),
  })

  if (isLoading) return <Skeleton className="h-40" />
  if (error || !data) return <Panel title="Sınıf"><EmptyState compact title="Sınıf bilgisi alınamadı" /></Panel>

  const c = data.current
  const changeable = canChange && data.options.some((o) => !o.is_current)

  return (
    <Panel
      title="Sınıf"
      description={data.term ? `${data.term.name} dönemi` : undefined}
      actions={changeable && <Button size="sm" icon={<ArrowLeftRight className="size-3.5" />} onClick={() => setOpen(true)}>{c ? 'Sınıf değiştir' : 'Şubeye yerleştir'}</Button>}
    >
      <div className="flex flex-col gap-4">
        {c ? (
          <div className="flex flex-wrap items-end justify-between gap-2">
            <div>
              <div className="flex items-center gap-2 text-[22px] font-semibold tracking-[-0.015em] leading-tight">
                {c.name}
                {c.pinned && <Badge><Pin className="size-3" /> Sabit</Badge>}
              </div>
              <p className="mt-0.5 text-[12.5px] text-ink-3">{date(c.joined_on)} tarihinden beri</p>
            </div>
            {can('academic.view') && <Link to={`/yerlestirme`} className="text-[12.5px] text-ink-2 hover:text-ink">Şubeleri gör</Link>}
          </div>
        ) : (
          <div>
            <p className="text-[14px] font-medium">Şubeye yerleşmemiş</p>
            <p className="mt-0.5 text-[12.5px] text-ink-3">
              {data.unstructured_class ? `Eski yapıdaki sınıfı: ${data.unstructured_class.name}. ` : ''}
              {data.level === null ? 'Okul sınıfı (9-12) girilmemiş.' : `${data.level}. sınıf şubelerinden birine yerleştirilebilir.`}
            </p>
          </div>
        )}

        {data.waitlist && (
          <Alert tone="info" title={`Bekleme listesinde${data.waitlist.preferred_section && data.level ? ` · ${data.level}-${data.waitlist.preferred_section} için` : ''}`}>
            {data.waitlist.reason ?? data.waitlist.source_label} · {date(data.waitlist.created_at)}
          </Alert>
        )}

        <div>
          <h4 className="mb-2 flex items-center gap-1.5 text-[12.5px] font-medium text-ink-2"><Clock className="size-3.5" /> Sınıf geçmişi</h4>
          {data.history.length === 0 ? (
            <p className="text-[13px] text-ink-3">Henüz sınıf kaydı yok.</p>
          ) : (
            <ol className="flex flex-col">
              {data.history.map((h) => (
                <li key={h.id} className="relative border-l border-line pb-3 pl-4 last:pb-0">
                  <span className={`absolute -left-[4.5px] top-1.5 size-2 rounded-full ${h.is_current ? 'bg-ink' : 'bg-line-strong'}`} />
                  <div className="flex flex-wrap items-center gap-x-2 text-[13px]">
                    <span className="font-medium">{h.class_name}</span>
                    {h.term && <span className="text-ink-3">{h.term}</span>}
                    {!h.class_active && <Badge>Pasif sınıf</Badge>}
                  </div>
                  <p className="text-[12px] text-ink-3 tabular">{date(h.joined_on)} – {h.left_on ? date(h.left_on) : 'devam ediyor'}{h.changed_by ? ` · ${h.changed_by}` : ''}</p>
                  {h.reason && <p className="mt-0.5 text-[12.5px] text-ink-2">{h.reason}</p>}
                </li>
              ))}
            </ol>
          )}
        </div>
      </div>

      {open && (
        <ChangeClassDialog
          studentId={studentId}
          studentName={data.student?.full_name ?? 'Öğrenci'}
          currentName={c?.name}
          options={data.options}
          onClose={() => setOpen(false)}
        />
      )}
    </Panel>
  )
}

export default StudentClassPanel

import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, BookOpenCheck, CalendarDays, Plus, Star } from 'lucide-react'
import { ApiError } from '@/lib/api'
import { date, time } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import { Panel } from '@/components/ui/layout'
import { MiniStat } from '@/modules/portal/ui'
import { useTeacherCan, useTeacherQuery, type GroupMeta } from '../api'
import { PointsPill } from '../ObservationList'
import { usePortalPageTitle } from '@/modules/portal/PortalLayout'

type StudentRow = {
  id: number; full_name: string; student_no: string; photo_url: string | null
  attendance_rate: number | null; absent_30: number; late_30: number; last_net: string | null
  homework_done: number; homework_total: number; homework_avg: number | null; points_60: number; notes_60: number
}
type Data = {
  group: GroupMeta | null
  students: StudentRow[]
  upcoming_lessons: { id: number; date: string; starts_at: string; ends_at: string; subject: string; classroom: string | null; attendance_taken_at: string | null }[]
  homework: { id: number; title: string; due_at: string; subject: string }[]
}
type Sort = 'name' | 'absent' | 'homework' | 'net'

export default function TeacherClassDetail() {
  const { id } = useParams()
  const can = useTeacherCan()
  const { data, isLoading, error } = useTeacherQuery<Data>(['classes', id], `/classes/${id}`)
  const [sort, setSort] = useState<Sort>('name')

  const rows = useMemo(() => {
    const list = [...(data?.students ?? [])]
    const hwRate = (s: StudentRow) => (s.homework_total ? s.homework_done / s.homework_total : 1)
    if (sort === 'absent') list.sort((a, b) => b.absent_30 - a.absent_30)
    if (sort === 'homework') list.sort((a, b) => hwRate(a) - hwRate(b))
    if (sort === 'net') list.sort((a, b) => Number(b.last_net ?? -999) - Number(a.last_net ?? -999))
    return list
  }, [data, sort])

  usePortalPageTitle(data?.group?.name)
  if (error) return <EmptyState title="Sınıf açılamadı" description={error instanceof ApiError ? error.message : undefined} action={<ButtonLink to="/ogretmen/siniflar">Sınıflarıma dön</ButtonLink>} />
  if (isLoading || !data?.group) return <div className="flex flex-col gap-3"><Skeleton className="h-16" /><Skeleton className="h-96" /></div>

  const g = data.group
  const st = data.students
  const avgAtt = st.filter((s) => s.attendance_rate !== null)
  const attRate = avgAtt.length ? Math.round(avgAtt.reduce((a, s) => a + (s.attendance_rate ?? 0), 0) / avgAtt.length) : null
  const hwTotal = st.reduce((a, s) => a + s.homework_total, 0)
  const hwDone = st.reduce((a, s) => a + s.homework_done, 0)
  const risky = st.filter((s) => s.absent_30 >= 3).length

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <div>
        <Link to="/ogretmen/siniflar" className="hidden items-center gap-1 text-[12.5px] font-medium text-ink-3 hover:text-ink md:inline-flex"><ArrowLeft className="size-3.5" /> Sınıflarım</Link>
        <div className="mt-1 flex flex-wrap items-end justify-between gap-3">
          <div className="min-w-0">
            <h1 className="flex items-center gap-2 text-[20px] font-semibold tracking-[-0.02em] sm:text-[22px]">{g.name}{g.is_advisor && <Badge tone="accent"><Star className="size-3" /> Danışmanı</Badge>}</h1>
            <p className="text-[14px] text-ink-3">{[g.program, g.classroom, g.subjects.map((s) => s.name).join(', ')].filter(Boolean).join(' · ')}</p>
          </div>
          {can('homework') && <ButtonLink to={`/ogretmen/odevler?yeni=1&sinif=${g.id}`} variant="primary" icon={<Plus className="size-4" />}>Bu sınıfa ödev ver</ButtonLink>}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Öğrenci" value={st.length} />
        <MiniStat label="Devam oranı (30 gün)" value={attRate !== null ? `%${attRate}` : '—'} tone={attRate !== null && attRate < 85 ? 'warning' : undefined} />
        <MiniStat label="Ödev teslim oranı" value={hwTotal ? `%${Math.round(hwDone / hwTotal * 100)}` : '—'} sub={`${hwDone}/${hwTotal} teslim`} />
        <MiniStat label="3 ve üzeri devamsızlığı olan" value={risky} tone={risky ? 'danger' : undefined} sub="öğrenci · son 30 gün" />
      </div>

      <Panel title="Öğrenciler" actions={
        <span className="flex max-w-full items-center gap-1.5"><span className="hidden text-[12.5px] text-ink-3 min-[420px]:inline">Sırala:</span>
        <Segmented size="sm" className="max-w-full" value={sort} onChange={setSort} options={[
          { value: 'name', label: 'Ad' }, { value: 'absent', label: 'Devamsızlık' }, { value: 'homework', label: 'Ödev' }, { value: 'net', label: 'Son net' },
        ]} /></span>
      } flush>
        {rows.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Sınıfta öğrenci yok.</p> : (
          <ul>
            {rows.map((s) => (
              <li key={s.id} className="border-t border-line">
                <Link to={`/ogretmen/ogrenci/${s.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-4 py-2.5 hover:bg-surface-2/60">
                  <span className="flex min-w-0 flex-1 basis-56 items-center gap-2.5">
                    <Avatar name={s.full_name} src={s.photo_url} size={32} />
                    <span className="min-w-0">
                      <span className="block break-words text-[14.5px] font-medium">{s.full_name}</span>
                      <span className="block text-[12.5px] text-ink-3 tabular">Öğrenci no {s.student_no}</span>
                    </span>
                  </span>
                  <span className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-ink-2 tabular">
                    <span title="Son 30 gün devam oranı" className={cn(s.attendance_rate !== null && s.attendance_rate < 80 && 'font-semibold text-danger')}>
                      Devam oranı {s.attendance_rate !== null ? `%${s.attendance_rate}` : '—'}{s.absent_30 ? ` · 30 günde ${s.absent_30} kez gelmedi` : ''}
                    </span>
                    <span title="Sizin ödevleriniz">Yaptığı ödev {s.homework_done}/{s.homework_total}{s.homework_avg !== null ? ` · ortalama puan ${s.homework_avg}` : ''}</span>
                    <span title="Son yayımlanan sınav neti">Son deneme neti {s.last_net ?? '—'}</span>
                    <PointsPill points={s.points_60} />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title={<span className="inline-flex items-center gap-1.5"><CalendarDays className="size-4 text-primary" /> Sıradaki dersler</span>} flush>
          {data.upcoming_lessons.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Planlı ders yok.</p> : (
            <ul>
              {data.upcoming_lessons.map((l) => (
                <li key={l.id} className="border-t border-line">
                  <Link to={`/ogretmen/yoklama/${l.id}`} className="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-surface-2/60">
                    <span className="min-w-0 break-words text-[14.5px]">{l.subject}{l.classroom ? <span className="text-ink-3"> · {l.classroom}</span> : null}</span>
                    <span className="shrink-0 text-[12.5px] text-ink-3 tabular">{date(l.date)} {time(l.starts_at)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
        <Panel title={<span className="inline-flex items-center gap-1.5"><BookOpenCheck className="size-4 text-primary" /> Bu sınıfa verdiğim ödevler</span>} actions={<Link className="text-[12.5px] font-medium text-primary hover:underline" to={`/ogretmen/odevler?durum=closed&sinif=${g.id}`}>Tümü</Link>} flush>
          {data.homework.length === 0 ? <p className="px-4 pb-4 text-[14px] text-ink-3">Henüz ödev vermediniz.</p> : (
            <ul>
              {data.homework.map((h) => (
                <li key={h.id} className="border-t border-line">
                  <Link to={`/ogretmen/odevler/${h.id}`} className="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-surface-2/60">
                    <span className="min-w-0 break-words text-[14.5px]">{h.title} <span className="text-ink-3">· {h.subject}</span></span>
                    <span className="shrink-0 text-[12.5px] text-ink-3 tabular">{date(h.due_at)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </div>
  )
}

import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Search, Star, Users } from 'lucide-react'
import { Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Input } from '@/components/ui/form'
import { Tabs } from '@/components/ui/layout'
import { PortalTitle } from '@/modules/portal/ui'
import { useTeacherQuery, type GroupMeta } from '../api'

type StudentLite = { id: number; full_name: string; student_no: string; photo_url: string | null; class_groups: string[] }

export default function TeacherClasses() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('sekme') === 'ogrenciler' ? 'students' : 'classes'
  const setTab = (t: 'classes' | 'students') => {
    const n = new URLSearchParams(params)
    if (t === 'students') n.set('sekme', 'ogrenciler')
    else n.delete('sekme')
    setParams(n, { replace: true })
  }
  const classes = useTeacherQuery<{ data: GroupMeta[] }>(['classes'], '/classes')
  const students = useTeacherQuery<{ data: StudentLite[] }>(['students', 'all'], '/students', undefined, tab === 'students')

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle title="Sınıflarım" description="Ders verdiğiniz ve danışmanı olduğunuz sınıflar ile öğrencileriniz" />
      <Tabs value={tab} onChange={setTab} tabs={[
        { value: 'classes', label: 'Sınıflar', count: classes.data?.data.length },
        { value: 'students', label: 'Öğrencilerim', count: students.data?.data.length },
      ]} />
      {tab === 'classes' ? <ClassCards q={classes} /> : <StudentSearch rows={students.data?.data} loading={students.isLoading} />}
    </div>
  )
}

function ClassCards({ q }: { q: { data?: { data: GroupMeta[] }; isLoading: boolean } }) {
  if (q.isLoading || !q.data) return <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-32" />)}</div>
  if (!q.data.data.length) return <EmptyState icon={<Users />} title="Size bağlı sınıf yok" description="Ders programında dersiniz olan sınıflar burada listelenir." />
  return (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {q.data.data.map((g) => (
        <li key={g.id}>
          <Link to={`/ogretmen/siniflar/${g.id}`} className="flex h-full flex-col gap-2 rounded-[var(--radius-lg)] bg-surface p-4 ring-1 ring-line hover:ring-line-strong">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="break-words text-[16px] font-semibold">{g.name}</p>
                <p className="break-words text-[12.5px] text-ink-3">{[g.program, g.classroom].filter(Boolean).join(' · ') || '—'}</p>
              </div>
              {g.is_advisor && <Badge tone="accent"><Star className="size-3" /> Danışmanı</Badge>}
            </div>
            <p className="text-[14px] text-ink-2"><b className="tabular">{g.student_count}</b> öğrenci</p>
            <div className="mt-auto flex flex-wrap gap-1.5">
              {g.subjects.length ? g.subjects.map((s) => (
                <span key={s.id} className="inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-[12.5px] text-ink-2">
                  <span className="size-2 rounded-full" style={{ background: s.color ?? 'var(--primary)' }} />
                  {s.name} <span className="text-ink-3 tabular">{s.weekly} saat/hafta</span>
                </span>
              )) : <span className="text-[12.5px] text-ink-3">Bu sınıfta haftalık dersiniz yok</span>}
            </div>
          </Link>
        </li>
      ))}
    </ul>
  )
}

function StudentSearch({ rows, loading }: { rows?: StudentLite[]; loading: boolean }) {
  const [q, setQ] = useState('')
  const needle = q.trim().toLocaleLowerCase('tr')
  const list = (rows ?? []).filter((s) => !needle || s.full_name.toLocaleLowerCase('tr').includes(needle) || s.student_no.startsWith(needle))
  return (
    <div className="flex flex-col gap-3">
      <Input leading={<Search className="size-4" />} placeholder="Ad ya da öğrenci no ile ara" value={q} onChange={(e) => setQ(e.target.value)} className="sm:max-w-sm" />
      {loading ? <Skeleton className="h-64" /> : list.length === 0 ? (
        <EmptyState compact icon={<Users />} title={needle ? 'Eşleşen öğrenci yok' : 'Öğrenciniz yok'} />
      ) : (
        <ul className="grid overflow-hidden rounded-[var(--radius-lg)] bg-surface ring-1 ring-line sm:grid-cols-2 lg:grid-cols-3 [&>li]:border-b [&>li]:border-line">
          {list.map((s) => (
            <li key={s.id}>
              <Link to={`/ogretmen/ogrenci/${s.id}`} className="flex items-center gap-3 px-4 py-2.5 hover:bg-surface-2/60">
                <Avatar name={s.full_name} src={s.photo_url} size={34} />
                <span className="min-w-0">
                  <span className="block break-words text-[14.5px] font-medium">{s.full_name}</span>
                  <span className="block break-words text-[12.5px] text-ink-3 tabular">{s.student_no}{s.class_groups.length ? ` · ${s.class_groups.join(', ')}` : ''}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

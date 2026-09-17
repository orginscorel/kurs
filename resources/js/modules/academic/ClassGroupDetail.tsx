import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarDays, ClipboardList, Pencil, Plus, Search, Trash2, UserMinus, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, num, time } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced } from '@/hooks/useListState'
import { DescriptionList, Panel, Tabs } from '@/components/ui/layout'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Input } from '@/components/ui/form'
import { Alert, Avatar, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog, Drawer } from '@/components/ui/overlay'
import { useAcademicOptions } from './hooks'
import { ColorChip, MiniStat } from './ui'
import { colorOf, WEEKDAYS, type ClassGroupDetailData, type WeekData } from './types'
import { ClassGroupFormDrawer } from './ClassGroupFormDrawer'
import { PersonText, PhoneText } from '@/components/ui/contact'
import { WeekGrid } from './schedule/WeekGrid'

type Tab = 'students' | 'schedule' | 'attendance' | 'exams'

export default function ClassGroupDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [tab, setTab] = useState<Tab>('students')
  const [editOpen, setEditOpen] = useState(false)
  const [addOpen, setAddOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [removeId, setRemoveId] = useState<number | null>(null)
  const options = useAcademicOptions(can('academic.manage'))

  const { data, isLoading, error } = useQuery({ queryKey: ['class-group', id], queryFn: () => api.get<ClassGroupDetailData>(`/class-groups/${id}`) })
  const week = useQuery({ queryKey: ['schedule', 'week', 'class_group', Number(id), 'current'], queryFn: () => api.get<WeekData>('/schedule/week', { view: 'class_group', id }), enabled: tab === 'schedule' && can('schedule.view') })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['class-group', id] })
    qc.invalidateQueries({ queryKey: ['class-groups'] })
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')
  const remove = useMutation({ mutationFn: (sid: number) => api.delete<{ message: string }>(`/class-groups/${id}/students/${sid}`), onSuccess: (r) => { toast.success(r.message); setRemoveId(null); invalidate() }, onError: fail })
  const del = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/class-groups/${id}`), onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['class-groups'] }); navigate('/siniflar') }, onError: (e) => { fail(e); setConfirmDelete(false) } })

  if (error) return <EmptyState title="Sınıf bulunamadı" action={<Button onClick={() => navigate('/siniflar')}>Sınıflara dön</Button>} />
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-8 w-64" /><div className="grid grid-cols-2 lg:grid-cols-4 gap-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-[76px]" />)}</div><Skeleton className="h-96" /></div>

  const g = data.group
  const att = data.attendance_30
  const attRate = att.total ? Math.round(((Number(att.present) + Number(att.late)) / att.total) * 100) : null
  const lastExam = data.exams[0]
  const manage = can('academic.manage')

  return (
    <div className="animate-fade-in">
      <nav className="mb-3 text-[12.5px] text-ink-3"><Link to="/siniflar" className="hover:text-ink">Sınıflar</Link> <span className="mx-1">/</span> <span className="text-ink-2">{g.name}</span></nav>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-[22px] font-semibold tracking-[-0.015em] leading-tight flex items-center gap-2.5">{g.name}{!g.is_active && <Badge>Pasif</Badge>}</h1>
          <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[13px] text-ink-2">
            {g.program && <ColorChip>{g.program}</ColorChip>}
            {g.term && <span>Dönem: {g.term}</span>}
            {g.homeroom && <span>· Ana derslik: {g.homeroom}</span>}
            {g.advisor && <span>· Danışman öğretmen: {g.advisor}</span>}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {can('schedule.view') && <ButtonLink to={`/ders-programi?tur=class_group&id=${g.id}`} icon={<CalendarDays className="size-4" />}>Ders programı</ButtonLink>}
          {can('homework.manage') && <ButtonLink to={`/odevler?yeni=1`} icon={<ClipboardList className="size-4" />}>Ödev ver</ButtonLink>}
          {manage && <Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button>}
          {manage && <Button variant="ghost" size="icon" aria-label="Sınıfı sil" title="Sınıfı sil" onClick={() => setConfirmDelete(true)}><Trash2 className="size-4 text-danger" /></Button>}
        </div>
      </div>

      <div className="mb-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <MiniStat label="Öğrenci / kontenjan" value={`${g.students_count}/${g.capacity}`} sub={`%${g.fill_rate} dolu`} tone={g.fill_rate >= 100 ? 'danger' : undefined} />
        <MiniStat label="Haftalık ders sayısı" value={`${data.schedules.length}`} sub={`Toplam ${Math.round(data.weekly_minutes / 60)} saat`} />
        <MiniStat label="Devam oranı (30 gün)" value={attRate === null ? '—' : `%${attRate}`} sub={att.total ? `${num(att.absent)} devamsızlık kaydı` : 'Yoklama alınmamış'} tone={attRate !== null && attRate < 80 ? 'warning' : undefined} />
        <MiniStat label="Son deneme ortalaması" value={lastExam ? `${num(lastExam.avg_net, 2)} net` : '—'} sub={lastExam ? lastExam.name : 'Yayımlanmış sonuç yok'} />
      </div>

      <Tabs value={tab} onChange={setTab} tabs={[{ value: 'students', label: 'Öğrenciler', count: g.students_count }, { value: 'schedule', label: 'Haftalık program', count: data.schedules.length }, { value: 'attendance', label: 'Yoklama özeti' }, { value: 'exams', label: 'Deneme sonuçları', count: data.exams.length || null }]} className="mb-4" />

      {tab === 'students' && (
        <Panel title="Sınıf listesi" actions={manage && <Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setAddOpen(true)} disabled={g.students_count >= g.capacity}>Öğrenci ekle</Button>} flush>
          {data.students.length === 0 ? (
            <EmptyState icon={<Users />} title="Sınıfta öğrenci yok" description="Öğrenci ekleyerek başlayın." action={manage ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setAddOpen(true)}>Öğrenci ekle</Button> : undefined} />
          ) : (
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead><tr className="border-y border-line bg-surface-2/60 text-[12.5px] text-ink-3"><th className="h-9 px-4 font-medium text-left">Öğrenci</th><th className="px-3 font-medium text-center">Kayıt durumu</th><th className="px-3 font-medium text-center">Veli</th><th className="px-3 font-medium text-center">Sınıfa katılma tarihi</th><th className="px-3 text-center" aria-label="İşlem" /></tr></thead>
                <tbody>
                  {data.students.map((s) => (
                    <tr key={s.id} className="border-b border-line last:border-0 hover:bg-surface-2/50">
                      <td className="px-4 py-2 text-left"><Link to={`/ogrenciler/${s.id}`} className="flex min-w-[200px] items-center gap-2.5 hover:text-primary"><Avatar name={s.full_name} src={s.photo_url} size={30} /><span><span className="block font-medium">{s.full_name}</span><span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {s.student_no}{s.school_grade ? ` · Okul sınıfı: ${s.school_grade}` : ''}</span></span></Link></td>
                      <td className="px-3 py-2 text-center"><Badge tone={s.status === 'active' ? 'success' : s.status === 'frozen' ? 'warning' : 'neutral'} dot>{s.status_label}</Badge></td>
                      <td className="px-3 py-2 text-ink-2 text-left">{s.guardian ? <span className="flex flex-col gap-0.5"><PersonText>{s.guardian.name}</PersonText><PhoneText value={s.guardian.phone} muted /></span> : <span className="text-ink-3">—</span>}</td>
                      <td className="px-3 py-2 text-ink-3 tabular text-center">{date(s.joined_on)}</td>
                      <td className="px-3 py-2 text-right">{manage && <Button size="icon-sm" variant="ghost" aria-label={`${s.full_name} sınıftan çıkar`} title="Sınıftan çıkar" onClick={() => setRemoveId(s.id)}><UserMinus className="size-3.5" /></Button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      {tab === 'schedule' && (
        can('schedule.view') && week.data ? (
          <>
            <WeekGrid data={week.data} canManage={false} compact onSelect={() => navigate(`/ders-programi?tur=class_group&id=${g.id}`)} />
            <p className="mt-2 text-[12.5px] text-ink-3">Düzenlemek için ders programı ekranını kullanın (sürükle-bırak, çakışma denetimi). {it(week.data.items.length)}</p>
          </>
        ) : can('schedule.view') && week.isLoading ? (
          <Skeleton className="h-[420px]" />
        ) : (
          <Panel title="Haftalık program" flush>
            {data.schedules.length === 0 ? <EmptyState compact title="Tanımlı ders yok" /> : (
              <div className="divide-y divide-line">
                {[1, 2, 3, 4, 5, 6, 7].filter((d) => data.schedules.some((s) => s.weekday === d)).map((d) => (
                  <div key={d} className="flex flex-col gap-1 px-4 py-2.5 sm:flex-row sm:items-start">
                    <p className="w-28 shrink-0 text-[12.5px] font-medium text-ink-2">{WEEKDAYS[d]}</p>
                    <div className="flex flex-wrap gap-1.5">
                      {data.schedules.filter((s) => s.weekday === d).map((s) => (
                        <span key={s.id} className="inline-flex items-center gap-1.5 rounded-[6px] border-l-[3px] bg-surface-2 px-2 py-1 text-[12px]" style={{ borderLeftColor: colorOf(s.subject_color) }}>
                          <span className="tabular text-ink-3">{s.starts_at.slice(0, 5)}</span><span className="font-medium">{s.subject}</span><span className="text-ink-3">· Öğretmen: {s.teacher || '—'} · Derslik: {s.classroom || '—'}</span>
                        </span>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Panel>
        )
      )}

      {tab === 'attendance' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Panel title="Devam durumu (son 30 gün)" description={`${att.total} yoklama kaydı`}>
            {att.total ? (
              <div className="flex flex-col gap-3">
                {[['Var', att.present, 'success'], ['Geç', att.late, 'warning'], ['Yok', att.absent, 'danger'], ['İzinli / raporlu', att.excused, 'info']].map(([label, n, tone]) => (
                  <div key={label as string}>
                    <div className="flex justify-between text-[12.5px]"><span className="text-ink-2">{label}</span><span className="tabular">{num(n as number)} kayıt · %{Math.round((Number(n) / att.total) * 100)}</span></div>
                    <ProgressBar value={(Number(n) / att.total) * 100} tone={tone as 'success'} className="mt-1" />
                  </div>
                ))}
              </div>
            ) : <p className="text-[13px] text-ink-3">Bu sınıf için son 30 günde yoklama kaydı yok.</p>}
          </Panel>
          <Panel title="Yaklaşan dersler">
            {data.upcoming.length === 0 ? <p className="text-[13px] text-ink-3">Planlanmış ders yok.</p> : (
              <ul className="flex flex-col gap-2">
                {data.upcoming.map((u) => (
                  <li key={u.id} className="flex min-w-0 items-center gap-3 text-[13px]">
                    <span className="w-[3px] h-8 rounded-full" style={{ background: colorOf(u.subject_color) }} />
                    <span className="w-[120px] shrink-0 tabular text-ink-3">{date(u.starts_at)} {time(u.starts_at)}</span>
                    <span className="font-medium">{u.subject}</span><span className="text-ink-3 truncate">· Öğretmen: {u.teacher || '—'} · Derslik: {u.classroom || '—'}</span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="Ödev özeti" className="lg:col-span-2">
            <DescriptionList columns={3} items={[{ label: 'Verilen ödev', value: num(data.homework.total) }, { label: 'Teslim edilen / atanan', value: `${num(data.homework.done)} / ${num(data.homework.assignments)}` }, { label: 'Yapılmayan teslim', value: <span className={Number(data.homework.missed) ? 'text-danger' : ''}>{num(data.homework.missed)}</span> }]} />
          </Panel>
        </div>
      )}

      {tab === 'exams' && (
        <Panel title="Yayımlanmış deneme sonuçları" flush>
          {data.exams.length === 0 ? <EmptyState compact title="Sonuç yok" description="Bu sınıf için yayımlanmış deneme sonucu bulunmuyor." /> : (
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead><tr className="border-y border-line bg-surface-2/60 text-[12.5px] text-ink-3"><th className="h-9 px-4 font-medium text-left">Deneme</th><th className="px-3 font-medium text-center">Sınav tarihi</th><th className="px-3 font-medium text-center">Ortalama net</th><th className="px-3 font-medium text-center">En yüksek net</th><th className="px-3 font-medium text-center">Katılan öğrenci</th></tr></thead>
                <tbody>{data.exams.map((e) => <tr key={e.id} className="border-b border-line last:border-0"><td className="px-4 py-2 font-medium text-left">{e.name}</td><td className="px-3 py-2 text-ink-3 tabular text-center">{date(e.exam_date)}</td><td className="px-3 py-2 tabular text-center">{num(e.avg_net, 2)}</td><td className="px-3 py-2 tabular text-center">{num(e.max_net, 2)}</td><td className="px-3 py-2 tabular text-center">{e.participants}</td></tr>)}</tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      <ClassGroupFormDrawer open={editOpen} group={g} options={options.data} onClose={() => setEditOpen(false)} onSaved={() => { invalidate(); qc.invalidateQueries({ queryKey: ['academic', 'options'] }) }} />
      <AddStudentsDrawer open={addOpen} groupId={g.id} groupName={g.name} remaining={g.capacity - g.students_count} onClose={() => setAddOpen(false)} onSaved={invalidate} />
      <ConfirmDialog open={removeId !== null} onClose={() => setRemoveId(null)} onConfirm={() => remove.mutate(removeId!)} loading={remove.isPending} danger title="Öğrenciyi sınıftan çıkar" confirmLabel="Çıkar" description="Üyelik bugünkü tarihle kapatılır; geçmiş yoklama ve sınav kayıtları korunur." />
      <ConfirmDialog open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => del.mutate()} loading={del.isPending} danger title="Sınıfı sil" confirmLabel="Sil" description="İçinde öğrenci olan sınıf silinemez. Ders şablonları kaldırılır." />
    </div>
  )
}

function it(n: number) {
  return n ? `${n} ders şablonu.` : ''
}

/** Sınıfa öğrenci ekleme: aday listesi (bu sınıfta olmayan aktif öğrenciler), çoklu seçim. */
function AddStudentsDrawer({ open, groupId, groupName, remaining, onClose, onSaved }: { open: boolean; groupId: number; groupName: string; remaining: number; onClose: () => void; onSaved: () => void }) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const { data, isLoading } = useQuery({ queryKey: ['class-group', groupId, 'candidates', debounced], queryFn: () => api.get<{ data: { id: number; student_no: string; full_name: string; school_grade: string | null; current: string }[] }>(`/class-groups/${groupId}/candidates`, { q: debounced }), enabled: open })
  const add = useMutation({
    mutationFn: () => api.post<{ message: string; errors: string[] }>(`/class-groups/${groupId}/students`, { student_ids: [...picked] }),
    onSuccess: (r) => { toast.success(r.message); r.errors.slice(0, 3).forEach((e) => toast.warning(e)); setPicked(new Set()); onClose(); onSaved() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Eklenemedi.'),
  })
  const list = useMemo(() => data?.data ?? [], [data])

  return (
    <Drawer open={open} onClose={onClose} title={`${groupName} sınıfına öğrenci ekle`} description={`${remaining} boş kontenjan · öğrencinin aynı dönemdeki önceki sınıf üyeliği kapanır`} footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" disabled={!picked.size || picked.size > remaining} loading={add.isPending} onClick={() => add.mutate()}>{picked.size ? `${picked.size} öğrenciyi ekle` : 'Ekle'}</Button></>}>
      <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad ya da öğrenci no" aria-label="Öğrenci ara" leading={<Search />} className="mb-3" />
      {picked.size > remaining && <Alert tone="danger" className="mb-3">Kontenjan {remaining} kişi; {picked.size} öğrenci seçildi. Seçimi azaltın.</Alert>}
      {isLoading ? <div className="flex flex-col gap-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10" />)}</div> : list.length === 0 ? <EmptyState compact title="Aday öğrenci yok" description="Aramayı değiştirin ya da yeni öğrenci kaydı oluşturun." /> : (
        <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
          {list.map((s) => {
            const on = picked.has(s.id)
            return (
              <li key={s.id}>
                <button type="button" onClick={() => setPicked((p) => { const n = new Set(p); on ? n.delete(s.id) : n.add(s.id); return n })} className={`flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors ${on ? 'bg-primary-soft/50' : 'hover:bg-surface-2'}`}>
                  <span className={`grid size-4 place-items-center rounded-[5px] border ${on ? 'bg-primary border-primary text-white' : 'border-line-strong'}`}>{on && <span className="size-2 rounded-sm bg-white" />}</span>
                  <Avatar name={s.full_name} size={26} />
                  <span className="min-w-0 flex-1"><span className="block truncate text-[13px] font-medium">{s.full_name}</span><span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {s.student_no}{s.school_grade ? ` · Okul sınıfı: ${s.school_grade}` : ''}{s.current ? ` · Şu anki sınıfı: ${s.current}` : ''}</span></span>
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </Drawer>
  )
}

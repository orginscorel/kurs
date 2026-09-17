import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowDown, ArrowUp, Check, CornerDownRight, Pencil, Plus, Save, Trash2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Checkbox, Input } from '@/components/ui/form'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog } from '@/components/ui/overlay'
import { useAcademicOptions } from './hooks'
import { ColorChip, MiniStat } from './ui'
import type { SubjectDetailData, TopicRow } from './types'
import { SubjectFormDrawer } from './AcademicStructure'

export default function SubjectDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const manage = can('academic.manage')
  const options = useAcademicOptions()
  const { data, isLoading, error } = useQuery({ queryKey: ['subject', id], queryFn: () => api.get<SubjectDetailData>(`/subjects/${id}`) })
  const [editOpen, setEditOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [teacherIds, setTeacherIds] = useState<number[]>([])
  const [teachersDirty, setTeachersDirty] = useState(false)
  const [adding, setAdding] = useState<{ parent_id: number | null } | null>(null)
  const [draft, setDraft] = useState({ name: '', outcome_code: '' })
  const [editingTopic, setEditingTopic] = useState<number | null>(null)
  const [deleteTopic, setDeleteTopic] = useState<TopicRow | null>(null)

  useEffect(() => {
    if (data) {
      setTeacherIds(data.teachers.map((t) => t.id))
      setTeachersDirty(false)
    }
  }, [data])

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['subject', id] })
    qc.invalidateQueries({ queryKey: ['subjects'] })
    qc.invalidateQueries({ queryKey: ['academic', 'options'] })
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')
  const addTopic = useMutation({ mutationFn: () => api.post<{ message: string }>(`/subjects/${id}/topics`, { name: draft.name, outcome_code: draft.outcome_code || null, parent_id: adding?.parent_id ?? null }), onSuccess: (r) => { toast.success(r.message); setAdding(null); setDraft({ name: '', outcome_code: '' }); invalidate() }, onError: fail })
  const updTopic = useMutation({ mutationFn: (t: { id: number; name: string; outcome_code: string; parent_id: number | null }) => api.put<{ message: string }>(`/subjects/${id}/topics/${t.id}`, { name: t.name, outcome_code: t.outcome_code || null, parent_id: t.parent_id }), onSuccess: (r) => { toast.success(r.message); setEditingTopic(null); invalidate() }, onError: fail })
  const delTopic = useMutation({ mutationFn: (tid: number) => api.delete<{ message: string }>(`/subjects/${id}/topics/${tid}`), onSuccess: (r) => { toast.success(r.message); setDeleteTopic(null); invalidate() }, onError: (e) => { fail(e); setDeleteTopic(null) } })
  const reorder = useMutation({ mutationFn: (items: { id: number; sort: number; parent_id: number | null }[]) => api.put<{ message: string }>(`/subjects/${id}/topics/reorder`, { items }), onSuccess: () => invalidate(), onError: fail })
  const saveTeachers = useMutation({ mutationFn: () => api.put<{ message: string }>(`/subjects/${id}/teachers`, { teacher_ids: teacherIds }), onSuccess: (r) => { toast.success(r.message); invalidate() }, onError: fail })
  const del = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/subjects/${id}`), onSuccess: (r) => { toast.success(r.message); invalidate(); navigate('/akademik?sekme=dersler') }, onError: (e) => { fail(e); setConfirmDelete(false) } })

  const tree = useMemo(() => {
    const topics = data?.topics ?? []
    const roots = topics.filter((t) => !t.parent_id)
    return roots.map((r) => ({ topic: r, children: topics.filter((t) => t.parent_id === r.id) }))
  }, [data])

  if (error) return <EmptyState title="Ders bulunamadı" action={<Button onClick={() => navigate('/akademik?sekme=dersler')}>Derslere dön</Button>} />
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-8 w-64" /><Skeleton className="h-24" /><Skeleton className="h-80" /></div>

  const s = data.subject
  const move = (list: TopicRow[], index: number, dir: -1 | 1) => {
    const target = index + dir
    if (target < 0 || target >= list.length) return
    const copy = [...list]
    const [item] = copy.splice(index, 1)
    copy.splice(target, 0, item!)
    reorder.mutate(copy.map((t, i) => ({ id: t.id, sort: i, parent_id: t.parent_id })))
  }

  const TopicLine = ({ t, siblings, index, depth }: { t: TopicRow; siblings: TopicRow[]; index: number; depth: number }) => {
    const [name, setName] = useState(t.name)
    const [code, setCode] = useState(t.outcome_code ?? '')
    const isEditing = editingTopic === t.id
    return (
      <div className={`flex items-center gap-2 py-1.5 ${depth ? 'pl-8' : ''}`}>
        {depth > 0 && <CornerDownRight className="size-3.5 text-ink-3 shrink-0" />}
        {isEditing ? (
          <>
            <Input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Kazanım kodu" className="w-[130px]" />
            <Input value={name} onChange={(e) => setName(e.target.value)} className="flex-1" autoFocus onKeyDown={(e) => e.key === 'Enter' && updTopic.mutate({ id: t.id, name, outcome_code: code, parent_id: t.parent_id })} />
            <Button size="icon-sm" variant="ghost" aria-label="Kaydet" loading={updTopic.isPending} onClick={() => updTopic.mutate({ id: t.id, name, outcome_code: code, parent_id: t.parent_id })}><Check className="size-4 text-success" /></Button>
            <Button size="icon-sm" variant="ghost" aria-label="Vazgeç" onClick={() => setEditingTopic(null)}><X className="size-4" /></Button>
          </>
        ) : (
          <>
            {t.outcome_code && <span className="w-[90px] shrink-0 font-mono text-[12px] text-ink-3">{t.outcome_code}</span>}
            <span className="flex-1 truncate text-[13.5px]">{t.name}</span>
            {t.success_rate !== null && <Badge tone={t.success_rate >= 70 ? 'success' : t.success_rate >= 50 ? 'warning' : 'danger'}>%{t.success_rate} başarı</Badge>}
            {t.asked > 0 && <span className="text-[12px] text-ink-3 tabular hidden sm:inline">{t.asked} soru</span>}
            {manage && (
              <span className="flex items-center gap-0.5 opacity-60 hover:opacity-100">
                <Button size="icon-sm" variant="ghost" aria-label="Yukarı" disabled={index === 0} onClick={() => move(siblings, index, -1)}><ArrowUp className="size-3.5" /></Button>
                <Button size="icon-sm" variant="ghost" aria-label="Aşağı" disabled={index === siblings.length - 1} onClick={() => move(siblings, index, 1)}><ArrowDown className="size-3.5" /></Button>
                {depth === 0 && <Button size="icon-sm" variant="ghost" aria-label="Alt konu ekle" onClick={() => { setAdding({ parent_id: t.id }); setDraft({ name: '', outcome_code: '' }) }}><Plus className="size-3.5" /></Button>}
                <Button size="icon-sm" variant="ghost" aria-label="Düzenle" onClick={() => setEditingTopic(t.id)}><Pencil className="size-3.5" /></Button>
                <Button size="icon-sm" variant="ghost" aria-label="Sil" onClick={() => setDeleteTopic(t)}><Trash2 className="size-3.5 text-danger" /></Button>
              </span>
            )}
          </>
        )}
      </div>
    )
  }

  const AddLine = ({ depth }: { depth: number }) => (
    <div className={`flex items-center gap-2 py-1.5 ${depth ? 'pl-8' : ''}`}>
      <Input value={draft.outcome_code} onChange={(e) => setDraft((d) => ({ ...d, outcome_code: e.target.value }))} placeholder="Kod" className="w-[110px]" />
      <Input value={draft.name} onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))} placeholder={depth ? 'Alt konu / kazanım adı' : 'Konu adı'} className="flex-1" autoFocus onKeyDown={(e) => e.key === 'Enter' && draft.name && addTopic.mutate()} />
      <Button size="sm" variant="primary" disabled={!draft.name.trim()} loading={addTopic.isPending} onClick={() => addTopic.mutate()}>Ekle</Button>
      <Button size="icon-sm" variant="ghost" aria-label="Vazgeç" onClick={() => setAdding(null)}><X className="size-4" /></Button>
    </div>
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Dersler', to: '/akademik?sekme=dersler' }, { label: s.name }]}
        title={<span className="flex items-center gap-2.5">{s.name}<ColorChip color={s.color}>{s.short_name ?? s.code}</ColorChip>{!s.is_active && <Badge>Pasif</Badge>}</span>}
        description="Konu ağacı, kazanım kodları ve dersi veren öğretmenler"
        actions={manage && <><Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button><Button variant="ghost" size="icon" aria-label="Sil" onClick={() => setConfirmDelete(true)}><Trash2 className="size-4 text-danger" /></Button></>}
      />
      <div className="mb-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <MiniStat label="Konu / kazanım" value={data.topics.length} />
        <MiniStat label="Öğretmen" value={data.teachers.length} />
        <MiniStat label="Program" value={data.programs.length} sub={`${data.programs.reduce((a, p) => a + p.weekly_hours, 0)} saat / hafta toplam`} />
        <MiniStat label="Sınav sorusu" value={data.topics.reduce((a, t) => a + t.asked, 0)} sub="konu analizine giren" />
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px] gap-4 items-start">
        <Panel title="Konular ve kazanımlar" description="Sıralama müfredat akışını belirler; alt konular kazanım kodu taşıyabilir" actions={manage && !adding && <Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => { setAdding({ parent_id: null }); setDraft({ name: '', outcome_code: '' }) }}>Konu ekle</Button>}>
          {tree.length === 0 && !adding ? (
            <EmptyState compact title="Konu tanımlı değil" description="Konu ekleyerek müfredatı oluşturun; sınav analizleri buna göre çalışır." action={manage ? <Button variant="primary" size="sm" icon={<Plus className="size-4" />} onClick={() => setAdding({ parent_id: null })}>Konu ekle</Button> : undefined} />
          ) : (
            <div className="divide-y divide-line">
              {tree.map(({ topic, children }, i) => (
                <div key={topic.id}>
                  <TopicLine t={topic} siblings={tree.map((x) => x.topic)} index={i} depth={0} />
                  {children.map((c, j) => <TopicLine key={c.id} t={c} siblings={children} index={j} depth={1} />)}
                  {adding?.parent_id === topic.id && <AddLine depth={1} />}
                </div>
              ))}
              {adding && adding.parent_id === null && <AddLine depth={0} />}
            </div>
          )}
        </Panel>

        <div className="flex flex-col gap-4">
          <Panel title="Öğretmenler" description={teachersDirty ? 'Kaydedilmemiş değişiklik' : 'Bu dersi verebilecek öğretmenler'} actions={manage && <Button size="xs" variant="primary" icon={<Save className="size-3.5" />} disabled={!teachersDirty} loading={saveTeachers.isPending} onClick={() => saveTeachers.mutate()}>Kaydet</Button>}>
            {manage ? (
              <div className="flex max-h-80 flex-col gap-1.5 overflow-y-auto scroll-thin">
                {(options.data?.teachers ?? []).filter((t) => t.is_active || teacherIds.includes(t.id)).map((t) => (
                  <Checkbox key={t.id} checked={teacherIds.includes(t.id)} onChange={(on) => { setTeacherIds((l) => (on ? [...l, t.id] : l.filter((x) => x !== t.id))); setTeachersDirty(true) }} label={<span>{t.name}{t.title ? <span className="text-ink-3"> · {t.title}</span> : null}</span>} />
                ))}
              </div>
            ) : data.teachers.length === 0 ? <p className="text-[13px] text-ink-3">Atanmış öğretmen yok.</p> : (
              <ul className="flex flex-col gap-1.5 text-[13px]">{data.teachers.map((t) => <li key={t.id}>{t.name}<span className="text-ink-3"> · {t.title ?? ''}</span></li>)}</ul>
            )}
          </Panel>
          <Panel title="Kullanıldığı programlar">
            {data.programs.length === 0 ? <p className="text-[13px] text-ink-3">Hiçbir programa eklenmemiş.</p> : (
              <ul className="flex flex-col gap-2 text-[13px]">{data.programs.map((p) => <li key={p.id} className="flex items-center justify-between"><Link to={`/akademik/programlar/${p.id}`} className="hover:text-primary"><ColorChip>{p.name}</ColorChip></Link><span className="tabular text-ink-3">{p.weekly_hours} saat/hafta</span></li>)}</ul>
            )}
          </Panel>
        </div>
      </div>

      <SubjectFormDrawer open={editOpen} subject={s} onClose={() => setEditOpen(false)} onSaved={invalidate} />
      <ConfirmDialog open={!!deleteTopic} onClose={() => setDeleteTopic(null)} onConfirm={() => delTopic.mutate(deleteTopic!.id)} loading={delTopic.isPending} danger title="Konuyu sil" confirmLabel="Sil" description={deleteTopic ? `"${deleteTopic.name}" konusu silinecek. Sınav soruları ve istatistikler bu konuyla bağını yitirir.` : undefined} />
      <ConfirmDialog open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => del.mutate()} loading={del.isPending} danger title="Dersi sil" confirmLabel="Sil" description="Ders programında ya da ödevlerde kullanılıyorsa silinemez; pasife alın." />
    </div>
  )
}

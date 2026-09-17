import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Pencil, Plus, Save, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { money } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { ConfirmDialog } from '@/components/ui/overlay'
import { useAcademicOptions } from './hooks'
import { ColorChip, MiniStat } from './ui'
import type { ProgramDetailData } from './types'
import { ProgramFormDrawer } from './AcademicStructure'

type Row = { subject_id: number; weekly_hours: number; curriculum: string }

export default function ProgramDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const manage = can('academic.manage')
  const options = useAcademicOptions()
  const { data, isLoading, error } = useQuery({ queryKey: ['program', id], queryFn: () => api.get<ProgramDetailData>(`/programs/${id}`) })
  const [rows, setRows] = useState<Row[]>([])
  const [dirty, setDirty] = useState(false)
  const [editOpen, setEditOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  useEffect(() => {
    if (data) {
      setRows(data.subjects.map((s) => ({ subject_id: s.id, weekly_hours: s.weekly_hours, curriculum: s.curriculum ?? '' })))
      setDirty(false)
    }
  }, [data])

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['program', id] })
    qc.invalidateQueries({ queryKey: ['programs'] })
    qc.invalidateQueries({ queryKey: ['academic', 'options'] })
  }
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.')
  const save = useMutation({ mutationFn: () => api.put<{ message: string }>(`/programs/${id}/subjects`, { subjects: rows.map((r) => ({ ...r, curriculum: r.curriculum || null })) }), onSuccess: (r) => { toast.success(r.message); invalidate() }, onError: fail })
  const del = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/programs/${id}`), onSuccess: (r) => { toast.success(r.message); invalidate(); navigate('/akademik?sekme=programlar') }, onError: (e) => { fail(e); setConfirmDelete(false) } })

  if (error) return <EmptyState title="Program bulunamadı" action={<Button onClick={() => navigate('/akademik')}>Programlara dön</Button>} />
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-8 w-64" /><Skeleton className="h-24" /><Skeleton className="h-80" /></div>

  const p = data.program
  const totalHours = rows.reduce((a, r) => a + (Number(r.weekly_hours) || 0), 0)
  const available = (options.data?.subjects ?? []).filter((s) => s.is_active && !rows.some((r) => r.subject_id === s.id))
  const subjectOf = (sid: number) => options.data?.subjects.find((s) => s.id === sid) ?? data.subjects.find((s) => s.id === sid)
  const update = (i: number, patch: Partial<Row>) => { setRows((l) => l.map((r, j) => (j === i ? { ...r, ...patch } : r))); setDirty(true) }

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Programlar', to: '/akademik?sekme=programlar' }, { label: p.name }]}
        title={<span className="flex items-center gap-2.5">{p.name}<ColorChip>{p.code}</ColorChip>{!p.is_active && <Badge>Pasif</Badge>}</span>}
        description={`${p.kind_label}${p.track_label ? ` · ${p.track_label}` : ''}${p.description ? ` · ${p.description}` : ''}`}
        actions={manage && <><Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button><Button variant="ghost" size="icon" aria-label="Sil" onClick={() => setConfirmDelete(true)}><Trash2 className="size-4 text-danger" /></Button></>}
      />

      <div className="mb-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <MiniStat label="Ders" value={rows.length} sub={`${totalHours} saat / hafta`} />
        <MiniStat label="Sınıf" value={data.class_groups.length} sub={`${data.class_groups.reduce((a, g) => a + g.students_count, 0)} öğrenci`} />
        <MiniStat label="Öğretmen" value={data.teachers.length} />
        <MiniStat label="Paket" value={data.packages.length} sub={data.packages[0] ? money(data.packages[0].list_price, { short: true }) : undefined} />
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_360px] gap-4 items-start">
        <Panel
          title="Dersler ve haftalık saatler"
          description={dirty ? 'Kaydedilmemiş değişiklik var' : 'Programa bağlı dersler, saat ve müfredat notu'}
          actions={manage && (
            <>
              <Select value="" onChange={(e) => { if (e.target.value) { setRows((l) => [...l, { subject_id: Number(e.target.value), weekly_hours: 2, curriculum: '' }]); setDirty(true) } }} placeholder="Ders ekle" options={available.map((s) => ({ value: s.id, label: s.name }))} className="w-[170px]" />
              <Button size="sm" variant="primary" icon={<Save className="size-3.5" />} disabled={!dirty} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
            </>
          )}
          flush
        >
          {rows.length === 0 ? (
            <EmptyState compact icon={<Plus />} title="Ders eklenmemiş" description="Sağ üstten ders ekleyip haftalık saatini girin." />
          ) : (
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead><tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3"><th className="h-9 px-4 font-medium text-left">Ders</th><th className="px-3 font-medium w-[110px] text-center">Saat / hafta</th><th className="fill px-3 font-medium text-center">Müfredat notu</th>{manage && <th className="w-10 text-center" />}</tr></thead>
                <tbody>
                  {rows.map((r, i) => {
                    const s = subjectOf(r.subject_id)
                    return (
                      <tr key={r.subject_id} className="border-b border-line last:border-0">
                        <td className="px-4 py-2 text-left"><Link to={`/akademik/dersler/${r.subject_id}`} className="hover:text-primary"><ColorChip color={s?.color}>{s?.name ?? '—'}</ColorChip></Link></td>
                        <td className="px-3 py-2 text-center">{manage ? <Input type="number" min={0} max={40} value={r.weekly_hours} onChange={(e) => update(i, { weekly_hours: Number(e.target.value) })} className="w-[80px]" /> : <span className="tabular">{r.weekly_hours}</span>}</td>
                        <td className="fill px-3 py-2 text-center">{manage ? <Input value={r.curriculum} onChange={(e) => update(i, { curriculum: e.target.value })} placeholder="Konu sırası, kaynak, hedef" /> : <span className="text-ink-2">{r.curriculum || '—'}</span>}</td>
                        {manage && <td className="px-2 py-2 text-right"><Button size="icon-sm" variant="ghost" aria-label="Kaldır" onClick={() => { setRows((l) => l.filter((_, j) => j !== i)); setDirty(true) }}><Trash2 className="size-3.5 text-danger" /></Button></td>}
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <div className="flex flex-col gap-4">
          <Panel title="Sınıflar" description={`${data.class_groups.length} sınıf`}>
            {data.class_groups.length === 0 ? <p className="text-[13px] text-ink-3">Bu programa bağlı sınıf yok.</p> : (
              <ul className="flex flex-col gap-2.5">
                {data.class_groups.map((g) => (
                  <li key={g.id}>
                    <Link to={`/siniflar/${g.id}`} className="block rounded-[var(--radius-sm)] px-2 py-1.5 -mx-2 hover:bg-surface-2">
                      <div className="flex items-center justify-between text-[13px]"><span className="font-medium">{g.name}</span><span className="tabular text-ink-3">{g.students_count}/{g.capacity}</span></div>
                      <ProgressBar value={g.capacity ? (g.students_count / g.capacity) * 100 : 0} className="mt-1" />
                      <p className="mt-1 text-[12px] text-ink-3">{g.term}{g.homeroom ? ` · ${g.homeroom}` : ''}{g.advisor ? ` · ${g.advisor}` : ''}</p>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title="Öğretmenler" description="Ders programından türetilir">
            {data.teachers.length === 0 ? <p className="text-[13px] text-ink-3">Henüz bu programda ders veren öğretmen yok.</p> : (
              <ul className="flex flex-col gap-2">
                {data.teachers.map((t) => (
                  <li key={t.id} className="flex items-center justify-between gap-2 text-[13px]">
                    <span className="min-w-0"><span className="block truncate font-medium">{t.name}</span><span className="block truncate text-[12px] text-ink-3">{t.subjects.join(', ')}</span></span>
                    {t.lessons > 0 && <span className="shrink-0 tabular text-ink-3">{t.lessons} ders/hafta</span>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          {data.packages.length > 0 && (
            <Panel title="Eğitim paketleri">
              <ul className="flex flex-col gap-1.5 text-[13px]">{data.packages.map((pk) => <li key={pk.id} className="flex justify-between"><span>{pk.name}</span><span className="tabular text-ink-2">{money(pk.list_price)} · {pk.default_installments} taksit</span></li>)}</ul>
            </Panel>
          )}
        </div>
      </div>

      <ProgramFormDrawer open={editOpen} program={p} onClose={() => setEditOpen(false)} onSaved={invalidate} />
      <ConfirmDialog open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => del.mutate()} loading={del.isPending} danger title="Programı sil" confirmLabel="Sil" description="Bağlı sınıf ya da kayıt varsa silinemez; bunun yerine pasife alın." />
    </div>
  )
}

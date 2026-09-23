import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Pencil, Plus, Search, Target, Trash2 } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { num } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useListState, useDebounced } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { EmptyState, ProgressBar } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import type { GoalCompare, RiskOptions, StudentGoal } from './types'
import { GoalFormDrawer } from './GoalFormDrawer'

function CompareCell({ label, c }: { label: string; c: GoalCompare }) {
  if (c.target === null) return <span className="text-ink-3">—</span>
  const tone = c.pct === null ? 'primary' : c.pct >= 90 ? 'success' : c.pct >= 70 ? 'warning' : 'danger'
  return (
    <div className="min-w-0">
      <div className="flex items-center justify-between text-[12px] text-ink-2">
        <span title={`${label}: son deneme / hedef`}>{c.actual !== null ? num(c.actual, 1) : '—'} / {num(c.target, 1)}</span>
        {c.pct !== null && <span className="tabular font-medium">%{num(c.pct, 0)}</span>}
      </div>
      <ProgressBar value={c.pct ?? 0} tone={tone} className="mt-1" />
    </div>
  )
}

export default function GoalTracking() {
  const can = useCan()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const list = useListState({ filters: {} })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)
  const [formFor, setFormFor] = useState<{ goal?: StudentGoal; studentId: number; studentName: string } | 'new' | null>(null)
  const [deleteGoal, setDeleteGoal] = useState<StudentGoal | null>(null)

  // Sınıf filtresi hedeflerin kendi ucundan (guidance.view); /risk/options risk.view ister, öğretmende 403 verirdi.
  const options = useQuery({ queryKey: ['guidance', 'goals', 'options'], queryFn: () => api.get<Pick<RiskOptions, 'class_groups'>>('/guidance/goals/options'), staleTime: 5 * 60_000 })

  const query = { q: debounced || undefined, class_group_id: list.filters.class_group_id, page: list.page }
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['guidance', 'goals', 'list', query],
    queryFn: () => api.get<Paginated<StudentGoal>>('/guidance/goals', query),
    placeholderData: keepPreviousData,
  })

  const del = useMutation({
    mutationFn: (id: number) => api.delete(`/guidance/goals/${id}`),
    onSuccess: () => { toast.success('Hedef silindi.'); setDeleteGoal(null); qc.invalidateQueries({ queryKey: ['guidance', 'goals'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const columns = useMemo<Column<StudentGoal>[]>(() => [
    {
      key: 'student', header: 'Öğrenci',
      cell: (g) => (
        <button className="block min-w-0 max-w-full text-left" onClick={() => navigate(`/ogrenciler/${g.student?.id}`)}>
          <p className="truncate font-medium text-ink hover:text-primary" title={g.student?.full_name}>{g.student?.full_name}</p>
          <p className="text-[12px] text-ink-3 tabular">Öğrenci no: {g.student?.student_no}</p>
        </button>
      ),
    },
    { key: 'target', header: 'Hedef üniversite / bölüm', hideable: true, truncate: true, maxWidth: 280, title: (g) => [g.university, g.department].filter(Boolean).join(' — ') || undefined, cell: (g) => <span className="text-ink-2">{[g.university, g.department].filter(Boolean).join(' — ') || '—'}{g.target_rank ? ` · Hedef sıra: ${num(g.target_rank)}` : ''}</span> },
    { key: 'tyt', header: 'TYT neti (son / hedef)', cell: (g) => g.progress ? <CompareCell label="TYT" c={g.progress.tyt} /> : <span className="text-ink-3">—</span> },
    { key: 'ayt', header: 'AYT neti (son / hedef)', cell: (g) => g.progress ? <CompareCell label="AYT" c={g.progress.ayt} /> : <span className="text-ink-3">—</span> },
    {
      key: 'actions', header: '', align: 'right',
      cell: (g) => can('guidance.manage') && (
        <div className="flex justify-end gap-1" onClick={(e) => e.stopPropagation()}>
          <Button size="icon-sm" variant="ghost" onClick={() => setFormFor({ goal: g, studentId: g.student!.id, studentName: g.student!.full_name })} aria-label="Hedefi düzenle" title="Düzenle"><Pencil className="size-3.5" /></Button>
          <Button size="icon-sm" variant="danger-soft" onClick={() => setDeleteGoal(g)} aria-label="Hedefi sil" title="Sil"><Trash2 className="size-3.5" /></Button>
        </div>
      ),
    },
  ], [can, navigate])

  return (
    <div className="animate-fade-in">
      <PageHeader title="Hedef Takibi" description="Üniversite hedefleri ve son 3 sınav ortalamasına göre gerçekleşme"
        actions={can('guidance.manage') && <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setFormFor('new')}>Yeni hedef</Button>} />

      <DataTable
        storageKey="guidance-goals"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="flex w-full flex-wrap items-center gap-2">
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Öğrenci ara" aria-label="Öğrenci ara" leading={<Search />} className="w-full sm:w-[240px]" />
            <Select value={list.filters.class_group_id ?? ''} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar"
              options={(options.data?.class_groups ?? []).map((c) => ({ value: c.id, label: c.name }))} className="w-full sm:w-[180px]" />
          </div>
        }
        empty={<EmptyState icon={<Target />} title="Hedef kaydı yok" description="Bir öğrenciye hedef ekleyerek başlayın." />}
      />

      {formFor === 'new' ? (
        <StudentPickForGoal onPick={(id, name) => setFormFor({ studentId: id, studentName: name })} onClose={() => setFormFor(null)} />
      ) : (
        <GoalFormDrawer
          open={!!formFor}
          onClose={() => setFormFor(null)}
          onSaved={() => setFormFor(null)}
          studentId={(formFor as any)?.studentId}
          studentName={(formFor as any)?.studentName}
          goal={(formFor as any)?.goal}
        />
      )}

      <ConfirmDialog open={!!deleteGoal} onClose={() => setDeleteGoal(null)} onConfirm={() => del.mutate(deleteGoal!.id)} title="Hedefi sil" danger loading={del.isPending}
        description={`${deleteGoal?.student?.full_name} hedefini silmek istediğinize emin misiniz?`} />
    </div>
  )
}

function StudentPickForGoal({ onPick, onClose }: { onPick: (id: number, name: string) => void; onClose: () => void }) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const results = useQuery({
    queryKey: ['students', 'quick-search', debounced],
    queryFn: () => api.get<Paginated<{ id: number; full_name: string; student_no: string }>>('/students', { q: debounced, per_page: 8 }),
    enabled: debounced.length >= 2,
  })

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/25 p-4 pt-24" onClick={onClose}>
      <div className="w-full max-w-sm rounded-[var(--radius-lg)] bg-surface ring-1 ring-line p-4 shadow-[var(--shadow-pop)]" onClick={(e) => e.stopPropagation()}>
        <p className="mb-3 text-[13.5px] font-semibold text-ink">Hedef eklenecek öğrenciyi seçin</p>
        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Ad ya da numara ile ara" leading={<Search />} autoFocus />
        <div className="mt-2 max-h-64 overflow-y-auto scroll-thin">
          {(results.data?.data ?? []).map((s) => (
            <button key={s.id} type="button" className="flex w-full items-center justify-between rounded-[var(--radius-sm)] px-2.5 py-2 text-left text-[13px] hover:bg-surface-2" onClick={() => onPick(s.id, s.full_name)}>
              <span className="text-ink">{s.full_name}</span>
              <span className="text-ink-3 tabular">{s.student_no}</span>
            </button>
          ))}
        </div>
        <div className="mt-3 flex justify-end"><Button size="sm" variant="ghost" onClick={onClose}>Vazgeç</Button></div>
      </div>
    </div>
  )
}

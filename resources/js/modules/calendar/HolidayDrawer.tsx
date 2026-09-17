import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarOff, Pencil, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, todayISO } from '@/lib/format'
import { useCan } from '@/app/auth'
import { ConfirmDialog, Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Switch, Textarea } from '@/components/ui/form'
import { Alert, EmptyState, Skeleton } from '@/components/ui/feedback'
import { addDays } from '../academic/types'

type HolidayRow = { id: number; name: string; kind: 'official' | 'institution'; kind_label: string; starts_on: string; ends_on: string; days: number; cancel_sessions: boolean; cancelled_count: number; notes: string | null }

/** Tatiller: resmi / kurum tatili. Eklenince aralıktaki dersler gerekçeyle iptal edilir, öğrencilere tek toplu bildirim gider. */
export function HolidayDrawer({ onClose, onChanged }: { onClose: () => void; onChanged: () => void }) {
  const can = useCan()
  const manage = can('schedule.manage')
  const from = addDays(todayISO(), -60)
  const q = useQuery({ queryKey: ['calendar', 'holidays', from], queryFn: () => api.get<{ data: HolidayRow[] }>('/holidays', { from }) })
  // null: kapalı · 'new': yeni tatil · satır: düzenleme
  const [editing, setEditing] = useState<HolidayRow | 'new' | null>(null)
  const adding = editing !== null
  const blank = { name: '', kind: 'official' as HolidayRow['kind'], starts_on: todayISO(), ends_on: todayISO(), cancel_sessions: true, notes: '' }
  const [form, setForm] = useState(blank)
  const startNew = () => {
    setForm(blank)
    setEditing('new')
  }
  const startEdit = (h: HolidayRow) => {
    setForm({ name: h.name, kind: h.kind, starts_on: h.starts_on, ends_on: h.ends_on, cancel_sessions: h.cancel_sessions, notes: h.notes ?? '' })
    setEditing(h)
  }
  const [removing, setRemoving] = useState<HolidayRow | null>(null)

  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const err = (k: string) => errors[k]?.[0]
  const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.firstError() : 'İşlem tamamlanamadı.')
  const refresh = () => {
    q.refetch()
    onChanged()
  }
  const create = useMutation({
    mutationFn: () => {
      const payload = { ...form, notes: form.notes || null }
      return editing && editing !== 'new' ? api.put<{ message: string }>(`/holidays/${editing.id}`, payload) : api.post<{ message: string }>('/holidays', payload)
    },
    onSuccess: (r) => {
      toast.success(r.message)
      setErrors({})
      setEditing(null)
      setForm(blank)
      refresh()
    },
    onError: (e) => { setErrors(e instanceof ApiError ? e.errors : {}); fail(e) },
  })
  const remove = useMutation({ mutationFn: (id: number) => api.delete<{ message: string }>(`/holidays/${id}`), onSuccess: (r) => { toast.success(r.message); setRemoving(null); refresh() }, onError: fail })

  return (
    <>
      <Drawer
        open
        onClose={onClose}
        title="Tatiller"
        description="Resmi ve kurum tatilleri. Tatile denk gelen dersler otomatik iptal edilir; tatil silinirse gelecekteki dersler geri gelir."
        footer={manage && !adding ? <Button variant="primary" icon={<Plus className="size-4" />} onClick={startNew}>Tatil ekle</Button> : undefined}
      >
        <div className="flex flex-col gap-4">
          {adding && (
            <section className="flex flex-col gap-3 rounded-[var(--radius-md)] p-3 ring-1 ring-line">
              <p className="text-[13px] font-semibold text-ink">{editing === 'new' ? 'Yeni tatil' : 'Tatili düzenle'}</p>
              <Field label="Tatil adı" required error={err('name')}><Input autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Örn. Cumhuriyet Bayramı" /></Field>
              <Field label="Tatil türü" required error={err('kind')}><Segmented value={form.kind} onChange={(kind) => setForm({ ...form, kind })} options={[{ value: 'official', label: 'Resmi tatil' }, { value: 'institution', label: 'Kurum tatili' }]} /></Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Başlangıç tarihi" required error={err('starts_on')}><Input type="date" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value, ends_on: e.target.value > form.ends_on ? e.target.value : form.ends_on })} /></Field>
                <Field label="Bitiş tarihi" required error={err('ends_on')}><Input type="date" min={form.starts_on} value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} /></Field>
              </div>
              <Switch checked={form.cancel_sessions} onChange={(cancel_sessions) => setForm({ ...form, cancel_sessions })} label="Bu tarihlerdeki dersleri iptal et ve öğrencilere bildir" />
              <Field label="Not" optional error={err('notes')}><Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
              {form.cancel_sessions && <Alert tone="neutral">Yoklaması alınmış dersler iptal edilmez. Etkilenen her öğrenciye tek bildirim gider.</Alert>}
              {editing !== 'new' && editing && <Alert tone="neutral">Kaydedince bu tatil nedeniyle iptal edilen gelecekteki dersler önce geri alınır, sonra yeni tarihlere göre yeniden iptal edilir.</Alert>}
              <div className="flex justify-end gap-2">
                <Button variant="ghost" onClick={() => setEditing(null)}>Vazgeç</Button>
                <Button variant="primary" disabled={form.name.trim().length < 2} loading={create.isPending} onClick={() => create.mutate()}>Kaydet</Button>
              </div>
            </section>
          )}

          {q.isLoading ? (
            <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-14" />)}</div>
          ) : !q.data?.data.length ? (
            <EmptyState compact icon={<CalendarOff />} title="Kayıtlı tatil yok" description="Resmi tatilleri ve kurum tatillerini ekleyerek dersleri toplu iptal edebilirsiniz."
              action={manage && !adding ? <Button size="sm" icon={<Plus className="size-4" />} onClick={startNew}>Tatil ekle</Button> : undefined} />
          ) : (
            <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line">
              {q.data.data.map((h) => (
                <li key={h.id} className="flex items-center gap-3 px-3 py-2.5">
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13.5px] font-medium">{h.name}</p>
                    <p className="text-[12px] tabular text-ink-3">{fmtDate(h.starts_on)}{h.days > 1 ? ` – ${fmtDate(h.ends_on)} · ${h.days} gün` : ''} · {h.kind_label}{h.cancel_sessions ? ` · ${h.cancelled_count} ders iptal edildi` : ' · Dersler yapılır'}</p>
                  </div>
                  {manage && (
                    <div className="flex shrink-0 gap-0.5">
                      <Button size="icon-sm" variant="ghost" aria-label={`${h.name} düzenle`} title="Düzenle" onClick={() => startEdit(h)}><Pencil className="size-4" /></Button>
                      <Button size="icon-sm" variant="ghost" aria-label="Sil" onClick={() => setRemoving(h)}><Trash2 className="size-4" /></Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </Drawer>
      <ConfirmDialog
        open={!!removing}
        onClose={() => setRemoving(null)}
        onConfirm={() => removing && remove.mutate(removing.id)}
        loading={remove.isPending}
        danger
        title="Tatili sil"
        confirmLabel="Sil"
        description="Gelecekteki tarihlerde bu tatil nedeniyle iptal edilen dersler yeniden planlanmış duruma döner. Öğrencilere otomatik bildirim gitmez."
      />
    </>
  )
}

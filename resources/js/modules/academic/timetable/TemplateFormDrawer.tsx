import { useMemo, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Copy, Plus, Trash2, Wand2, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { Drawer, ConfirmDialog } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import { WEEKDAY_SHORT, WEEKDAYS } from '../types'
import { generatePeriods, minutesOf, timeOf, type GeneratorInput, type Period, type TemplateClass, type TemplateDays, type TimeTemplateRow } from './types'

const DEFAULT_GEN: GeneratorInput = { weekdays: [1, 2, 3, 4, 5], start: '16:30', lesson_minutes: 40, break_minutes: 10, count: 5, lunch_after: null, lunch_minutes: 50 }

export function TemplateFormDrawer({ template, classes, onClose, onSaved }: { template: TimeTemplateRow | null; classes: TemplateClass[]; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(template?.name ?? '')
  const [description, setDescription] = useState(template?.description ?? '')
  const [levels, setLevels] = useState<number[]>(template?.levels ?? [])
  const [active, setActive] = useState(template?.is_active ?? true)
  const [gen, setGen] = useState<GeneratorInput>(template?.generator ?? DEFAULT_GEN)
  const [days, setDays] = useState<TemplateDays>(template?.days ?? {})
  const [classIds, setClassIds] = useState<number[]>(template?.classes.map((c) => c.id) ?? [])
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const total = Object.values(days).reduce((s, d) => s + d.length, 0)
  const levelGroups = useMemo(() => {
    const m = new Map<string, TemplateClass[]>()
    classes.forEach((c) => m.set(c.level ? String(c.level) : 'Diğer', [...(m.get(c.level ? String(c.level) : 'Diğer') ?? []), c]))
    return [...m.entries()].sort(([a], [b]) => (a === 'Diğer' ? 1 : b === 'Diğer' ? -1 : Number(b) - Number(a)))
  }, [classes])

  const setDay = (wd: number, periods: Period[]) =>
    setDays((prev) => {
      const next = { ...prev }
      if (periods.length) next[String(wd)] = periods
      else delete next[String(wd)]
      return next
    })

  const addPeriod = (wd: number) => {
    const list = days[String(wd)] ?? []
    const last = list[list.length - 1]
    const len = last ? minutesOf(last[1]) - minutesOf(last[0]) : gen.lesson_minutes
    const start = last ? minutesOf(last[1]) + gen.break_minutes : minutesOf(gen.start)
    setDay(wd, [...list, [timeOf(start), timeOf(Math.min(start + len, 23 * 60 + 59))]])
  }

  const payload = () => ({ name, description: description || null, levels, is_active: active, generator: gen, days, class_group_ids: classIds })
  const onError = (e: unknown) => {
    const msg = e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'
    setError(msg)
    toast.error(msg)
  }
  const save = useMutation({
    mutationFn: () => (template ? api.put<{ message: string }>(`/time-templates/${template.id}`, payload()) : api.post<{ message: string }>('/time-templates', payload())),
    onSuccess: (r) => {
      toast.success(r.message)
      onSaved()
    },
    onError,
  })
  const remove = useMutation({ mutationFn: () => api.delete<{ message: string }>(`/time-templates/${template!.id}`), onSuccess: (r) => { toast.success(r.message); onSaved() }, onError })

  return (
    <>
      <Drawer
        open
        onClose={onClose}
        width={640}
        title={template ? 'Şablonu düzenle' : 'Yeni zaman şablonu'}
        description="Dilimler günün ders saatleridir; aradaki boşluk teneffüs ya da öğle arasıdır."
        footer={
          <>
            {template && <Button variant="danger-soft" className="mr-auto" icon={<Trash2 className="size-4" />} onClick={() => setConfirmDelete(true)}>Sil</Button>}
            <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
            <Button variant="primary" loading={save.isPending} disabled={!name.trim() || total === 0} onClick={() => save.mutate()}>Kaydet</Button>
          </>
        }
      >
        <div className="flex flex-col gap-5">
          {error && <Alert tone="danger">{error}</Alert>}
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Ad" required className="sm:col-span-2"><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Örn. 11-12. sınıf hafta içi akşam" /></Field>
            <Field label="Açıklama" optional className="sm:col-span-2"><Textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} /></Field>
          </div>

          <Field label="Seviyeler" hint="“Seviyeye göre ata” bu şablonu adı bu seviyeyle başlayan sınıflara (ör. 11-A, 12-B) bağlar.">
            <div className="flex flex-wrap gap-1.5">
              {[5, 6, 7, 8, 9, 10, 11, 12].map((l) => (
                <button key={l} type="button" onClick={() => setLevels((prev) => (prev.includes(l) ? prev.filter((x) => x !== l) : [...prev, l].sort((a, b) => a - b)))}
                  className={cn('h-8 min-w-10 rounded-[var(--radius-sm)] border px-2 text-[13px] tabular transition-colors', levels.includes(l) ? 'border-ink bg-surface-2 font-medium text-ink' : 'border-line text-ink-2 hover:border-line-strong')}>
                  {l}
                </button>
              ))}
            </div>
          </Field>

          <section className="rounded-[var(--radius-md)] ring-1 ring-line p-3">
            <p className="mb-3 flex items-center gap-2 text-[13px] font-semibold"><Wand2 className="size-4 text-ink-3" />Hızlı oluşturucu</p>
            <div className="mb-3 flex flex-wrap gap-1.5">
              {[1, 2, 3, 4, 5, 6, 7].map((d) => (
                <button key={d} type="button" onClick={() => setGen({ ...gen, weekdays: gen.weekdays.includes(d) ? gen.weekdays.filter((x) => x !== d) : [...gen.weekdays, d] })}
                  className={cn('h-8 w-11 rounded-[var(--radius-sm)] border text-[12.5px] transition-colors', gen.weekdays.includes(d) ? 'border-ink bg-surface-2 font-medium' : 'border-line text-ink-3 hover:border-line-strong')}>
                  {WEEKDAY_SHORT[d]}
                </button>
              ))}
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <Field label="Başlangıç"><Input type="time" value={gen.start} onChange={(e) => setGen({ ...gen, start: e.target.value })} /></Field>
              <Field label="Ders (dk)"><Input type="number" min={20} max={120} value={gen.lesson_minutes} onChange={(e) => setGen({ ...gen, lesson_minutes: Number(e.target.value) })} /></Field>
              <Field label="Teneffüs (dk)"><Input type="number" min={0} max={60} value={gen.break_minutes} onChange={(e) => setGen({ ...gen, break_minutes: Number(e.target.value) })} /></Field>
              <Field label="Ders sayısı"><Input type="number" min={1} max={14} value={gen.count} onChange={(e) => setGen({ ...gen, count: Number(e.target.value) })} /></Field>
              <Field label="Öğle arası">
                <Select value={gen.lunch_after ?? ''} onChange={(e) => setGen({ ...gen, lunch_after: e.target.value ? Number(e.target.value) : null })} placeholder="Yok" options={Array.from({ length: Math.max(0, gen.count - 1) }, (_, i) => ({ value: i + 1, label: `${i + 1}. dersten sonra` }))} />
              </Field>
              <Field label="Öğle arası (dk)"><Input type="number" min={0} max={120} disabled={!gen.lunch_after} value={gen.lunch_minutes ?? 0} onChange={(e) => setGen({ ...gen, lunch_minutes: Number(e.target.value) })} /></Field>
            </div>
            <div className="mt-3 flex justify-end">
              <Button size="sm" disabled={gen.weekdays.length === 0} onClick={() => setDays((prev) => ({ ...prev, ...generatePeriods(gen) }))}>Seçili günlere dilimleri yaz</Button>
            </div>
          </section>

          <section className="flex flex-col gap-2">
            <p className="text-[13px] font-semibold">Günler ve ders dilimleri <span className="font-normal tabular text-ink-3">· {total} ders saati</span></p>
            {[1, 2, 3, 4, 5, 6, 7].map((wd) => {
              const list = days[String(wd)] ?? []
              return (
                <div key={wd} className="flex flex-col gap-2 border-b border-line py-2 last:border-0 sm:flex-row sm:items-start">
                  <div className="flex w-[120px] shrink-0 items-center justify-between gap-2 sm:pt-1.5">
                    <span className={cn('text-[13px]', list.length ? 'font-medium' : 'text-ink-3')}>{WEEKDAYS[wd]}</span>
                  </div>
                  <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                    {list.map((p, i) => (
                      <span key={i} className="inline-flex items-center gap-1 rounded-[var(--radius-sm)] border border-line bg-surface px-1.5 py-1">
                        <input type="time" value={p[0]} onChange={(e) => setDay(wd, list.map((x, k) => (k === i ? [e.target.value, x[1]] : x)))} className="w-[76px] bg-transparent text-[12.5px] tabular outline-none" aria-label="Başlangıç" />
                        <span className="text-ink-3">–</span>
                        <input type="time" value={p[1]} onChange={(e) => setDay(wd, list.map((x, k) => (k === i ? [x[0], e.target.value] : x)))} className="w-[76px] bg-transparent text-[12.5px] tabular outline-none" aria-label="Bitiş" />
                        <button type="button" onClick={() => setDay(wd, list.filter((_, k) => k !== i))} className="grid size-5 place-items-center rounded text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Dilimi kaldır"><X className="size-3" /></button>
                      </span>
                    ))}
                    <Button size="xs" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => addPeriod(wd)}>Dilim</Button>
                    {list.length > 0 && (
                      <Button size="xs" variant="ghost" icon={<Copy className="size-3.5" />} title="Bu günün dilimlerini hafta içi tüm günlere kopyala"
                        onClick={() => setDays((prev) => { const next = { ...prev }; [1, 2, 3, 4, 5].forEach((d) => (next[String(d)] = list.map((x) => [...x] as Period))); return next })}>
                        Hafta içine kopyala
                      </Button>
                    )}
                  </div>
                </div>
              )
            })}
          </section>

          <Field label="Atanan sınıflar" hint="Aynı seviyedeki A/B şubeleri aynı şablonu paylaşabilir; bot aynı öğretmeni iki şubeye aynı anda koymaz.">
            <div className="flex flex-col gap-2">
              {levelGroups.map(([level, list]) => (
                <div key={level} className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                  <span className="w-[70px] text-[12px] text-ink-3">{level === 'Diğer' ? 'Diğer' : `${level}. sınıf`}</span>
                  {list.map((c) => (
                    <Checkbox key={c.id} checked={classIds.includes(c.id)} onChange={(on) => setClassIds((prev) => (on ? [...prev, c.id] : prev.filter((x) => x !== c.id)))} label={c.name} />
                  ))}
                </div>
              ))}
              {classes.length === 0 && <p className="text-[12.5px] text-ink-3">Aktif sınıf yok.</p>}
            </div>
          </Field>

          <Switch checked={active} onChange={setActive} label="Aktif (pasif şablon bot tarafından kullanılmaz)" />
        </div>
      </Drawer>

      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => remove.mutate()}
        loading={remove.isPending}
        danger
        title="Şablonu sil"
        confirmLabel="Sil"
        description="Şablon sınıflardan kaldırılır. Mevcut ders programı değişmez."
      />
    </>
  )
}

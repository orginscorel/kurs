import { useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ClipboardList, FileSpreadsheet, FileText, ListChecks, Printer } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Button } from '@/components/ui/Button'
import { Alert, Skeleton } from '@/components/ui/feedback'
import { Checkbox, Field, Input, Segmented, Switch } from '@/components/ui/form'
import { cn } from '@/lib/cn'
import { todayISO } from '@/lib/format'

type Options = {
  term: string | null
  classes: { id: number; name: string; students: number }[]
  unassigned: number
  statuses: Record<string, string>
}
type Mode = 'list' | 'attendance'
type Preview = {
  total: number
  groups: { name: string; count: number }[]
  sample: { student_no: string; full_name: string; class_name: string; phone: string; guardian_name: string; relationship: string; guardian_phone: string }[]
}

const PRESETS: { value: string; label: string; labels: string }[] = [
  { value: 'hafta', label: 'Hafta (Pzt–Paz)', labels: 'Pzt,Sal,Çar,Per,Cum,Cmt,Paz' },
  { value: 'haftaici', label: 'Hafta içi', labels: 'Pzt,Sal,Çar,Per,Cum' },
  { value: 'haftasonu', label: 'Hafta sonu dersleri', labels: 'Cmt 1,Cmt 2,Cmt 3,Paz 1,Paz 2,Paz 3' },
  { value: 'ders', label: 'Ders saati (1–8)', labels: '1,2,3,4,5,6,7,8' },
  { value: 'ozel', label: 'Özel', labels: '' },
]

/**
 * Toplu öğrenci listesi / elle yoklama çizelgesi çıktısı.
 * Sıra: sınıf (seviye → şube) → ad → soyad. Her sınıf ayrı sayfada başlayabilir.
 */
export default function RosterPrintPage() {
  const can = useCan()
  const [params] = useSearchParams()
  const [mode, setMode] = useState<Mode>(params.get('tur') === 'yoklama' ? 'attendance' : 'list')
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [unassigned, setUnassigned] = useState(true)
  const [statuses, setStatuses] = useState<Set<string>>(new Set(['active', 'enrolled', 'pending']))
  const [perPage, setPerPage] = useState(true)
  const [preset, setPreset] = useState('hafta')
  const [custom, setCustom] = useState('')
  const [title, setTitle] = useState('')
  const [date, setDate] = useState(todayISO())
  const [busy, setBusy] = useState<string | null>(null)

  const opts = useQuery({ queryKey: ['students', 'roster-options'], queryFn: () => api.get<{ data: Options }>('/students-roster/options').then((r) => r.data) })
  const classes = opts.data?.classes ?? []
  const allPicked = picked.size === 0

  const count = useMemo(() => {
    const base = classes.filter((c) => allPicked || picked.has(c.id)).reduce((a, c) => a + c.students, 0)
    return base + (unassigned ? opts.data?.unassigned ?? 0 : 0)
  }, [classes, picked, allPicked, unassigned, opts.data])

  const preview = useQuery({
    queryKey: ['students', 'roster-preview', [...picked].sort(), unassigned, [...statuses].sort()],
    queryFn: () => api.get<{ data: Preview }>('/students-roster/preview', {
      class_group_ids: allPicked ? undefined : [...picked], statuses: [...statuses], include_unassigned: unassigned ? 1 : 0,
    }).then((r) => r.data),
    enabled: statuses.size > 0,
    placeholderData: (prev) => prev,
  })

  const labels = preset === 'ozel' ? custom : PRESETS.find((p) => p.value === preset)?.labels ?? ''

  const toggle = (id: number) => setPicked((s) => {
    const n = new Set(s)
    if (n.has(id)) n.delete(id)
    else n.add(id)
    return n
  })
  const toggleStatus = (k: string) => setStatuses((s) => {
    const n = new Set(s)
    if (n.has(k)) n.delete(k)
    else n.add(k)
    return n
  })

  const run = async (format: 'pdf' | 'xlsx') => {
    if (statuses.size === 0) return toast.error('En az bir öğrenci durumu seçin.')
    setBusy(format)
    try {
      await api.download('/students-roster/export', {
        format, mode, date, title: title || undefined,
        class_group_ids: allPicked ? undefined : [...picked],
        statuses: [...statuses],
        include_unassigned: unassigned ? 1 : 0,
        page_per_class: perPage ? 1 : 0,
        column_labels: mode === 'attendance' ? labels || undefined : undefined,
      }, format === 'pdf' ? 'ogrenci-listesi.pdf' : 'ogrenci-listesi.xlsx')
      toast.success(format === 'pdf' ? 'PDF hazırlandı.' : 'Excel hazırlandı.')
    } catch (e) {
      toast.error(e instanceof ApiError ? e.firstError() : 'Çıktı alınamadı.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="mx-auto max-w-[1100px]">
      <PageHeader
        breadcrumbs={[{ label: 'Raporlar ve çıktılar', to: '/raporlar' }, { label: 'Liste ve çizelge çıktıları' }]}
        title="Liste ve çizelge çıktıları"
        description="Sınıf listelerini (öğrenci no, ad soyad, telefon, veli) ya da elle doldurulacak yoklama çizelgesini PDF veya Excel olarak alın. Önizleme seçimlerinize göre güncellenir."
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="flex min-w-0 flex-col gap-4">
          <Panel title="1. Çıktı türü">
            <div className="grid gap-3 sm:grid-cols-2">
              {([
                { v: 'list', icon: ListChecks, t: 'Öğrenci listesi', d: 'Öğrenci no, ad soyad, telefon, veli ad soyad ve veli telefonu.' },
                { v: 'attendance', icon: ClipboardList, t: 'Yoklama çizelgesi', d: 'Aynı liste + elle işaretlenecek gün/ders kutuları ve imza alanı.' },
              ] as const).map((o) => (
                <button key={o.v} type="button" onClick={() => setMode(o.v)}
                  className={cn('flex items-start gap-3 rounded-[var(--radius-md)] border p-3.5 text-left transition-colors',
                    mode === o.v ? 'border-primary bg-primary-soft/60 ring-2 ring-primary/15' : 'border-line bg-surface hover:border-line-strong')}>
                  <span className={cn('grid size-9 shrink-0 place-items-center rounded-full', mode === o.v ? 'bg-primary text-white' : 'bg-surface-2 text-ink-2')}><o.icon className="size-4.5" /></span>
                  <span>
                    <span className="block text-[14px] font-semibold">{o.t}</span>
                    <span className="mt-0.5 block text-[12.5px] leading-snug text-ink-3">{o.d}</span>
                  </span>
                </button>
              ))}
            </div>
          </Panel>

          <Panel title="2. Sınıflar" description={opts.data?.term ? `${opts.data.term} dönemi · hiçbiri seçilmezse tüm sınıflar alınır` : undefined}
            actions={picked.size > 0 && <Button size="xs" variant="ghost" onClick={() => setPicked(new Set())}>Tümünü al</Button>}>
            {opts.isLoading ? <Skeleton className="h-24" /> : (
              <>
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={() => setPicked(new Set())}
                    className={cn('h-9 rounded-full border px-3.5 text-[13px] font-medium transition-colors', allPicked ? 'border-primary bg-primary text-white' : 'border-line bg-surface hover:border-line-strong')}>
                    Tüm sınıflar
                  </button>
                  {classes.map((c) => (
                    <button key={c.id} type="button" onClick={() => toggle(c.id)}
                      className={cn('flex h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] font-medium transition-colors',
                        picked.has(c.id) ? 'border-primary bg-primary-soft text-primary-ink' : 'border-line bg-surface hover:border-line-strong')}>
                      {c.name}<span className="text-[12px] font-normal text-ink-3 tabular">{c.students}</span>
                    </button>
                  ))}
                </div>
                <div className="mt-4 border-t border-line pt-3">
                  <Checkbox checked={unassigned} onChange={setUnassigned} label={<>Sınıfı olmayan öğrencileri de ekle <span className="text-ink-3">({opts.data?.unassigned ?? 0} öğrenci, "Sınıfsız" başlığıyla)</span></>} />
                </div>
              </>
            )}
          </Panel>

          <Panel title="3. Ayrıntılar">
            <div className="flex flex-col gap-4">
              <Field label="Öğrenci durumu">
                <div className="flex flex-wrap gap-x-4 gap-y-2">
                  {Object.entries(opts.data?.statuses ?? {}).map(([k, v]) => <Checkbox key={k} checked={statuses.has(k)} onChange={() => toggleStatus(k)} label={v} />)}
                </div>
              </Field>

              {mode === 'attendance' && (
                <Field label="İşaretleme sütunları" hint="Her öğrenci satırında bu başlıklarla boş kutular basılır.">
                  <div className="flex flex-col gap-2">
                    <Segmented size="sm" value={preset} onChange={setPreset} options={PRESETS.map((p) => ({ value: p.value, label: p.label }))} />
                    {preset === 'ozel'
                      ? <Input value={custom} onChange={(e) => setCustom(e.target.value)} placeholder="Virgülle ayırın: 1. hafta, 2. hafta, 3. hafta, 4. hafta" />
                      : <p className="text-[12.5px] text-ink-3">{labels.split(',').join(' · ')}</p>}
                  </div>
                </Field>
              )}

              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Başlık" hint="Boş bırakılırsa türün adı kullanılır.">
                  <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder={mode === 'attendance' ? 'Yoklama Çizelgesi' : 'Öğrenci Listesi'} maxLength={120} />
                </Field>
                <Field label="Belge tarihi">
                  <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                </Field>
              </div>

              <Switch checked={perPage} onChange={setPerPage} label="Her sınıf yeni sayfada başlasın" />
            </div>
          </Panel>

          <Panel title="4. Önizleme" description="Çıktının ilk satırları; belge bu sütun sırasıyla basılır.">
            {!preview.data ? <Skeleton className="h-32" /> : preview.data.total === 0 ? (
              <Alert tone="warning" title="Seçimlere uyan öğrenci yok">Sınıf ya da durum seçimini genişletin.</Alert>
            ) : (
              <div className="overflow-x-auto scroll-thin rounded-[var(--radius-sm)] border border-line">
                <table className="w-full min-w-[640px] border-collapse text-[12.5px]">
                  <thead>
                    <tr className="bg-surface-2 text-left text-[11.5px] font-semibold uppercase tracking-[0.05em] text-ink-3">
                      <th className="px-2.5 py-2">#</th><th className="px-2.5 py-2">Öğr. no</th><th className="px-2.5 py-2">Adı soyadı</th>
                      <th className="px-2.5 py-2">Sınıf</th><th className="px-2.5 py-2">Telefon</th><th className="px-2.5 py-2">Veli</th><th className="px-2.5 py-2">Veli telefonu</th>
                      {mode === 'attendance' && <th className="px-2.5 py-2">İşaret kutuları</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {preview.data.sample.map((r, i) => (
                      <tr key={i} className="border-t border-line">
                        <td className="px-2.5 py-1.5 text-ink-3 tabular">{i + 1}</td>
                        <td className="px-2.5 py-1.5 tabular">{r.student_no}</td>
                        <td className="px-2.5 py-1.5 font-medium">{r.full_name}</td>
                        <td className="px-2.5 py-1.5">{r.class_name}</td>
                        <td className="px-2.5 py-1.5 tabular whitespace-nowrap">{r.phone || '—'}</td>
                        <td className="px-2.5 py-1.5">{r.guardian_name || '—'}{r.relationship ? <span className="text-ink-3"> ({r.relationship})</span> : null}</td>
                        <td className="px-2.5 py-1.5 tabular whitespace-nowrap">{r.guardian_phone || '—'}</td>
                        {mode === 'attendance' && <td className="px-2.5 py-1.5"><span className="inline-flex gap-1">{[0, 1, 2].map((k) => <span key={k} className="size-3.5 rounded-[2px] border border-line-strong" />)}</span></td>}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </div>

        <div className="lg:sticky lg:top-20 lg:self-start">
          <Panel title="Özet">
            <div className="flex flex-col gap-3">
              <div className="rounded-[var(--radius-md)] bg-primary-soft/60 p-3.5">
                <p className="text-[12px] text-ink-3">Listeye girecek</p>
                <p className="text-[26px] font-semibold tabular text-primary-ink">{preview.data ? preview.data.total : count}<span className="ml-1 text-[13px] font-normal text-ink-3">öğrenci</span></p>
                <p className="text-[12.5px] text-ink-2">{preview.data ? preview.data.groups.map((g) => `${g.name}: ${g.count}`).join(' · ') : allPicked ? `${classes.length} sınıfın tamamı` : `${picked.size} sınıf`}</p>
              </div>
              <ul className="flex flex-col gap-1 text-[12.5px] text-ink-2">
                <li>Sıralama: <b>sınıf → ad → soyad</b></li>
                <li>Veli: birincil veli (yoksa ilk bağlı veli)</li>
              </ul>
              {statuses.size === 0 && <Alert tone="warning" title="Durum seçilmedi">En az bir öğrenci durumu işaretleyin.</Alert>}
              <Button variant="primary" size="lg" icon={<FileText className="size-4" />} loading={busy === 'pdf'} disabled={!!busy} onClick={() => run('pdf')}>
                PDF indir
              </Button>
              {can('students.export') && (
                <Button variant="success" icon={<FileSpreadsheet className="size-4" />} loading={busy === 'xlsx'} disabled={!!busy} onClick={() => run('xlsx')}>
                  Excel indir
                </Button>
              )}
              <p className="flex items-center gap-1.5 text-[12px] text-ink-3"><Printer className="size-3.5" /> PDF, A4 yazdırmaya hazırdır; çok sütunlu çizelge yatay basılır.</p>
            </div>
          </Panel>
        </div>
      </div>
    </div>
  )
}

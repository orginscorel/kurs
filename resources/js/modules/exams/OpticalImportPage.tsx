import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, CheckCircle2, ChevronRight, Code2, FileUp, ScanLine, UploadCloud } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { cn } from '@/lib/cn'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { formatLabel, importStatusMeta, type Mapping, type OpticalImport, type PreviewData, type Source, type UploadDescription } from './types'
import { ExamPicker } from './shared'

type Step = 1 | 2 | 3 | 4

export default function OpticalImportPage() {
  const [params, setParams] = useSearchParams()
  const examId = params.get('sinav') ? Number(params.get('sinav')) : null
  const detailId = params.get('aktarim') ? Number(params.get('aktarim')) : null
  const qc = useQueryClient()
  const [step, setStep] = useState<Step>(1)
  const [desc, setDesc] = useState<UploadDescription | null>(null)
  const [mapping, setMapping] = useState<Mapping | null>(null)
  const [preview, setPreview] = useState<PreviewData | null>(null)
  const [running, setRunning] = useState<OpticalImport | null>(null)
  const [layoutName, setLayoutName] = useState('')
  const [apiOpen, setApiOpen] = useState(false)

  const reset = () => { setStep(1); setDesc(null); setMapping(null); setPreview(null); setRunning(null); setLayoutName('') }
  useEffect(() => { reset() }, [examId])

  const upload = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('file', file); return api.post<UploadDescription>(`/exams/${examId}/optical/upload`, fd) },
    onSuccess: (d) => { setDesc(d); setMapping(d.suggested_mapping); setStep(2); qc.invalidateQueries({ queryKey: ['optical', 'imports'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Dosya yüklenemedi.'),
  })
  const previewMut = useMutation({
    mutationFn: () => api.post<PreviewData>(`/exams/${examId}/optical/${desc!.import.id}/preview`, { mapping }),
    onSuccess: (p) => { setPreview(p); setStep(3) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Önizleme oluşturulamadı.'),
  })
  const runMut = useMutation({
    mutationFn: () => api.post<{ message: string; import: OpticalImport }>(`/exams/${examId}/optical/${desc!.import.id}/run`, { mapping, save_layout_as: layoutName || null }),
    onSuccess: (r) => { toast.success(r.message); setRunning(r.import); setStep(4); qc.invalidateQueries({ queryKey: ['optical', 'imports'] }); qc.invalidateQueries({ queryKey: ['exam', examId] }); qc.invalidateQueries({ queryKey: ['exams'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İçe aktarma başlatılamadı.'),
  })

  // Kuyruktaki işi izle
  const polling = running && ['queued', 'processing', 'pending'].includes(running.status)
  const status = useQuery({ queryKey: ['optical', 'import', running?.id], queryFn: () => api.get<{ import: OpticalImport }>(`/exams/${examId}/optical/${running!.id}`), enabled: !!polling, refetchInterval: 2000 })
  useEffect(() => {
    if (status.data?.import && status.data.import.status !== running?.status) {
      setRunning(status.data.import)
      if (status.data.import.status === 'completed') { toast.success('İçe aktarma tamamlandı.'); qc.invalidateQueries({ queryKey: ['exam', examId] }); qc.invalidateQueries({ queryKey: ['optical', 'imports'] }) }
      if (status.data.import.status === 'failed') toast.error(status.data.import.error ?? 'İçe aktarma başarısız.')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status.data])

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Optik Okuma"
        description="CSV, Excel, TXT (sabit genişlikli) ya da JSON çıktısını yükleyin; kolonları eşleştirin, önizleyin ve puanlayın."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <ExamPicker value={examId ? String(examId) : ''} onChange={(v) => setParams((p) => { v ? p.set('sinav', v) : p.delete('sinav'); return p })} className="w-[320px] max-w-full" placeholder="Sınav seçin (anahtarı hazır)" />
            <Button variant="ghost" icon={<Code2 className="size-4" />} onClick={() => setApiOpen(true)}>API</Button>
          </div>
        }
      />

      {!examId ? (
        <EmptyState icon={<ScanLine />} title="Önce sınav seçin" description="Cevap anahtarı girilmiş bir deneme seçtiğinizde yükleme sihirbazı açılır. Listede deneme yoksa önce deneme oluşturun." action={<ButtonLink to="/sinavlar">Denemelere git</ButtonLink>} />
      ) : (
        <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px] gap-4 items-start">
          <div className="flex flex-col gap-4 min-w-0">
            <Steps step={step} />
            {step === 1 && <UploadStep loading={upload.isPending} onFile={(f) => upload.mutate(f)} />}
            {step === 2 && desc && mapping && (
              <MappingStep desc={desc} mapping={mapping} onChange={setMapping} layoutName={layoutName} onLayoutName={setLayoutName} onBack={reset} onNext={() => previewMut.mutate()} loading={previewMut.isPending} />
            )}
            {step === 3 && preview && desc && (
              <PreviewStep desc={desc} preview={preview} onBack={() => setStep(2)} onRun={() => runMut.mutate()} loading={runMut.isPending} />
            )}
            {step === 4 && running && <ResultStep imp={running} examId={examId} onNew={reset} />}
          </div>
          <RecentImports examId={examId} />
        </div>
      )}

      <ApiDocModal open={apiOpen} onClose={() => setApiOpen(false)} examId={examId} />
      <ImportDetailModal examId={examId} importId={detailId} onClose={() => setParams((p) => { p.delete('aktarim'); return p })} />
    </div>
  )
}

function Steps({ step }: { step: Step }) {
  const items = ['Dosya yükle', 'Kolon eşleştir', 'Önizleme', 'İçe aktar']
  return (
    <ol className="flex items-center gap-1 overflow-x-auto scroll-thin text-[12.5px]">
      {items.map((label, i) => {
        const n = (i + 1) as Step
        return (
          <li key={label} className="flex items-center gap-1 shrink-0">
            <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 ring-1 ring-inset', n === step ? 'bg-primary-soft text-primary-ink ring-primary/20 font-medium' : n < step ? 'bg-success-soft text-success ring-success/15' : 'bg-surface text-ink-3 ring-line')}>
              <span className="grid size-4 place-items-center rounded-full bg-surface/70 text-[10.5px] font-semibold">{n < step ? '✓' : n}</span>{label}
            </span>
            {i < items.length - 1 && <ChevronRight className="size-3.5 text-ink-3" />}
          </li>
        )
      })}
    </ol>
  )
}

function UploadStep({ onFile, loading }: { onFile: (f: File) => void; loading: boolean }) {
  const input = useRef<HTMLInputElement>(null)
  const [drag, setDrag] = useState(false)
  return (
    <Panel>
      <div
        onDragOver={(e) => { e.preventDefault(); setDrag(true) }}
        onDragLeave={() => setDrag(false)}
        onDrop={(e) => { e.preventDefault(); setDrag(false); const f = e.dataTransfer.files[0]; if (f) onFile(f) }}
        onClick={() => input.current?.click()}
        className={cn('flex cursor-pointer flex-col items-center justify-center rounded-[var(--radius-lg)] border-2 border-dashed px-6 py-14 text-center transition-colors', drag ? 'border-primary bg-primary-soft/40' : 'border-line hover:border-line-strong hover:bg-surface-2/60')}
      >
        <div className="mb-3 grid size-12 place-items-center rounded-full bg-primary-soft text-primary-ink"><UploadCloud className="size-6" /></div>
        <p className="text-[14.5px] font-semibold">Optik dosyasını sürükleyin ya da seçin</p>
        <p className="mt-1 max-w-md text-[13px] text-ink-2">CSV (; , TAB), XLSX, TXT (sabit genişlikli), JSON · en fazla 20 MB · Windows-1254 Türkçe kodlaması otomatik çevrilir</p>
        <Button className="mt-5" variant="primary" icon={<FileUp className="size-4" />} loading={loading} onClick={(e) => { e.stopPropagation(); input.current?.click() }}>Dosya seç</Button>
        <input ref={input} type="file" accept=".csv,.txt,.xlsx,.xls,.json,.dat,.prn" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) onFile(f); e.target.value = '' }} />
      </div>
      <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-[12.5px] text-ink-2">
        <div className="rounded-[var(--radius-md)] bg-surface-2 p-3"><p className="font-medium text-ink mb-1">CSV / Excel</p>Öğrenci no, kitapçık ve bölüm başına ya da tek kolonda tüm cevaplar. Başlık satırı otomatik algılanır.</div>
        <div className="rounded-[var(--radius-md)] bg-surface-2 p-3"><p className="font-medium text-ink mb-1">TXT sabit genişlik</p>Kolon başlangıç/uzunluk tanımlanır; düzen şablon olarak kaydedilip tekrar kullanılır.</div>
        <div className="rounded-[var(--radius-md)] bg-surface-2 p-3"><p className="font-medium text-ink mb-1">Kitapçık</p>Kitapçık kolonu yoksa her öğrencinin cevapları A/B'ye göre puanlanıp en yüksek doğru veren kitapçık seçilir.</div>
      </div>
    </Panel>
  )
}

// ---------------------------------------------------------------- Adım 2: eşleştirme

function MappingStep({ desc, mapping, onChange, layoutName, onLayoutName, onBack, onNext, loading }: { desc: UploadDescription; mapping: Mapping; onChange: (m: Mapping) => void; layoutName: string; onLayoutName: (v: string) => void; onBack: () => void; onNext: () => void; loading: boolean }) {
  const fixed = desc.kind === 'fixed'
  const set = (patch: Partial<Mapping>) => onChange({ ...mapping, ...patch })
  const setSection = (code: string, src: Source) => onChange({ ...mapping, sections: { ...mapping.sections, [code]: src } })
  const missing = useMemo(() => {
    const m: string[] = []
    if (!mapping.student_no) m.push('Öğrenci no')
    if (mapping.answers_mode === 'combined' ? !mapping.combined : desc.sections.some((s) => !mapping.sections[s.code])) m.push('Cevaplar')
    return m
  }, [mapping, desc.sections])

  return (
    <Panel
      title={`Kolon eşleştirme · ${desc.import.original_name}`}
      description={`${formatLabel[desc.import.format] ?? desc.import.format} · ${desc.total_rows} satır${desc.delimiter ? ` · ayraç "${desc.delimiter === '\t' ? 'TAB' : desc.delimiter}"` : ''}${desc.has_header ? ' · başlık satırı var' : ''} · ${desc.total_questions} soru bekleniyor`}
      actions={desc.layouts.length > 0 ? (
        <Select className="w-[220px]" value="" placeholder="Kayıtlı düzen yükle…" onChange={(e) => { const l = desc.layouts.find((x) => String(x.id) === e.target.value); if (l) { onChange(l.mapping); toast.success(`"${l.name}" düzeni yüklendi.`) } }} options={desc.layouts.map((l) => ({ value: l.id, label: l.name }))} />
      ) : undefined}
    >
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-x-6 gap-y-3">
        <SourceField label="Öğrenci numarası" required fixed={fixed} headers={desc.headers} value={mapping.student_no} onChange={(v) => set({ student_no: v })} kind={desc.kind} />
        <SourceField label="Ad soyad (yedek eşleme)" fixed={fixed} headers={desc.headers} value={mapping.name} onChange={(v) => set({ name: v })} kind={desc.kind} allowNone />
        <SourceField label="Kitapçık" hint="Boş bırakılırsa cevaplardan otomatik algılanır" fixed={fixed} headers={desc.headers} value={mapping.booklet} onChange={(v) => set({ booklet: v })} kind={desc.kind} allowNone allowFixed={desc.booklets} />
        <Field label="Cevap düzeni">
          <Segmented value={mapping.answers_mode} onChange={(v) => set({ answers_mode: v })} options={[{ value: 'combined', label: 'Tek kolonda tüm cevaplar' }, { value: 'per_section', label: 'Bölüm başına kolon' }]} />
        </Field>
        {mapping.answers_mode === 'combined' ? (
          <SourceField label={`Tüm cevaplar (${desc.total_questions} karakter; bölümler sırayla kesilir: ${desc.sections.map((s) => `${s.code} ${s.question_count}`).join(', ')})`} required fixed={fixed} headers={desc.headers} value={mapping.combined} onChange={(v) => set({ combined: v })} kind={desc.kind} lengthHint={desc.total_questions} className="lg:col-span-2" />
        ) : (
          desc.sections.map((s) => <SourceField key={s.code} label={`${s.name} (${s.question_count} soru)`} required fixed={fixed} headers={desc.headers} value={mapping.sections[s.code] ?? null} onChange={(v) => setSection(s.code, v)} kind={desc.kind} lengthHint={s.question_count} />)
        )}
      </div>

      <div className="mt-4">
        <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Örnek satırlar</p>
        {fixed ? <FixedPreview rows={desc.sample_rows as string[]} mapping={mapping} sections={desc.sections} /> : <DelimitedPreview desc={desc} mapping={mapping} />}
      </div>

      <div className="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4">
        <Field label="Bu düzeni şablon olarak kaydet" hint="Aynı optik okuyucudan gelen sonraki dosyalarda tek tıkla kullanılır" className="w-full sm:w-[320px]">
          <Input value={layoutName} onChange={(e) => onLayoutName(e.target.value)} placeholder="Örn. Zeki Optik TYT düzeni" />
        </Field>
        <div className="ml-auto flex items-center gap-2">
          {missing.length > 0 && <span className="text-[12.5px] text-warning inline-flex items-center gap-1"><AlertTriangle className="size-3.5" /> Eksik: {missing.join(', ')}</span>}
          <Button variant="ghost" onClick={onBack}>Başka dosya</Button>
          <Button variant="primary" disabled={missing.length > 0} loading={loading} onClick={onNext}>Önizle</Button>
        </div>
      </div>
    </Panel>
  )
}

function SourceField({ label, hint, required, fixed, headers, value, onChange, kind, allowNone, allowFixed, lengthHint, className }: { label: string; hint?: string; required?: boolean; fixed: boolean; headers: string[]; value: Source; onChange: (v: Source) => void; kind: string; allowNone?: boolean; allowFixed?: string[]; lengthHint?: number; className?: string }) {
  if (fixed) {
    return (
      <Field label={label} hint={hint} required={required} className={className}>
        <div className="flex items-center gap-2">
          {allowFixed && (
            <Select value={value?.fixed ? `fixed:${value.fixed}` : value ? 'col' : ''} className="w-[150px]" onChange={(e) => { const v = e.target.value; if (v === '') onChange(null); else if (v.startsWith('fixed:')) onChange({ fixed: v.slice(6) }); else onChange({ start: 1, length: 1 }) }} options={[{ value: '', label: 'Otomatik algıla' }, { value: 'col', label: 'Satırdan oku' }, ...allowFixed.map((b) => ({ value: `fixed:${b}`, label: `Sabit: ${b}` }))]} />
          )}
          {!value?.fixed && (allowFixed ? !!value : true) && (
            <>
              <Input type="number" min={1} value={value?.start ?? ''} placeholder="Başlangıç" className="w-[110px]" onChange={(e) => onChange({ ...(value ?? {}), start: Number(e.target.value) || undefined, length: value?.length })} />
              <Input type="number" min={1} value={value?.length ?? ''} placeholder={lengthHint ? `Uzunluk (${lengthHint})` : 'Uzunluk'} className="w-[130px]" onChange={(e) => onChange({ ...(value ?? {}), start: value?.start, length: Number(e.target.value) || undefined })} />
              {allowNone && !allowFixed && value && <Button size="sm" variant="ghost" onClick={() => onChange(null)}>Kaldır</Button>}
            </>
          )}
        </div>
      </Field>
    )
  }
  const current = value?.fixed ? `fixed:${value.fixed}` : value ? (kind === 'json' ? `key:${value.key}` : `col:${value.col}`) : ''
  return (
    <Field label={label} hint={hint} required={required} className={className}>
      <Select
        value={current}
        placeholder={allowNone ? (allowFixed ? 'Otomatik algıla' : 'Kullanma') : 'Kolon seçin'}
        onChange={(e) => { const v = e.target.value; if (v === '') onChange(null); else if (v.startsWith('fixed:')) onChange({ fixed: v.slice(6) }); else if (v.startsWith('key:')) onChange({ key: v.slice(4) }); else onChange({ col: Number(v.slice(4)) }) }}
        options={[
          ...(allowFixed ?? []).map((b) => ({ value: `fixed:${b}`, label: `Sabit: ${b} kitapçığı` })),
          ...headers.map((h, i) => ({ value: kind === 'json' ? `key:${h}` : `col:${i}`, label: kind === 'json' ? h : `${i + 1}. ${h}` })),
        ]}
      />
    </Field>
  )
}

function DelimitedPreview({ desc, mapping }: { desc: UploadDescription; mapping: Mapping }) {
  const roleOf = (i: number, h: string) => {
    const match = (s: Source) => !!s && (desc.kind === 'json' ? s.key === h || (s.key ?? '').startsWith(h + '.') : s.col === i)
    if (match(mapping.student_no)) return 'No'
    if (match(mapping.name)) return 'Ad'
    if (match(mapping.booklet)) return 'Kitapçık'
    if (mapping.answers_mode === 'combined' && match(mapping.combined)) return 'Cevaplar'
    const sec = Object.entries(mapping.sections).find(([, s]) => match(s))
    return sec ? sec[0] : null
  }
  const rows = desc.sample_rows.slice(0, 5)
  return (
    <div className="overflow-x-auto rounded-[var(--radius-md)] ring-1 ring-line">
      <table className="tbl w-full text-[12px] font-mono">
        <thead className="bg-surface-2/60">
          <tr>{desc.headers.map((h, i) => { const role = roleOf(i, h); return <th key={i} className="px-2 py-1.5 font-medium whitespace-nowrap text-left"><span className="text-ink-3">{h}</span>{role && <Badge tone="primary" className="ml-1.5">{role}</Badge>}</th> })}</tr>
        </thead>
        <tbody>
          {rows.map((r, ri) => (
            <tr key={ri} className="border-t border-line">
              {desc.headers.map((h, i) => { const v = Array.isArray(r) ? r[i] : (r as Record<string, unknown>)[h]; const s = typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v ?? ''); return <td key={i} className="px-2 py-1 whitespace-nowrap max-w-[260px] truncate text-ink-2 text-left" title={s}>{s}</td> })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function FixedPreview({ rows, mapping, sections }: { rows: string[]; mapping: Mapping; sections: UploadDescription['sections'] }) {
  const ranges: { start: number; length: number; label: string; cls: string }[] = []
  const push = (s: Source, label: string, cls: string, fallbackLen?: number) => { if (s?.start) ranges.push({ start: s.start, length: s.length ?? fallbackLen ?? 1, label, cls }) }
  push(mapping.student_no, 'No', 'bg-primary-soft text-primary-ink')
  push(mapping.name, 'Ad', 'bg-info-soft text-info')
  push(mapping.booklet, 'Kit', 'bg-warning-soft text-warning')
  if (mapping.answers_mode === 'combined') {
    let off = mapping.combined?.start ?? 0
    if (off) sections.forEach((s, i) => { ranges.push({ start: off, length: s.question_count, label: s.code, cls: i % 2 ? 'bg-success-soft text-success' : 'bg-accent-soft text-accent' }); off += s.question_count })
  } else sections.forEach((s, i) => push(mapping.sections[s.code], s.code, i % 2 ? 'bg-success-soft text-success' : 'bg-accent-soft text-accent', s.question_count))
  const maxLen = Math.max(...rows.slice(0, 4).map((r) => r.length), 10)
  const ruler = Array.from({ length: maxLen }, (_, i) => ((i + 1) % 10 === 0 ? String(((i + 1) / 10) % 10) : (i + 1) % 5 === 0 ? '+' : '·')).join('')
  const render = (line: string) => {
    const chars = [...line]
    const out: React.ReactNode[] = []
    let i = 0
    while (i < chars.length) {
      const r = ranges.find((x) => x.start - 1 === i)
      if (r) { out.push(<span key={i} className={cn('rounded-[3px] px-[1px]', r.cls)} title={r.label}>{chars.slice(i, i + r.length).join('')}</span>); i += r.length }
      else { out.push(<span key={i}>{chars[i]}</span>); i++ }
    }
    return out
  }
  return (
    <div className="overflow-x-auto rounded-[var(--radius-md)] bg-surface-2/60 ring-1 ring-line p-3 font-mono text-[12px] leading-6 whitespace-pre">
      <div className="text-ink-3 select-none">{ruler}</div>
      {rows.slice(0, 4).map((line, i) => <div key={i}>{render(line)}</div>)}
      <div className="mt-2 flex flex-wrap gap-2 font-sans text-[12px]">{ranges.map((r, i) => <span key={i} className={cn('rounded px-1.5', r.cls)}>{r.label}: {r.start}–{r.start + r.length - 1}</span>)}</div>
    </div>
  )
}

// ---------------------------------------------------------------- Adım 3: önizleme

function PreviewStep({ desc, preview, onBack, onRun, loading }: { desc: UploadDescription; preview: PreviewData; onBack: () => void; onRun: () => void; loading: boolean }) {
  const rate = preview.total ? Math.round((preview.matched / preview.total) * 100) : 0
  return (
    <Panel title="Önizleme" description={`${preview.total} satır · ${preview.matched} öğrenci eşleşti (%${rate})`}>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <Tile label="Satır" value={preview.total} />
        <Tile label="Eşleşen" value={preview.matched} tone="text-success" />
        <Tile label="Eşleşmeyen" value={preview.unmatched_count} tone={preview.unmatched_count ? 'text-danger' : undefined} />
        <Tile label="Kitapçık" value={Object.entries(preview.booklets).filter(([k, v]) => v > 0 && k !== 'unknown').map(([k, v]) => `${k === 'auto' ? 'oto' : k} ${v}`).join(' · ') || '—'} small />
      </div>
      {preview.booklets.auto > 0 && <Alert tone="info" className="mt-3">{preview.booklets.auto} satırda kitapçık bilgisi yoktu; cevaplara göre otomatik algılandı.</Alert>}
      {preview.blank_rows > 0 && <Alert tone="warning" className="mt-3">{preview.blank_rows} satırda hiç işaretli cevap yok (boş kâğıt ya da yanlış kolon).</Alert>}
      {preview.unmatched_count > 0 && (
        <Alert tone="danger" className="mt-3" title={`Eşleşmeyen öğrenci numaraları (${preview.unmatched_count})`}>
          <div className="mt-1.5 flex flex-wrap gap-1.5 max-h-28 overflow-y-auto scroll-thin">
            {preview.unmatched.map((u) => <Badge key={u.row} tone="danger">satır {u.row}: {u.student_no ?? '—'}{u.name ? ` · ${u.name}` : ''}</Badge>)}
          </div>
          <p className="mt-1.5 text-[12px] text-ink-3">Bu satırlar atlanır; içe aktarma sonrası rapor edilir. Numarayı düzeltip yeniden yükleyebilir ya da elle giriş yapabilirsiniz.</p>
        </Alert>
      )}
      <div className="mt-4 overflow-x-auto rounded-[var(--radius-md)] ring-1 ring-line">
        <table className="tbl w-full text-[12.5px]">
          <thead className="bg-surface-2/60 text-[12px] text-ink-3"><tr><th className="px-2 py-1.5 font-medium text-left">#</th><th className="px-2 py-1.5 font-medium text-center">Dosyadaki no</th><th className="fill px-2 py-1.5 font-medium text-center">Eşleşen öğrenci</th><th className="px-2 py-1.5 font-medium text-center">Kit.</th><th className="px-2 py-1.5 font-medium text-center">İşaretli</th>{desc.sections.map((s) => <th key={s.code} className="px-2 py-1.5 font-medium text-center">{s.code}</th>)}</tr></thead>
          <tbody>
            {preview.rows.map((r) => (
              <tr key={r.row} className={cn('border-t border-line', !r.student && 'bg-danger-soft/30')}>
                <td className="px-2 py-1 tabular text-ink-3 text-left">{r.row}</td>
                <td className="px-2 py-1 tabular text-center">{r.student_no ?? '—'}{r.name && <span className="text-ink-3"> · {r.name}</span>}</td>
                <td className="fill px-2 py-1 text-center">{r.student ? <span className="inline-flex items-center gap-1 text-success"><CheckCircle2 className="size-3.5" />{r.student.name}</span> : <span className="text-danger">eşleşmedi</span>}</td>
                <td className="px-2 py-1 text-center">{r.booklet}{r.booklet_detected && <span className="text-ink-3" title="otomatik algılandı">*</span>}</td>
                <td className="px-2 py-1 tabular text-center">{r.filled}/{desc.total_questions}</td>
                {desc.sections.map((s) => <td key={s.code} className="px-2 py-1 font-mono text-[12px] text-ink-2 whitespace-nowrap max-w-[180px] truncate text-center" title={r.answers[s.code]}>{r.answers[s.code] || <span className="text-ink-3">boş</span>}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4">
        {preview.will_queue && <span className="mr-auto text-[12.5px] text-ink-3">Büyük dosya: arka planda işlenir, ilerleme bu ekranda gösterilir.</span>}
        <Button variant="ghost" onClick={onBack}>Eşleştirmeye dön</Button>
        <Button variant="primary" loading={loading} disabled={preview.matched === 0} icon={<ScanLine className="size-4" />} onClick={onRun}>{preview.matched} öğrenciyi puanla</Button>
      </div>
    </Panel>
  )
}

// ---------------------------------------------------------------- Adım 4: sonuç

function ResultStep({ imp, examId, onNew }: { imp: OpticalImport; examId: number; onNew: () => void }) {
  const meta = importStatusMeta[imp.status]
  const busy = ['queued', 'processing', 'pending'].includes(imp.status)
  return (
    <Panel title={<span className="inline-flex items-center gap-2">İçe aktarma <Badge tone={meta.tone} dot>{meta.label}</Badge></span>} description={imp.original_name ?? undefined}>
      {busy && (
        <div className="mb-4">
          <div className="mb-1.5 flex justify-between text-[12.5px] text-ink-2"><span>{imp.status === 'queued' ? 'Kuyrukta bekliyor (işçi her dakika çalışır)' : 'Puanlanıyor…'}</span><span className="tabular">{imp.processed_rows}/{imp.total_rows}</span></div>
          <ProgressBar value={imp.progress} />
        </div>
      )}
      {imp.status === 'failed' && <Alert tone="danger" title="İçe aktarma başarısız">{imp.error}</Alert>}
      {imp.status === 'completed' && <ImportSummary imp={imp} />}
      <div className="mt-4 flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4">
        <Button variant="ghost" onClick={onNew}>Yeni dosya yükle</Button>
        <ButtonLink to={`/sinavlar/${examId}?sekme=results`} variant="primary">Sonuçlara git</ButtonLink>
      </div>
    </Panel>
  )
}

function ImportSummary({ imp }: { imp: OpticalImport }) {
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <Tile label="Satır" value={imp.total_rows} />
        <Tile label="Puanlanan" value={imp.matched_rows} tone="text-success" />
        <Tile label="Eşleşmeyen" value={imp.unmatched_count} tone={imp.unmatched_count ? 'text-danger' : undefined} />
        <Tile label="Kitapçık" value={Object.entries(imp.report?.booklets ?? {}).filter(([, v]) => v > 0).map(([k, v]) => `${k === 'auto' ? 'oto' : k} ${v}`).join(' · ') || '—'} small />
      </div>
      {imp.unmatched_count > 0 && (
        <Alert tone="danger" title={`Eşleşmeyen öğrenci numaraları (${imp.unmatched_count})`}>
          <div className="mt-1.5 flex flex-wrap gap-1.5 max-h-32 overflow-y-auto scroll-thin">{imp.unmatched.map((u) => <Badge key={u.row} tone="danger">satır {u.row}: {u.student_no ?? '—'}{u.name ? ` · ${u.name}` : ''}</Badge>)}</div>
        </Alert>
      )}
      {imp.report?.errors && imp.report.errors.length > 0 && (
        <Alert tone="warning" title="Puanlanamayan satırlar">
          <ul className="list-disc pl-5">{imp.report.errors.map((e, i) => <li key={i}>satır {e.row} ({e.student_no ?? '—'}): {e.message}</li>)}</ul>
        </Alert>
      )}
      {imp.finished_at && <p className="text-[12px] text-ink-3">Tamamlandı: {dateTime(imp.finished_at)}</p>}
    </div>
  )
}

function Tile({ label, value, tone, small }: { label: string; value: number | string; tone?: string; small?: boolean }) {
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2 px-3 py-2">
      <p className="text-[12px] font-medium text-ink-3">{label}</p>
      <p className={cn('mt-0.5 tabular font-semibold', small ? 'text-[13px]' : 'text-[18px]', tone)}>{value}</p>
    </div>
  )
}

// ---------------------------------------------------------------- yan panel + modallar

function RecentImports({ examId }: { examId: number }) {
  const [, setParams] = useSearchParams()
  const { data, isLoading } = useQuery({
    queryKey: ['optical', 'imports', examId],
    queryFn: () => api.get<Paginated<OpticalImport>>('/exams/imports', { exam_id: examId, per_page: 10 }),
    refetchInterval: (q) => (q.state.data?.data.some((i) => ['queued', 'processing'].includes(i.status)) ? 3000 : false),
  })
  return (
    <Panel title="Son içe aktarmalar" description="Bu sınav" flush className="xl:sticky xl:top-[72px]">
      {isLoading ? <div className="p-4"><Skeleton className="h-24" /></div> : !data?.data.length ? <div className="px-4 py-6 text-center text-[12.5px] text-ink-3">Henüz içe aktarma yok.</div> : (
        <ul className="divide-y divide-line">
          {data.data.map((i) => (
            <li key={i.id}>
              <button type="button" className="flex w-full flex-col gap-1 px-4 py-2.5 text-left hover:bg-surface-2/70" onClick={() => setParams((p) => { p.set('aktarim', String(i.id)); return p })}>
                <div className="flex items-center gap-2 text-[13px]"><Badge tone="neutral">{formatLabel[i.format] ?? i.format}</Badge><span className="flex-1 truncate font-medium">{i.original_name ?? 'API'}</span><Badge tone={importStatusMeta[i.status].tone} dot>{importStatusMeta[i.status].label}</Badge></div>
                <div className="flex items-center gap-2 text-[12px] text-ink-3"><span>{dateTime(i.created_at)}</span><span className="ml-auto tabular">{i.matched_rows}/{i.total_rows}{i.unmatched_count ? <span className="text-danger"> · {i.unmatched_count} eşleşmedi</span> : null}</span></div>
                {['queued', 'processing'].includes(i.status) && <ProgressBar value={i.progress} className="mt-1" />}
              </button>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

function ImportDetailModal({ examId, importId, onClose }: { examId: number | null; importId: number | null; onClose: () => void }) {
  const { data, isLoading } = useQuery({ queryKey: ['optical', 'import', importId, 'detail'], queryFn: () => api.get<{ import: OpticalImport }>(`/exams/${examId}/optical/${importId}`), enabled: !!examId && !!importId })
  const imp = data?.import
  return (
    <Modal open={!!importId} onClose={onClose} size="lg" title={imp ? <span className="inline-flex items-center gap-2">{imp.original_name ?? 'API'} <Badge tone={importStatusMeta[imp.status].tone} dot>{importStatusMeta[imp.status].label}</Badge></span> : 'İçe aktarma'} description={imp ? `${formatLabel[imp.format] ?? imp.format} · ${dateTime(imp.created_at)}${imp.created_by ? ` · ${imp.created_by}` : ''}` : undefined}>
      {isLoading || !imp ? <Skeleton className="h-32" /> : imp.status === 'failed' ? <Alert tone="danger" title="Başarısız">{imp.error}</Alert> : <ImportSummary imp={imp} />}
    </Modal>
  )
}

function ApiDocModal({ open, onClose, examId }: { open: boolean; onClose: () => void; examId: number | null }) {
  const url = `${window.location.origin}/api/v1/exams/${examId ?? '{exam}'}/optical`
  const example = `curl -X POST '${url}' \\
  -H 'Authorization: Bearer <JETON>' -H 'Accept: application/json' -H 'Content-Type: application/json' \\
  -d '{"rows":[
    {"student_no":"2026001","booklet":"A","answers":{"TUR":"ABCD…","SOS":"…","MAT":"…","FEN":"…"}},
    {"student_no":"2026002","answers":"ABCDEABCDE…"}
  ]}'`
  return (
    <Modal open={open} onClose={onClose} size="lg" title="API ile optik gönderimi" description="Optik okuyucu yazılımı sonuçları doğrudan gönderebilir. Jeton: exams.import yetkili bir kullanıcı için POST /api/v1/auth/token ile alınır.">
      <div className="flex flex-col gap-3 text-[13px]">
        <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 font-mono text-[12px] whitespace-pre-wrap break-all">{example}</div>
        <ul className="list-disc pl-5 text-ink-2 leading-relaxed">
          <li><code>answers</code> nesne (bölüm kodu → cevap dizisi) ya da tek dizi olabilir; tek dizide bölümler sınav sırasına göre kesilir.</li>
          <li><code>booklet</code> verilmezse cevaplardan otomatik algılanır. <code>name</code> yedek eşleme, <code>national_rank</code> isteğe bağlıdır.</li>
          <li>Boş cevap için boşluk, <code>-</code> ya da <code>.</code> kullanın; en fazla 2000 satır/istek. Yanıtta eşleşmeyen numaralar listelenir.</li>
          <li>Sınavın cevap anahtarı hazır olmalı; yayımlanmış sınava gönderim sıralamaları ve istatistikleri otomatik tazeler.</li>
        </ul>
        <Link to="/sinavlar" className="text-primary hover:underline" onClick={onClose}>Sınav kimliğini denemeler listesinden alın →</Link>
      </div>
    </Modal>
  )
}

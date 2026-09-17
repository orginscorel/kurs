import { useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, KeyRound, UploadCloud, XCircle } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { Modal } from '@/components/ui/overlay'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Alert, Badge, EmptyState } from '@/components/ui/feedback'
import { Segmented } from '@/components/ui/form'

export type ImportEntity = 'students' | 'teachers'

type Job = { id: number; original_name: string; status: string; total_rows: number; success_rows: number }
type PreviewRow = { row: number; status: 'ok' | 'warning' | 'error'; title: string; subtitle: string | null; errors: string[]; warnings: string[] }
type Preview = { job: Job; summary: { total: number; ok: number; warning: number; error: number }; unknown_columns: string[]; columns: string[]; rows: PreviewRow[] }
type RowRef = { row: number; title: string; reason?: string; note?: string | null; id?: number }
type CommitResult = { message: string; job: Job; created: RowRef[]; skipped: RowRef[]; failed: RowRef[]; secrets: { title: string; note: string | null; password: string }[] }

const META: Record<ImportEntity, { noun: string; plural: string; listTo: string; invalidate: string[][] }> = {
  students: { noun: 'öğrenci', plural: 'Öğrenciler', listTo: '/ogrenciler', invalidate: [['students'], ['guardians'], ['onboarding']] },
  teachers: { noun: 'öğretmen', plural: 'Öğretmenler', listTo: '/ogretmenler', invalidate: [['teachers'], ['academic'], ['onboarding']] },
}

type Filter = 'all' | 'error' | 'warning'

/**
 * Excel içe aktarma sihirbazı: şablon indir → dosya yükle → satır satır önizleme → onayla → sonuç özeti.
 * Hatalı satırlar içe aktarılmaz; uyarılı satırlar aktarılır.
 */
export function ExcelImportDialog({ entity, open, onClose }: { entity: ImportEntity; open: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const meta = META[entity]
  const fileRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [drag, setDrag] = useState(false)
  const [preview, setPreview] = useState<Preview | null>(null)
  const [result, setResult] = useState<CommitResult | null>(null)
  const [filter, setFilter] = useState<Filter>('all')
  const [uploadError, setUploadError] = useState<string | null>(null)

  const reset = () => { setFile(null); setPreview(null); setResult(null); setFilter('all'); setUploadError(null); if (fileRef.current) fileRef.current.value = '' }

  const discard = useMutation({ mutationFn: (id: number) => api.delete(`/imports/${id}`) })

  const close = () => {
    if (preview && !result) discard.mutate(preview.job.id)
    reset()
    onClose()
  }

  const template = useMutation({
    mutationFn: () => api.download(`/imports/${entity}/template`, undefined, `${meta.noun}-ice-aktarma-sablonu.xlsx`),
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Şablon indirilemedi.'),
  })

  const upload = useMutation({
    mutationFn: (f: File) => { const fd = new FormData(); fd.append('file', f); return api.post<Preview>(`/imports/${entity}/preview`, fd) },
    onSuccess: (p) => { setPreview(p); setUploadError(null); setFilter(p.summary.error > 0 ? 'error' : 'all') },
    onError: (e) => setUploadError(e instanceof ApiError ? e.firstError() : 'Dosya okunamadı.'),
  })

  const commit = useMutation({
    mutationFn: (id: number) => api.post<CommitResult>(`/imports/${id}/commit`),
    onSuccess: (r) => {
      setResult(r)
      meta.invalidate.forEach((k) => qc.invalidateQueries({ queryKey: k }))
      if (r.created.length) toast.success(r.message)
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İçe aktarma tamamlanamadı.'),
  })

  const pick = (f: File | undefined | null) => {
    if (!f) return
    setFile(f)
    setUploadError(null)
    upload.mutate(f)
  }

  const importable = preview ? preview.summary.ok + preview.summary.warning : 0
  const rows = preview?.rows.filter((r) => filter === 'all' || r.status === filter) ?? []

  const footer = result ? (
    <>
      <Button onClick={reset}>Yeni dosya yükle</Button>
      <ButtonLink variant="primary" to={meta.listTo} onClick={close}>{meta.plural} listesine git</ButtonLink>
    </>
  ) : preview ? (
    <>
      <Button variant="ghost" onClick={() => { discard.mutate(preview.job.id); reset() }}>Farklı dosya seç</Button>
      <Button variant="primary" disabled={importable === 0} loading={commit.isPending} onClick={() => commit.mutate(preview.job.id)}>
        {importable === 0 ? 'Aktarılacak satır yok' : `${importable} ${meta.noun} içe aktar`}
      </Button>
    </>
  ) : (
    <Button variant="ghost" onClick={close}>Vazgeç</Button>
  )

  return (
    <Modal
      open={open}
      onClose={close}
      size="lg"
      title={`Excel'den ${meta.noun} içe aktar`}
      description={result ? 'İçe aktarma tamamlandı.' : preview ? `${preview.job.original_name} · ${preview.summary.total} satır kontrol edildi` : 'Şablonu indirin, doldurun ve yükleyin. Kaydetmeden önce her satırı kontrol edebilirsiniz.'}
      footer={footer}
    >
      {result ? (
        <ResultView result={result} noun={meta.noun} />
      ) : preview ? (
        <div className="flex flex-col gap-3">
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
            <SummaryTile label="Toplam satır" value={preview.summary.total} />
            <SummaryTile label="Hazır" value={preview.summary.ok} tone="success" />
            <SummaryTile label="Uyarılı" value={preview.summary.warning} tone="warning" hint="aktarılır" />
            <SummaryTile label="Hatalı" value={preview.summary.error} tone="danger" hint="atlanır" />
          </div>
          {preview.unknown_columns.length > 0 && (
            <Alert tone="warning" title="Tanınmayan sütunlar yok sayılacak">{preview.unknown_columns.join(', ')}</Alert>
          )}
          {preview.summary.error > 0 && (
            <p className="text-[12.5px] text-ink-2">Hatalı satırlar içe aktarılmaz. Düzeltmek için Excel dosyasında satırı düzeltip dosyayı yeniden yükleyin.</p>
          )}
          <div className="flex flex-wrap items-center justify-between gap-2">
            <Segmented<Filter>
              size="sm"
              value={filter}
              onChange={setFilter}
              options={[
                { value: 'all', label: `Tümü (${preview.summary.total})` },
                { value: 'error', label: `Hatalı (${preview.summary.error})` },
                { value: 'warning', label: `Uyarılı (${preview.summary.warning})` },
              ]}
            />
            <span className="text-[12px] text-ink-3">Eşleşen sütunlar: {preview.columns.length}</span>
          </div>
          <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line max-h-[46dvh] overflow-y-auto scroll-thin">
            {rows.length === 0 && <li><EmptyState compact title="Bu grupta satır yok" /></li>}
            {rows.slice(0, 300).map((r) => (
              <li key={r.row} className="flex items-start gap-3 px-3 py-2.5 text-[13px]">
                <span className="mt-0.5 shrink-0">
                  {r.status === 'error' ? <XCircle className="size-4 text-danger" /> : r.status === 'warning' ? <AlertTriangle className="size-4 text-warning" /> : <CheckCircle2 className="size-4 text-success" />}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-baseline gap-x-2">
                    <span className="font-medium text-ink break-words">{r.title}</span>
                    <span className="text-[11.5px] text-ink-3 tabular">Satır {r.row}</span>
                  </p>
                  {r.subtitle && <p className="text-[12px] text-ink-3 break-words">{r.subtitle}</p>}
                  {r.errors.map((m, i) => <p key={`e${i}`} className="text-[12.5px] text-danger break-words">{m}</p>)}
                  {r.warnings.map((m, i) => <p key={`w${i}`} className="text-[12.5px] text-warning break-words">{m}</p>)}
                </div>
              </li>
            ))}
            {rows.length > 300 && <li className="px-3 py-2 text-[12px] text-ink-3">İlk 300 satır gösteriliyor ({rows.length} satır).</li>}
          </ul>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <ol className="grid grid-cols-1 sm:grid-cols-3 gap-2 text-[12.5px] text-ink-2">
            <li className="rounded-[var(--radius-md)] ring-1 ring-line p-3"><span className="font-semibold text-ink">1.</span> Şablonu indirin. “Açıklamalar” sayfası her sütunu anlatır.</li>
            <li className="rounded-[var(--radius-md)] ring-1 ring-line p-3"><span className="font-semibold text-ink">2.</span> Her {meta.noun} için bir satır doldurun; başlıkları değiştirmeyin.</li>
            <li className="rounded-[var(--radius-md)] ring-1 ring-line p-3"><span className="font-semibold text-ink">3.</span> Dosyayı yükleyin, önizlemeyi kontrol edip onaylayın.</li>
          </ol>
          <div>
            <Button icon={<Download className="size-4" />} loading={template.isPending} onClick={() => template.mutate()}>Şablonu indir (.xlsx)</Button>
          </div>
          <button
            type="button"
            onClick={() => fileRef.current?.click()}
            onDragOver={(e) => { e.preventDefault(); setDrag(true) }}
            onDragLeave={() => setDrag(false)}
            onDrop={(e) => { e.preventDefault(); setDrag(false); pick(e.dataTransfer.files?.[0]) }}
            className={cn('flex flex-col items-center justify-center gap-2 rounded-[var(--radius-lg)] border-2 border-dashed px-4 py-8 text-center transition-colors', drag ? 'border-primary bg-primary-soft/40' : 'border-line hover:border-line-strong hover:bg-surface-2')}
          >
            {upload.isPending ? <FileSpreadsheet className="size-7 text-ink-3 animate-pulse" /> : <UploadCloud className="size-7 text-ink-3" />}
            <span className="text-[13.5px] font-medium text-ink">{upload.isPending ? `${file?.name ?? 'Dosya'} kontrol ediliyor…` : 'Excel dosyasını seçin ya da buraya bırakın'}</span>
            <span className="text-[12px] text-ink-3">.xlsx ya da .csv · en fazla 5 MB · en fazla 2.000 satır</span>
          </button>
          <input ref={fileRef} type="file" hidden accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" onChange={(e) => pick(e.target.files?.[0])} />
          {uploadError && <Alert tone="danger" title="Dosya kontrol edilemedi">{uploadError}</Alert>}
          {entity === 'students' && <p className="text-[12px] text-ink-3">Her öğrenci için veli bilgisi zorunludur. Öğrenci portal hesabı otomatik açılır; şifreler öğrenci profilinden görülebilir. Aynı telefonlu veli varsa öğrenci o veliye bağlanır.</p>}
          {entity === 'teachers' && <p className="text-[12px] text-ink-3">Verdiği dersler, sistemde tanımlı ders adlarıyla eşleştirilir. “Sistem Kullanıcısı: Evet” yazılan öğretmenlerin geçici şifreleri içe aktarma sonunda bir kez gösterilir.</p>}
        </div>
      )}
    </Modal>
  )
}

function SummaryTile({ label, value, tone, hint }: { label: string; value: number; tone?: 'success' | 'warning' | 'danger'; hint?: string }) {
  return (
    <div className="rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2">
      <p className="text-[11.5px] text-ink-3">{label}{hint ? ` · ${hint}` : ''}</p>
      <p className={cn('text-[18px] font-semibold tabular', value > 0 && tone === 'success' && 'text-success', value > 0 && tone === 'warning' && 'text-warning', value > 0 && tone === 'danger' && 'text-danger')}>{value}</p>
    </div>
  )
}

function ResultView({ result, noun }: { result: CommitResult; noun: string }) {
  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-3 gap-2">
        <SummaryTile label="Oluşturuldu" value={result.created.length} tone="success" />
        <SummaryTile label="Atlandı (hatalı)" value={result.skipped.length} tone="warning" />
        <SummaryTile label="Kaydedilemedi" value={result.failed.length} tone="danger" />
      </div>
      {result.secrets.length > 0 && (
        <Alert tone="warning" title="Geçici şifreler yalnız şimdi gösteriliyor">
          <p className="mb-2">Öğretmenler ilk girişte şifrelerini değiştirmek zorundadır. Bu listeyi not alın.</p>
          <ul className="flex flex-col gap-1">
            {result.secrets.map((s, i) => (
              <li key={i} className="flex flex-wrap items-center gap-x-2 text-[12.5px]">
                <KeyRound className="size-3.5 text-ink-3" />
                <span className="font-medium text-ink">{s.title}</span>
                {s.note && <span className="text-ink-3">{s.note}</span>}
                <code className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-ink select-all">{s.password}</code>
              </li>
            ))}
          </ul>
        </Alert>
      )}
      {result.created.length === 0 && result.failed.length === 0 && <EmptyState compact title={`Hiç ${noun} oluşturulmadı`} description="Tüm satırlar hatalıydı. Dosyayı düzeltip yeniden yükleyin." />}
      {(result.failed.length > 0 || result.skipped.length > 0) && (
        <div>
          <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Aktarılamayan satırlar</p>
          <ul className="divide-y divide-line rounded-[var(--radius-md)] ring-1 ring-line max-h-56 overflow-y-auto scroll-thin">
            {[...result.failed, ...result.skipped].sort((a, b) => a.row - b.row).map((r) => (
              <li key={r.row} className="px-3 py-2 text-[12.5px]">
                <span className="font-medium text-ink">Satır {r.row} · {r.title}</span>
                <span className="block text-danger break-words">{r.reason}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
      {result.created.length > 0 && (
        <div>
          <p className="mb-1.5 text-[12.5px] font-medium text-ink-2">Oluşturulan kayıtlar</p>
          <ul className="flex flex-wrap gap-1.5 max-h-40 overflow-y-auto scroll-thin">
            {result.created.slice(0, 200).map((r) => <li key={r.row}><Badge tone="success">{r.title}{r.note ? ` · ${r.note}` : ''}</Badge></li>)}
          </ul>
        </div>
      )}
    </div>
  )
}

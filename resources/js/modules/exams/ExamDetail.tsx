import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ArrowRight, CheckCircle2, ClipboardList, KeyRound, MoreHorizontal, Pencil, RefreshCw, ScanLine, Send, Trash2, Users } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat, Tabs } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { ConfirmDialog, Menu } from '@/components/ui/overlay'
import { axisProps, ChartTooltip, gridProps, Legend, series } from '@/components/charts/ChartKit'
import { formatLabel, importStatusMeta, net, type ExamDetailData } from './types'
import { ExamStatusBadge, useExamOptions } from './shared'
import { ExamFormDrawer } from './ExamFormDrawer'
import { ResultsTable } from './ResultsTable'
import { QuestionAnalysis } from './QuestionAnalysis'
import { ExamTopicAnalysis } from './ExamTopicAnalysis'

type Tab = 'overview' | 'results' | 'questions' | 'topics' | 'imports'

export default function ExamDetail() {
  const { id } = useParams()
  const examId = Number(id)
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('sekme') as Tab) || 'overview'
  const setTab = (t: Tab) => setParams((p) => { t === 'overview' ? p.delete('sekme') : p.set('sekme', t); return p }, { replace: true })
  const [editOpen, setEditOpen] = useState(false)
  const [confirm, setConfirm] = useState<null | 'publish' | 'delete' | 'recalc'>(null)

  const { data, isLoading, error } = useQuery({ queryKey: ['exam', examId], queryFn: () => api.get<ExamDetailData>(`/exams/${examId}`) })
  const options = useExamOptions(can('exams.manage'))

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['exam', examId] })
    qc.invalidateQueries({ queryKey: ['exams'] })
  }
  const action = useMutation({
    mutationFn: (kind: 'publish' | 'recalculate' | 'delete') => kind === 'delete' ? api.delete<{ message: string }>(`/exams/${examId}`) : api.post<{ message: string }>(`/exams/${examId}/${kind}`),
    onSuccess: (r, kind) => {
      toast.success(r.message)
      setConfirm(null)
      if (kind === 'delete') navigate('/sinavlar')
      else invalidate()
    },
    onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'); setConfirm(null) },
  })

  if (error) return <EmptyState title="Deneme bulunamadı" description={error instanceof ApiError ? error.message : undefined} action={<ButtonLink to="/sinavlar">Denemelere dön</ButtonLink>} />
  if (isLoading || !data) return <DetailSkeleton />

  const e = data.exam
  const o = data.overview
  const keyComplete = e.key.total >= e.key.expected && e.key.expected > 0
  const hasResults = o.participants > 0

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Denemeler', to: '/sinavlar' }, { label: e.name }]}
        title={
          <span className="inline-flex flex-wrap items-center gap-2.5">
            {e.name}
            <ExamStatusBadge status={e.status} />
          </span>
        }
        description={`${e.type?.name ?? ''} · ${date(e.exam_date, 'long')} · ${e.publisher ? `Yayınevi: ${e.publisher}` : 'Kurum denemesi'} · ${e.scope === 'national' ? 'Türkiye geneli' : 'Kurum içi'} · Kitapçık: ${e.booklets.join(', ')}`}
        actions={
          <>
            {can('exams.manage') && (
              <ButtonLink to={`/sinavlar/${e.id}/cevap-anahtari`} icon={<KeyRound className="size-4" />}>{keyComplete ? 'Cevap anahtarı' : 'Cevap anahtarını gir'}</ButtonLink>
            )}
            {can('exams.import') && e.status !== 'draft' && (
              <ButtonLink to={`/optik-okuma?sinav=${e.id}`} icon={<ScanLine className="size-4" />}>Optik yükle</ButtonLink>
            )}
            {can('exams.publish') && e.status === 'answer_key_ready' && hasResults && (
              <Button variant="primary" icon={<Send className="size-4" />} onClick={() => setConfirm('publish')}>Sonuçları yayımla</Button>
            )}
            {(can('exams.manage')) && (
              <Menu
                trigger={<Button variant="ghost" size="icon" aria-label="Diğer"><MoreHorizontal className="size-4" /></Button>}
                items={[
                  { label: 'Düzenle', icon: <Pencil />, onClick: () => setEditOpen(true) },
                  { label: 'Yeniden hesapla', icon: <RefreshCw />, onClick: () => setConfirm('recalc'), hidden: !hasResults },
                  'divider',
                  { label: 'Denemeyi sil', icon: <Trash2 />, danger: true, onClick: () => setConfirm('delete'), hidden: e.status === 'results_published' },
                ]}
              />
            )}
          </>
        }
      />

      {e.status === 'draft' && (
        <Alert tone="warning" className="mb-4" title={`Cevap anahtarı eksik (${e.key.total}/${e.key.expected})`} action={can('exams.manage') ? <ButtonLink size="sm" to={`/sinavlar/${e.id}/cevap-anahtari`}>Anahtarı gir</ButtonLink> : undefined}>
          Optik okuma yüklemeden önce tüm bölümlerin cevap anahtarı girilmelidir.
        </Alert>
      )}
      {e.status === 'answer_key_ready' && !hasResults && (
        <Alert tone="info" className="mb-4" title="Sınav optik okumaya hazır" action={can('exams.import') ? <ButtonLink size="sm" to={`/optik-okuma?sinav=${e.id}`}>Optik yükle</ButtonLink> : undefined}>
          Optik okuyucu çıktısını (CSV, XLSX, TXT, JSON) yükleyin ya da API ile gönderin.
        </Alert>
      )}
      {e.status === 'answer_key_ready' && hasResults && (
        <Alert tone="success" className="mb-4" title={`${o.participants} öğrenci puanlandı, sonuçlar henüz yayımlanmadı`} action={can('exams.publish') ? <Button size="sm" variant="primary" onClick={() => setConfirm('publish')}>Yayımla</Button> : undefined}>
          Yayımlandığında sıralamalar kesinleşir, soru ve kazanım istatistikleri üretilir; öğrenci profillerinde görünür.
        </Alert>
      )}

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <Stat label="Katılan öğrenci" icon={<Users />} value={o.participants} sub={e.status === 'results_published' && e.published_at ? `Sonuç yayını: ${relative(e.published_at)}` : `Toplam ${e.key.expected} soru`} />
        <Stat label="Kurum ortalama neti" value={hasResults ? net(o.avg_net) : '—'} delta={o.previous && hasResults ? Number((((o.avg_net - o.previous.avg_net) / Math.max(1, o.previous.avg_net)) * 100).toFixed(1)) : null} deltaLabel={o.previous ? `Önceki: ${net(o.previous.avg_net)}` : undefined} />
        <Stat label="En yüksek net" value={hasResults ? net(o.max_net) : '—'} sub={hasResults ? `En düşük net: ${net(o.min_net)}` : undefined} />
        <Stat label="Ortalama puan" value={hasResults && o.avg_score !== null ? net(o.avg_score) : '—'} sub={o.max_score !== null ? `En yüksek puan: ${net(o.max_score)}` : undefined} />
        <Stat label="Doğru / yanlış / boş" value={hasResults ? `${net(o.avg_correct, 1)} / ${net(o.avg_wrong, 1)} / ${net(o.avg_blank, 1)}` : '—'} sub={hasResults ? 'Öğrenci başına ortalama' : undefined} />
        <Stat label="Cevap anahtarı" value={`${e.key.total}/${e.key.expected}`} tone={keyComplete ? 'success' : 'warning'} sub={`${e.key.with_topic} soruya konu atandı${e.key.cancelled ? ` · ${e.key.cancelled} soru iptal` : ''}`} icon={<CheckCircle2 />} />
      </div>

      <Tabs
        className="mt-5 mb-4"
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'overview', label: 'Özet' },
          { value: 'results', label: 'Sonuçlar', count: o.participants || null },
          { value: 'questions', label: 'Soru analizi' },
          { value: 'topics', label: 'Konu analizi' },
          { value: 'imports', label: 'Optik içe aktarımlar', count: data.imports.length || null },
        ]}
      />

      {tab === 'overview' && <Overview data={data} onGoResults={() => setTab('results')} />}
      {tab === 'results' && <ResultsTable examId={examId} />}
      {tab === 'questions' && <QuestionAnalysis examId={examId} classGroups={o.classes} />}
      {tab === 'topics' && <ExamTopicAnalysis examId={examId} classGroups={o.classes} />}
      {tab === 'imports' && <ImportsTab data={data} />}

      <ExamFormDrawer open={editOpen} exam={e} options={options.data} onClose={() => setEditOpen(false)} onSaved={invalidate} />
      <ConfirmDialog open={confirm === 'publish'} onClose={() => setConfirm(null)} onConfirm={() => action.mutate('publish')} loading={action.isPending} title="Sonuçlar yayımlansın mı?" description={`${o.participants} öğrencinin sonucu kesinleşir; kurum/sınıf sıralamaları ve soru–kazanım istatistikleri hesaplanır. Öğrenci profillerinde ve raporlarda görünür.`} confirmLabel="Yayımla" />
      <ConfirmDialog open={confirm === 'recalc'} onClose={() => setConfirm(null)} onConfirm={() => action.mutate('recalculate')} loading={action.isPending} title="Yeniden hesaplansın mı?" description="Kayıtlı cevaplar güncel cevap anahtarı ve ceza oranıyla yeniden puanlanır; sıralamalar ve istatistikler tazelenir. Öğrenci cevapları değişmez." confirmLabel="Yeniden hesapla" />
      <ConfirmDialog open={confirm === 'delete'} onClose={() => setConfirm(null)} onConfirm={() => action.mutate('delete')} loading={action.isPending} title="Deneme silinsin mi?" description="Cevap anahtarı, içe aktarmalar ve puanlanmış sonuçlar silinir. Bu işlem geri alınamaz." confirmLabel="Sil" danger />
    </div>
  )
}

function Overview({ data, onGoResults }: { data: ExamDetailData; onGoResults: () => void }) {
  const o = data.overview
  const e = data.exam
  if (o.participants === 0) {
    return (
      <Panel>
        <EmptyState icon={<ClipboardList />} title="Henüz sonuç yok" description={e.status === 'draft' ? 'Cevap anahtarı girildikten sonra optik okuma dosyasını yükleyin.' : 'Optik okuma dosyasını yükleyin ya da elle sonuç girin; bölüm ortalamaları ve dağılım burada görünür.'} />
      </Panel>
    )
  }
  return (
    <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <Panel
        title="Bölüm ortalamaları"
        description="Her bölümde kurum ortalaması ve en yüksek net"
        className="xl:col-span-2"
        actions={<Legend items={[{ label: 'Ortalama net', color: series[0]! }, { label: 'En yüksek net', color: series[1]! }]} />}
      >
        <div className="h-64">
          <ResponsiveContainer>
            <BarChart data={o.sections.map((s) => ({ name: s.name, avg: s.avg_net, max: s.max_net, count: s.question_count }))} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={2} barCategoryGap="30%">
              <CartesianGrid {...gridProps} />
              <XAxis dataKey="name" {...axisProps} interval={0} dy={6} />
              <YAxis {...axisProps} width={36} allowDecimals={false} />
              <Tooltip content={<ChartTooltip formatValue={(v) => net(v)} />} cursor={{ fill: 'var(--surface-2)', radius: 6 }} />
              <Bar dataKey="avg" name="Ortalama net" fill={series[0]} radius={[4, 4, 0, 0]} maxBarSize={36} />
              <Bar dataKey="max" name="En yüksek net" fill={series[1]} radius={[4, 4, 0, 0]} maxBarSize={36} />
            </BarChart>
          </ResponsiveContainer>
        </div>
        <div className="mt-3 overflow-x-auto">
          <table className="tbl w-full text-[13px]">
            <thead className="text-[12.5px] text-ink-3">
              <tr><th className="py-1.5 font-medium text-left">Bölüm</th><th className="py-1.5 font-medium text-center">Soru sayısı</th><th className="py-1.5 font-medium text-center">Ortalama net</th><th className="py-1.5 font-medium text-center">Ortalama doğru / yanlış / boş</th><th className="py-1.5 pl-4 font-medium text-center">Başarı oranı</th></tr>
            </thead>
            <tbody>
              {o.sections.map((s) => {
                const pct = Math.round((Math.max(0, s.avg_net) / s.question_count) * 100)
                return (
                  <tr key={s.id} className="border-t border-line">
                    <td className="py-2 font-medium text-left">{s.name}</td>
                    <td className="py-2 tabular text-ink-2 text-center">{s.question_count}</td>
                    <td className="py-2 tabular font-medium text-center">{net(s.avg_net)}</td>
                    <td className="py-2 tabular text-ink-2 text-center">{net(s.avg_correct, 1)} / {net(s.avg_wrong, 1)} / {net(s.avg_blank, 1)}</td>
                    <td className="py-2 pl-4 text-center"><div className="flex items-center justify-center gap-2"><ProgressBar value={pct} tone={pct >= 60 ? 'success' : pct >= 35 ? 'primary' : 'warning'} className="w-24" /><span className="w-9 text-right tabular text-[12px] text-ink-2">%{pct}</span></div></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Panel>

      <div className="flex flex-col gap-4">
        <Panel title="Net dağılımı" description="Her net aralığındaki öğrenci sayısı">
          <div className="h-40">
            <ResponsiveContainer>
              <BarChart data={o.distribution} margin={{ top: 4, right: 4, left: -24, bottom: 0 }}>
                <XAxis dataKey="label" {...axisProps} tick={{ ...axisProps.tick, fontSize: 10 }} interval={1} />
                <YAxis {...axisProps} allowDecimals={false} />
                <Tooltip content={<ChartTooltip formatValue={(v) => `${v} öğrenci`} />} cursor={{ fill: 'var(--surface-2)' }} />
                <Bar dataKey="count" name="Öğrenci" fill={series[2]} radius={[3, 3, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Panel>
        <Panel title="Sınıf ortalamaları" description="Ortalama net · öğrenci sayısı" actions={<Button size="xs" variant="ghost" iconRight={<ArrowRight className="size-3.5" />} onClick={onGoResults}>Tüm sonuçlar</Button>}>
          {o.classes.length === 0 ? <p className="text-[12.5px] text-ink-3">Sınıf bilgisi olan sonuç yok.</p> : (
            <ul className="flex flex-col gap-2">
              {o.classes.map((c) => (
                <li key={c.id} className="flex items-center gap-2 text-[13px]">
                  <span className="w-24 truncate font-medium">{c.name}</span>
                  <ProgressBar value={(c.avg_net / Math.max(1, o.max_net)) * 100} className="flex-1" />
                  <span className="w-12 text-right tabular">{net(c.avg_net)}</span>
                  <span className="w-16 text-right text-[12px] text-ink-3 tabular whitespace-nowrap">{c.participants} öğrenci</span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </div>
  )
}

function ImportsTab({ data }: { data: ExamDetailData }) {
  if (data.imports.length === 0) {
    return <Panel><EmptyState compact icon={<ScanLine />} title="İçe aktarma yapılmadı" description="Optik Okuma sayfasından bu sınav için dosya yükleyebilirsiniz." action={<ButtonLink to={`/optik-okuma?sinav=${data.exam.id}`} variant="primary">Optik yükle</ButtonLink>} /></Panel>
  }
  return (
    <Panel flush>
      <ul className="divide-y divide-line">
        {data.imports.map((i) => (
          <li key={i.id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-[13px]">
            <Badge tone="neutral">{formatLabel[i.format] ?? i.format}</Badge>
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium">{i.original_name ?? 'API ile gönderildi'}</p>
              <p className="text-[12px] text-ink-3">{dateTime(i.created_at)}{i.created_by ? ` · Yükleyen: ${i.created_by}` : ''}</p>
            </div>
            <span className="tabular text-ink-2">Eşleşen öğrenci: {i.matched_rows}/{i.total_rows}{i.unmatched_count ? <span className="text-danger"> · Eşleşmeyen: {i.unmatched_count}</span> : null}</span>
            <Badge tone={importStatusMeta[i.status].tone} dot>{importStatusMeta[i.status].label}</Badge>
            <Link to={`/optik-okuma?sinav=${data.exam.id}&aktarim=${i.id}`} className="text-primary hover:underline text-[12.5px]">Ayrıntı</Link>
          </li>
        ))}
      </ul>
    </Panel>
  )
}

function DetailSkeleton() {
  return (
    <div className="animate-fade-in">
      <Skeleton className="h-4 w-40 mb-3" />
      <Skeleton className="h-7 w-80 mb-2" />
      <Skeleton className="h-4 w-96 mb-6" />
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-[92px] rounded-[var(--radius-lg)]" />)}</div>
      <Skeleton className="h-10 w-full mb-4" />
      <Skeleton className="h-72 rounded-[var(--radius-lg)]" />
    </div>
  )
}

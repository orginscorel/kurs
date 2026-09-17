import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Download, FileText, Image as ImageIcon, RefreshCw, Trash2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date } from '@/lib/format'
import { useCan } from '@/app/auth'
import { Drawer, ConfirmDialog } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Badge, Skeleton, EmptyState } from '@/components/ui/feedback'
import { Tabs } from '@/components/ui/layout'
import { axisProps, ChartTooltip, gridProps, series } from '@/components/charts/ChartKit'
import { net, rateTone, type ResultDetail } from './types'
import { AnswerCell, Dyb, RateBar } from './shared'

type Tab = 'answers' | 'card' | 'history'

export function ResultDrawer({ examId, resultId, onClose, onChanged }: { examId: number; resultId: number | null; onClose: () => void; onChanged: () => void }) {
  const can = useCan()
  const [tab, setTab] = useState<Tab>('answers')
  const [confirm, setConfirm] = useState(false)
  const [cardStamp, setCardStamp] = useState(0)
  const open = resultId !== null

  const { data, isLoading } = useQuery({ queryKey: ['exam', examId, 'result', resultId], queryFn: () => api.get<ResultDetail>(`/exams/${examId}/results/${resultId}`), enabled: open })

  const remove = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`/exams/${examId}/results/${resultId}`),
    onSuccess: (r) => { toast.success(r.message); setConfirm(false); onClose(); onChanged() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const r = data?.result
  const [pdfHazirlaniyor, setPdfHazirlaniyor] = useState(false)
  const cardUrl = `/api/v1/exams/${examId}/results/${resultId}/card.png${cardStamp ? `?fresh=1&t=${cardStamp}` : ''}`

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={720}
      title={r ? r.student.name : 'Sonuç'}
      description={r ? `Öğrenci no: ${r.student.no}${r.class_group ? ` · Sınıf: ${r.class_group}` : ''} · ${r.booklet} kitapçığı · Kaynak: ${r.source === 'manual' ? 'elle giriş' : r.source === 'import' ? 'API' : 'optik okuma'}` : undefined}
      footer={
        <>
          {can('exams.manage') && <Button variant="danger-soft" className="mr-auto" icon={<Trash2 className="size-4" />} onClick={() => setConfirm(true)}>Sonucu sil</Button>}
          <Button
            icon={<FileText className="size-4" />}
            loading={pdfHazirlaniyor}
            onClick={() => {
              setPdfHazirlaniyor(true)
              api.download(`/exams/${examId}/results/${resultId}/pdf`, undefined, 'sonuc.pdf')
                .catch((e) => toast.error(e.message))
                .finally(() => setPdfHazirlaniyor(false))
            }}
          >
            {pdfHazirlaniyor ? 'Hazırlanıyor…' : 'PDF belge'}
          </Button>
          <Button variant="primary" icon={<ImageIcon className="size-4" />} onClick={() => api.download(`/exams/${examId}/results/${resultId}/card.png`, { download: 1 }, 'sonuc-karti.png').catch((e) => toast.error(e.message))}>Kartı indir</Button>
        </>
      }
    >
      {isLoading || !data || !r ? (
        <div className="flex flex-col gap-3"><Skeleton className="h-20" /><Skeleton className="h-64" /></div>
      ) : (
        <div className="flex flex-col gap-4">
          <div className="grid grid-cols-3 sm:grid-cols-6 gap-2">
            <Tile label="Toplam net" value={net(r.net)} strong />
            <Tile label="Puan" value={r.score !== null ? net(r.score) : '—'} />
            <Tile label="Doğru" value={String(r.correct)} tone="text-success" />
            <Tile label="Yanlış" value={String(r.wrong)} tone="text-danger" />
            <Tile label="Boş" value={String(r.blank)} tone="text-ink-3" />
            <Tile label="Kurum sırası" value={r.institution_rank ? `${r.institution_rank}/${r.participants}` : '—'} />
          </div>
          <div className="flex flex-wrap gap-2 text-[12.5px] text-ink-2">
            {r.class_rank && <Badge tone="neutral">Sınıf sırası: {r.class_rank}{r.class_total ? ` / ${r.class_total}` : ''}</Badge>}
            {r.national_rank && <Badge tone="info">Türkiye sırası: {r.national_rank.toLocaleString('tr-TR')}</Badge>}
            <Link to={`/ogrenciler/${r.student.id}`} className="ml-auto text-primary hover:underline">Öğrenci profili →</Link>
          </div>

          <Tabs value={tab} onChange={setTab} tabs={[{ value: 'answers', label: 'Cevap analizi' }, { value: 'card', label: 'Sonuç kartı' }, { value: 'history', label: 'Net geçmişi', count: data.history.length || null }]} />

          {tab === 'answers' && (
            <div className="flex flex-col gap-4">
              {data.sections.map((s) => (
                <div key={s.id}>
                  <div className="mb-1.5 flex items-center justify-between">
                    <p className="text-[13px] font-semibold text-ink">{s.name} <span className="ml-1 font-normal text-ink-3">Net: {net(s.net)}</span></p>
                    <Dyb correct={s.correct} wrong={s.wrong} blank={s.blank} />
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {s.questions.map((q) => (
                      <AnswerCell key={q.id} number={q.number} given={q.given} state={q.state} title={`${q.number}. soru · doğru ${q.key}${q.topic ? ` · ${q.topic.name}` : ''}${q.state === 'x' ? ' · iptal' : ''}`} />
                    ))}
                  </div>
                </div>
              ))}
              {(data.topics.weak.length > 0 || data.topics.strong.length > 0) && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <TopicList title="Gelişmesi gereken konular" items={data.topics.weak} />
                  <TopicList title="Güçlü konular" items={data.topics.strong} />
                </div>
              )}
              <p className="text-[12.5px] text-ink-3">Yeşil doğru, kırmızı yanlış, gri boş, lacivert iptal (herkese doğru sayılır). Numaralar A kitapçığına göredir.</p>
            </div>
          )}

          {tab === 'card' && (
            <div className="flex flex-col items-center gap-3">
              <img key={cardStamp} src={cardUrl} alt="Sonuç kartı" className="w-full max-w-[420px] rounded-[var(--radius-md)] ring-1 ring-line shadow-[var(--shadow-soft)]" />
              <div className="flex items-center gap-2">
                <Button size="sm" variant="ghost" icon={<RefreshCw className="size-3.5" />} onClick={() => setCardStamp(Date.now())}>Yeniden üret</Button>
                <Button size="sm" icon={<Download className="size-3.5" />} onClick={() => api.download(`/exams/${examId}/results/${resultId}/card.png`, { download: 1 }, 'sonuc-karti.png').catch((e) => toast.error(e.message))}>PNG indir</Button>
              </div>
              <p className="max-w-md text-center text-[12px] text-ink-3">1080×1350 markalı kart; WhatsApp gönderimi İletişim modülünden yapılır.</p>
            </div>
          )}

          {tab === 'history' && (
            data.history.length < 2 ? (
              <EmptyState compact title="Bu türde ilk deneme" description="Gelişim grafiği ikinci denemeden sonra görünür." />
            ) : (
              <div className="rounded-[var(--radius-md)] ring-1 ring-line p-3">
                <div className="h-56">
                  <ResponsiveContainer>
                    <LineChart data={data.history.map((h) => ({ label: date(h.exam_date).slice(0, 5), name: h.name, net: h.net, rank: h.institution_rank }))} margin={{ top: 10, right: 12, left: -18, bottom: 0 }}>
                      <CartesianGrid {...gridProps} />
                      <XAxis dataKey="label" {...axisProps} />
                      <YAxis {...axisProps} />
                      <Tooltip content={<ChartTooltip formatLabel={(l) => l} formatValue={(v) => net(v)} />} />
                      <Line type="monotone" dataKey="net" name="Net" stroke={series[0]} strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
                <ul className="mt-2 divide-y divide-line text-[12.5px]">
                  {data.history.map((h) => (
                    <li key={h.id} className="flex items-center gap-3 py-1.5">
                      <span className="tabular text-ink-3 w-20">{date(h.exam_date)}</span>
                      <span className="flex-1 truncate text-ink-2">{h.name}</span>
                      <span className="tabular font-medium">{net(h.net)}</span>
                      <span className="tabular text-ink-3 w-16 text-right whitespace-nowrap">{h.institution_rank ? `${h.institution_rank}. sıra` : ''}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )
          )}
        </div>
      )}

      <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} onConfirm={() => remove.mutate()} loading={remove.isPending} title="Sonuç silinsin mi?" description="Öğrencinin bu sınavdaki sonucu silinir; sıralamalar yeniden hesaplanır." confirmLabel="Sil" danger />
    </Drawer>
  )
}

function Tile({ label, value, strong, tone }: { label: string; value: string; strong?: boolean; tone?: string }) {
  return (
    <div className="rounded-[var(--radius-md)] bg-surface-2 px-2.5 py-2">
      <p className="text-[12px] font-medium text-ink-3">{label}</p>
      <p className={`mt-0.5 tabular ${strong ? 'text-[18px] font-semibold text-ink' : 'text-[15px] font-medium'} ${tone ?? ''}`}>{value}</p>
    </div>
  )
}

function TopicList({ title, items }: { title: string; items: ResultDetail['topics']['weak'] }) {
  return (
    <div className="rounded-[var(--radius-md)] ring-1 ring-line p-3">
      <p className="mb-2 text-[12.5px] font-semibold text-ink">{title}</p>
      {items.length === 0 ? <p className="text-[12.5px] text-ink-3">—</p> : (
        <ul className="flex flex-col gap-1.5">
          {items.map((t) => (
            <li key={t.id} className="flex items-center gap-2 text-[12.5px]">
              <span className="flex-1 truncate text-ink-2">{t.name} <span className="text-ink-3">· {t.correct}/{t.asked} doğru</span></span>
              <RateBar rate={t.rate} className="w-32 min-w-0" />
              <Badge tone={rateTone(t.rate)} className="hidden sm:inline-flex">{t.section}</Badge>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, HelpCircle, Undo2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Segmented, Select } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import type { QuestionAnalysis as QA } from './types'

type Q = QA['sections'][number]['questions'][number]

/** Soru bazlı analiz: doğru/yanlış/boş %, şık dağılımı, en çok seçilen yanlış şık; sınıf filtresi. */
export function QuestionAnalysis({ examId, classGroups }: { examId: number; classGroups: { id: number; name: string }[] }) {
  const can = useCan()
  const qc = useQueryClient()
  const [classId, setClassId] = useState('')
  const [order, setOrder] = useState<'number' | 'hardest' | 'blank'>('number')
  const [cancelTarget, setCancelTarget] = useState<{ q: Q; section: string } | null>(null)
  const { data, isLoading } = useQuery({ queryKey: ['exam', examId, 'questions', classId], queryFn: () => api.get<QA>(`/exams/${examId}/analysis/questions`, { class_group_id: classId }) })

  const cancel = useMutation({
    mutationFn: ({ q }: { q: Q }) => api.post<{ message: string }>(`/exams/${examId}/questions/${q.id}/cancel`, { cancelled: !q.is_cancelled }),
    onSuccess: (r) => { toast.success(r.message); setCancelTarget(null); qc.invalidateQueries({ queryKey: ['exam', examId] }); qc.invalidateQueries({ queryKey: ['exams'] }) },
    onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'); setCancelTarget(null) },
  })

  const sections = useMemo(() => (data?.sections ?? []).map((s) => ({
    ...s,
    questions: [...s.questions].sort((a, b) => order === 'hardest' ? b.wrong_pct - a.wrong_pct : order === 'blank' ? b.blank_pct - a.blank_pct : a.number - b.number),
  })), [data, order])

  if (isLoading || !data) return <div className="flex flex-col gap-3"><Skeleton className="h-12" /><Skeleton className="h-64" /><Skeleton className="h-64" /></div>
  if (data.participants === 0) return <Panel><EmptyState compact icon={<HelpCircle />} title="Analiz için sonuç yok" description="Sonuçlar içe aktarıldığında her sorunun doğru/yanlış/boş dağılımı burada görünür." /></Panel>

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Select value={classId} onChange={(e) => setClassId(e.target.value)} placeholder="Kurum geneli" className="w-[190px]" options={classGroups.map((g) => ({ value: g.id, label: g.name }))} />
        <Segmented size="sm" value={order} onChange={setOrder} options={[{ value: 'number', label: 'Soru sırası' }, { value: 'hardest', label: 'En çok yanlış' }, { value: 'blank', label: 'En çok boş' }]} />
        <span className="text-[12.5px] text-ink-3 ml-auto">{data.participants} öğrenci</span>
      </div>

      {data.highlights.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
          {data.highlights.map((h, i) => <Alert key={i} tone={h.tone}>{h.text}</Alert>)}
        </div>
      )}

      {sections.map((s) => (
        <Panel key={s.id} title={s.name} description={`${s.question_count} soru · başarı %${s.success_pct}`} flush>
          <div className="overflow-x-auto">
            <table className="tbl w-full text-[13px]">
              <thead className="bg-surface-2/60 text-[12px] text-ink-3">
                <tr>
                  <th className="px-3 py-2 font-medium w-14 text-left">No</th>
                  <th className="px-2 py-2 font-medium w-12 text-center">Cvp</th>
                  <th className="fill px-2 py-2 font-medium min-w-[160px] text-center">Konu</th>
                  <th className="px-2 py-2 font-medium min-w-[220px] text-center">Doğru / yanlış / boş</th>
                  <th className="px-2 py-2 font-medium min-w-[200px] text-center">Şık dağılımı</th>
                  <th className="px-2 py-2 font-medium text-center">Yanlış eğilim</th>
                  {can('exams.manage') && <th className="w-10 text-center" />}
                </tr>
              </thead>
              <tbody>
                {s.questions.map((q) => {
                  const total = Math.max(1, q.correct + q.wrong + q.blank)
                  return (
                    <tr key={q.id} className={cn('border-t border-line', q.is_cancelled && 'bg-primary-soft/40', !q.is_cancelled && q.wrong_pct >= 50 && 'bg-danger-soft/30')}>
                      <td className="px-3 py-2 tabular font-medium text-left">{q.number}<span className="ml-1 text-[12px] text-ink-3">{Object.entries(q.booklet_no).filter(([b]) => b !== 'A').map(([b, n]) => `${b}${n}`).join(' ')}</span></td>
                      <td className="px-2 py-2 text-center"><span className="inline-flex size-6 items-center justify-center rounded bg-surface-2 text-[12px] font-semibold ring-1 ring-line">{q.key || '?'}</span></td>
                      <td className="fill px-2 py-2 text-ink-2 text-center">{q.topic?.name ?? <span className="text-ink-3">—</span>}{q.is_cancelled && <Badge tone="primary" className="ml-1.5">İptal</Badge>}</td>
                      <td className="px-2 py-2 text-center">
                        <div className="flex items-center gap-2">
                          <div className="flex h-2 flex-1 overflow-hidden rounded-full bg-surface-3">
                            <span className="bg-success" style={{ width: `${q.correct_pct}%` }} />
                            <span className="bg-danger" style={{ width: `${q.wrong_pct}%` }} />
                          </div>
                          <span className="w-[112px] text-[12px] tabular"><span className="text-success">%{q.correct_pct}</span> <span className="text-ink-3">/</span> <span className="text-danger">%{q.wrong_pct}</span> <span className="text-ink-3">/ %{q.blank_pct}</span></span>
                        </div>
                      </td>
                      <td className="px-2 py-2 text-center">
                        <div className="flex items-end gap-1 h-7">
                          {(['A', 'B', 'C', 'D', 'E'] as const).map((c) => {
                            const pct = Math.round(((q.distribution[c] ?? 0) / total) * 100)
                            return (
                              <div key={c} className="flex flex-col items-center gap-0.5 w-8" title={`${c}: ${q.distribution[c] ?? 0} (%${pct})`}>
                                <div className="w-full flex items-end h-4"><div className={cn('w-full rounded-t-[2px]', c === q.key ? 'bg-success' : c === q.most_common_wrong ? 'bg-danger' : 'bg-ink-3/40')} style={{ height: `${Math.max(2, pct)}%` }} /></div>
                                <span className={cn('text-[10px] tabular', c === q.key ? 'text-success font-semibold' : 'text-ink-3')}>{c}</span>
                              </div>
                            )
                          })}
                        </div>
                      </td>
                      <td className="px-2 py-2 text-[12.5px] text-ink-2 whitespace-nowrap text-center">{q.most_common_wrong ? <>En çok <b className="text-danger">{q.most_common_wrong}</b> seçildi (%{q.most_common_wrong_pct})</> : <span className="text-ink-3">—</span>}</td>
                      {can('exams.manage') && (
                        <td className="px-1 py-2 text-right">
                          <Button size="icon-sm" variant="ghost" aria-label={q.is_cancelled ? 'İptali kaldır' : 'Soruyu iptal et'} title={q.is_cancelled ? 'İptali kaldır' : 'Soruyu iptal et'} onClick={() => setCancelTarget({ q, section: s.name })}>
                            {q.is_cancelled ? <Undo2 className="size-3.5" /> : <Ban className="size-3.5" />}
                          </Button>
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </Panel>
      ))}

      <ConfirmDialog
        open={cancelTarget !== null}
        onClose={() => setCancelTarget(null)}
        onConfirm={() => cancelTarget && cancel.mutate({ q: cancelTarget.q })}
        loading={cancel.isPending}
        title={cancelTarget?.q.is_cancelled ? 'Soru iptali kaldırılsın mı?' : 'Soru iptal edilsin mi?'}
        description={cancelTarget ? `${cancelTarget.section} ${cancelTarget.q.number}. soru. ${cancelTarget.q.is_cancelled ? 'Soru yeniden puanlamaya dahil edilir' : 'İptal edilen soru tüm öğrencilere doğru sayılır'}; sonuçlar ve sıralamalar hemen yeniden hesaplanır.` : undefined}
        confirmLabel={cancelTarget?.q.is_cancelled ? 'İptali kaldır' : 'İptal et'}
        danger={!cancelTarget?.q.is_cancelled}
      />
    </div>
  )
}

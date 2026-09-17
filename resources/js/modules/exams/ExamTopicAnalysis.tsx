import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Target } from 'lucide-react'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import { Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Select } from '@/components/ui/form'
import { rateTone, type ExamTopicAnalysis as TA } from './types'
import { RateBar } from './shared'

/** Sınav bazında konu/kazanım analizi + sınıf karşılaştırma matrisi. */
export function ExamTopicAnalysis({ examId, classGroups }: { examId: number; classGroups: { id: number; name: string }[] }) {
  const [classId, setClassId] = useState('')
  const [subject, setSubject] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['exam', examId, 'topics', classId], queryFn: () => api.get<TA>(`/exams/${examId}/analysis/topics`, { class_group_id: classId }) })

  const subjects = useMemo(() => [...new Set((data?.topics ?? []).map((t) => t.subject).filter((s): s is string => !!s))], [data])
  const topics = useMemo(() => (data?.topics ?? []).filter((t) => !subject || t.subject === subject), [data, subject])

  if (isLoading || !data) return <div className="flex flex-col gap-3"><Skeleton className="h-12" /><Skeleton className="h-64" /></div>
  if (data.participants === 0 || data.topics.length === 0) {
    return (
      <Panel>
        <EmptyState compact icon={<Target />} title={data.participants === 0 ? 'Analiz için sonuç yok' : 'Sorulara konu atanmamış'} description={data.participants === 0 ? 'Sonuçlar içe aktarıldığında konu başarıları burada görünür.' : 'Cevap anahtarı ekranında sorulara konu/kazanım atayın; analiz otomatik oluşur.'} action={data.participants > 0 ? <Link to={`/sinavlar/${examId}/cevap-anahtari`} className="text-primary hover:underline text-[13px]">Cevap anahtarına git →</Link> : undefined} />
      </Panel>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Select value={classId} onChange={(e) => setClassId(e.target.value)} placeholder="Kurum geneli" className="w-[190px]" options={classGroups.map((g) => ({ value: g.id, label: g.name }))} />
        <Select value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="Tüm dersler" className="w-[190px]" options={subjects.map((s) => ({ value: s, label: s }))} />
        <span className="ml-auto text-[12.5px] text-ink-3">{data.participants} öğrenci · {data.topics.length} konu</span>
      </div>
      {data.untagged_questions > 0 && <Alert tone="warning">{data.untagged_questions} soruya konu atanmamış; bu sorular analize girmiyor. <Link to={`/sinavlar/${examId}/cevap-anahtari`} className="underline">Cevap anahtarında ata</Link></Alert>}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <HighlightList title="En zor konular" items={data.hardest} tone="danger" />
        <HighlightList title="En başarılı konular" items={data.easiest} tone="success" />
      </div>

      <Panel title="Konu başarı tablosu" description="Doğru / soru×öğrenci; en zordan kolaya" flush>
        <div className="overflow-x-auto">
          <table className="tbl w-full text-[13px]">
            <thead className="bg-surface-2/60 text-[12px] text-ink-3">
              <tr>
                <th className="px-3 py-2 font-medium text-left">Konu</th>
                <th className="px-2 py-2 font-medium text-center">Ders</th>
                <th className="px-2 py-2 font-medium text-center">Sorular</th>
                <th className="px-2 py-2 font-medium text-center">D / Y / B</th>
                <th className="px-2 py-2 font-medium min-w-[160px] text-center">Başarı</th>
                {data.classes.map((c) => <th key={c.id} className="px-2 py-2 font-medium whitespace-nowrap text-center">{c.name}</th>)}
              </tr>
            </thead>
            <tbody>
              {topics.map((t) => (
                <tr key={t.id} className="border-t border-line">
                  <td className="px-3 py-2 font-medium text-left">{t.name}{t.outcome_code && <span className="ml-1.5 text-[12px] text-ink-3">{t.outcome_code}</span>}</td>
                  <td className="px-2 py-2 text-ink-2 text-center">{t.subject}</td>
                  <td className="px-2 py-2 text-ink-3 tabular text-[12px] text-center">{t.numbers}</td>
                  <td className="px-2 py-2 tabular text-[12.5px] text-center"><span className="text-success">{t.correct}</span> / <span className="text-danger">{t.wrong}</span> / <span className="text-ink-3">{t.blank}</span></td>
                  <td className="px-2 py-2 text-center"><RateBar rate={t.rate} /></td>
                  {data.classes.map((c) => {
                    const r = c.rates[String(t.id)] ?? null
                    return <td key={c.id} className={cn('px-2 py-2 tabular text-center', r === null ? 'text-ink-3' : r < 50 ? 'text-danger' : r < 70 ? 'text-warning' : 'text-success')}>{r === null ? '—' : `%${r}`}</td>
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}

function HighlightList({ title, items, tone }: { title: string; items: TA['hardest']; tone: 'danger' | 'success' }) {
  return (
    <Panel title={title}>
      {items.length === 0 ? <p className="text-[12.5px] text-ink-3">—</p> : (
        <ol className="flex flex-col gap-2">
          {items.map((t, i) => (
            <li key={t.id} className="flex items-center gap-2.5 text-[13px]">
              <span className={cn('grid size-5 place-items-center rounded-full text-[11px] font-semibold', tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-success-soft text-success')}>{i + 1}</span>
              <span className="flex-1 truncate">{t.name} <span className="text-ink-3">· {t.subject}</span></span>
              <Badge tone={rateTone(t.rate)}>%{t.rate}</Badge>
            </li>
          ))}
        </ol>
      )}
    </Panel>
  )
}

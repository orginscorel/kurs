import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Search, Target, X } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Select } from '@/components/ui/form'
import { rateTone, type CumulativeTopics } from './types'
import { RateBar, useExamOptions } from './shared'

type StudentLite = { id: number; full_name: string; student_no: string; photo_url: string | null; class_groups: { id: number; name: string }[] }

/** Kümülatif konu/kazanım analizi: kurum geneli, sınıf karşılaştırması ve öğrenci GÜÇLÜ / GELİŞMESİ GEREKEN konular. */
export default function TopicAnalysisPage() {
  const list = useListState()
  const options = useExamOptions()
  const classId = list.filters.class_group_id ?? ''
  const subjectId = list.filters.subject_id ?? ''
  const studentId = list.filters.student_id ?? ''
  const [q, setQ] = useState('')
  const dq = useDebounced(q, 250)
  const search = useQuery({ queryKey: ['students', 'search', dq], queryFn: () => api.get<Paginated<StudentLite>>('/students', { q: dq, per_page: 8 }), enabled: dq.length >= 2 })
  const student = useQuery({ queryKey: ['student-lite', studentId], queryFn: () => api.get<{ student: StudentLite }>(`/students/${studentId}`), enabled: !!studentId })

  const { data, isLoading } = useQuery({
    queryKey: ['exams', 'topic-analysis', classId, subjectId, studentId],
    queryFn: () => api.get<CumulativeTopics>('/exams/topic-analysis', { class_group_id: classId, subject_id: subjectId, student_id: studentId, min_asked: studentId ? 2 : 5 }),
  })
  const subjects = options.data?.subjects ?? []
  const subjectsInData = useMemo(() => (data?.subjects ?? []), [data])

  return (
    <div className="animate-fade-in">
      <PageHeader title="Kazanım Analizi" description="Tüm yayımlanmış denemelerden kümülatif konu başarısı: kurum geneli, sınıf karşılaştırması ve öğrenci profili." />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Select value={classId} onChange={(e) => list.update({ filters: { class_group_id: e.target.value } })} placeholder="Tüm sınıflar" className="w-[180px]" options={(options.data?.class_groups ?? []).map((g) => ({ value: g.id, label: g.name }))} />
        <Select value={subjectId} onChange={(e) => list.update({ filters: { subject_id: e.target.value } })} placeholder="Tüm dersler" className="w-[180px]" options={subjects.map((s) => ({ value: s.id, label: s.name }))} />
        <div className="relative w-full sm:w-[280px]">
          {studentId && student.data ? (
            <div className="flex h-9 items-center gap-2 rounded-[var(--radius-sm)] bg-primary-soft px-2.5 text-[13px] text-primary-ink">
              <Avatar name={student.data.student.full_name} src={student.data.student.photo_url} size={22} />
              <span className="flex-1 truncate font-medium">{student.data.student.full_name}</span>
              <button onClick={() => list.update({ filters: { student_id: null } })} aria-label="Öğrenciyi kaldır" className="grid size-6 place-items-center rounded hover:bg-surface/60"><X className="size-3.5" /></button>
            </div>
          ) : (
            <>
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Öğrenci ara (ad ya da no)" leading={<Search />} />
              {search.data && dq.length >= 2 && search.data.data.length > 0 && (
                <ul className="absolute z-20 mt-1 w-full rounded-[var(--radius-md)] bg-surface p-1 ring-1 ring-line shadow-[var(--shadow-pop)]">
                  {search.data.data.map((s) => (
                    <li key={s.id}><button type="button" className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-[13px] hover:bg-surface-2" onClick={() => { list.update({ filters: { student_id: String(s.id) } }); setQ('') }}><Avatar name={s.full_name} src={s.photo_url} size={22} /><span className="flex-1 truncate">{s.full_name}</span><span className="text-ink-3 tabular">{s.student_no}</span></button></li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
        {(classId || subjectId || studentId) && <Button size="sm" variant="ghost" onClick={() => list.update({ filters: { class_group_id: null, subject_id: null, student_id: null } })}>Temizle</Button>}
      </div>

      {isLoading || !data ? (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4"><Skeleton className="h-64 rounded-[var(--radius-lg)]" /><Skeleton className="h-64 rounded-[var(--radius-lg)]" /></div>
      ) : data.topics.length === 0 ? (
        <EmptyState icon={<Target />} title="Yeterli veri yok" description={studentId ? 'Bu öğrencinin yayımlanmış deneme sonucu ya da konu atanmış sorusu yok.' : 'Denemeler yayımlandıkça ve sorulara konu atandıkça analiz burada oluşur.'} />
      ) : (
        <>
          {data.student && (
            <div className="mb-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
              <StudentTopicPanel title="GÜÇLÜ konular" tone="success" items={data.student.strong} empty="≥%70 başarılı konu yok." />
              <StudentTopicPanel title="GELİŞMESİ GEREKEN konular" tone="danger" items={data.student.weak} empty="<%50 başarılı konu yok — tebrikler." />
            </div>
          )}
          {!data.student && (
            <div className="mb-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
              <StudentTopicPanel title="Kurum geneli en zor konular" tone="danger" items={data.hardest} empty="—" />
              <StudentTopicPanel title="Kurum geneli en başarılı konular" tone="success" items={data.easiest} empty="—" />
            </div>
          )}

          {data.classes.length > 1 && (
            <Panel title="Sınıf × ders karşılaştırması" description="Başarı oranı (doğru / sorulan)" flush className="mb-4">
              <div className="overflow-x-auto">
                <table className="tbl w-full text-[13px]">
                  <thead className="bg-surface-2/60 text-[12px] text-ink-3"><tr><th className="px-3 py-2 font-medium text-left">Sınıf</th><th className="px-2 py-2 font-medium text-center">Öğr.</th><th className="px-2 py-2 font-medium text-center">Genel</th>{subjectsInData.map((s) => <th key={s.id} className="px-2 py-2 font-medium whitespace-nowrap text-center">{s.name}</th>)}</tr></thead>
                  <tbody>
                    {data.classes.map((c) => (
                      <tr key={c.id} className="border-t border-line">
                        <td className="px-3 py-2 font-medium text-left">{c.name}</td>
                        <td className="px-2 py-2 tabular text-ink-3 text-center">{c.students}</td>
                        <td className="px-2 py-2 text-center"><Badge tone={rateTone(c.rate)}>{c.rate === null ? '—' : `%${c.rate}`}</Badge></td>
                        {subjectsInData.map((s) => { const r = c.subjects[String(s.id)] ?? null; return <td key={s.id} className={cn('px-2 py-2 tabular text-center', r === null ? 'text-ink-3' : r < 50 ? 'text-danger' : r < 70 ? 'text-warning' : 'text-success')}>{r === null ? '—' : `%${r}`}</td> })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}

          <Panel title="Konu tablosu" description={`${data.topics.length} konu · en zordan kolaya`} flush>
            {data.topics.some((t) => t.students < 3) && !studentId && <Alert tone="info" className="m-3">Az sayıda öğrencinin çözdüğü konular istatistiksel olarak zayıf olabilir.</Alert>}
            <div className="overflow-x-auto">
              <table className="tbl w-full text-[13px]">
                <thead className="bg-surface-2/60 text-[12px] text-ink-3"><tr><th className="px-3 py-2 font-medium text-left">Konu</th><th className="px-2 py-2 font-medium text-center">Ders</th><th className="px-2 py-2 font-medium text-center">Öğrenci</th><th className="px-2 py-2 font-medium text-center">Soru</th><th className="px-2 py-2 font-medium text-center">D / Y / B</th><th className="px-2 py-2 font-medium min-w-[170px] text-center">Başarı</th></tr></thead>
                <tbody>
                  {data.topics.map((t) => (
                    <tr key={t.id} className="border-t border-line">
                      <td className="px-3 py-2 font-medium text-left">{t.name}{t.outcome_code && <span className="ml-1.5 text-[12px] text-ink-3">{t.outcome_code}</span>}</td>
                      <td className="px-2 py-2 text-ink-2 text-center">{t.subject}</td>
                      <td className="px-2 py-2 tabular text-ink-3 text-center">{t.students}</td>
                      <td className="px-2 py-2 tabular text-ink-3 text-center">{t.asked}</td>
                      <td className="px-2 py-2 tabular text-[12.5px] text-center"><span className="text-success">{t.correct}</span> / <span className="text-danger">{t.wrong}</span> / <span className="text-ink-3">{t.blank}</span></td>
                      <td className="px-2 py-2 text-center"><RateBar rate={t.rate} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          {studentId && <p className="mt-3 text-[12.5px] text-ink-3">Öğrencinin tüm sınav geçmişi için <Link to={`/ogrenciler/${studentId}`} className="text-primary hover:underline">öğrenci profiline</Link> gidin.</p>}
        </>
      )}
    </div>
  )
}

function StudentTopicPanel({ title, tone, items, empty }: { title: string; tone: 'success' | 'danger'; items: CumulativeTopics['hardest']; empty: string }) {
  return (
    <Panel title={<span className={tone === 'success' ? 'text-success' : 'text-danger'}>{title}</span>}>
      {items.length === 0 ? <p className="text-[12.5px] text-ink-3">{empty}</p> : (
        <ul className="flex flex-col gap-2">
          {items.map((t) => (
            <li key={t.id} className="flex items-center gap-2 text-[13px]">
              <span className="flex-1 truncate">{t.name} <span className="text-ink-3">· {t.subject}</span></span>
              <span className="text-[12px] text-ink-3 tabular hidden sm:inline">{t.correct}/{t.asked}</span>
              <RateBar rate={t.rate} className="w-36" />
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

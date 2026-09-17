import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Trophy } from 'lucide-react'
import { api, type Paginated } from '@/lib/api'
import { PageHeader } from '@/components/ui/layout'
import { EmptyState, Skeleton } from '@/components/ui/feedback'
import { ButtonLink } from '@/components/ui/Button'
import type { ExamRow } from './types'
import { ExamPicker } from './shared'
import { ResultsTable } from './ResultsTable'

/** Sonuçlar: sınav seçici + sonuç tablosu (varsayılan: son yayımlanan sınav). */
export default function ResultsPage() {
  const [params, setParams] = useSearchParams()
  const selected = params.get('sinav') ?? ''
  const latest = useQuery({ queryKey: ['exams', 'latest-published'], queryFn: () => api.get<Paginated<ExamRow>>('/exams', { per_page: 1, status: 'results_published', sort: '-exam_date' }), enabled: !selected })
  const examId = selected ? Number(selected) : latest.data?.data[0]?.id

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Sınav Sonuçları"
        description="Öğrenci sonuç tablosu; ders netleri, puan ve sıralamalar. Satıra tıklayarak cevap analizi ve sonuç kartına ulaşın."
        actions={
          <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto min-w-0">
            <ExamPicker value={examId ? String(examId) : ''} onChange={(v) => setParams((p) => { v ? p.set('sinav', v) : p.delete('sinav'); p.delete('page'); p.delete('q'); p.delete('class_group_id'); return p })} className="w-full sm:w-[320px] min-w-0" />
            {examId && <ButtonLink to={`/sinavlar/${examId}`} variant="ghost">Sınav sayfası</ButtonLink>}
          </div>
        }
      />
      {!selected && latest.isLoading ? (
        <Skeleton className="h-72 rounded-[var(--radius-lg)]" />
      ) : examId ? (
        <ResultsTable key={examId} examId={examId} storageKey="results-page" />
      ) : (
        <EmptyState icon={<Trophy />} title="Yayımlanmış sonuç yok" description="Bir deneme yayımlandığında sonuçları burada görebilirsiniz." action={<ButtonLink to="/sinavlar" variant="primary">Denemelere git</ButtonLink>} />
      )}
    </div>
  )
}

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Ban, ClipboardPaste, KeyRound, Save, Undo2 } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Kbd, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { TopicQuickAdd } from '@/components/ui/TopicQuickAdd'
import type { ExamStatus } from './types'
import { ExamStatusBadge, useExamOptions } from './shared'

type KeyData = {
  exam: { id: number; name: string; status: ExamStatus; booklets: string[]; type: string | null; has_results: boolean }
  sections: { id: number; code: string; name: string; subject_id: number | null; subject: string | null; question_count: number; answers: string; booklet_orders: Record<string, number[]>; topics: Record<string, number>; cancelled: number[]; question_ids: Record<string, number> }[]
}

type SectionState = {
  code: string
  name: string
  subject_id: number | null
  n: number
  answers: string[]
  orders: Record<string, number[]>
  topics: Record<number, number | null>
  cancelled: Set<number>
  ids: Record<string, number>
}

const LETTERS = ['A', 'B', 'C', 'D', 'E']

export default function AnswerKeyEditor() {
  const { id } = useParams()
  const examId = Number(id)
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { data, isLoading, error } = useQuery({ queryKey: ['exam', examId, 'answer-key'], queryFn: () => api.get<KeyData>(`/exams/${examId}/answer-key`) })
  const options = useExamOptions()

  const [sections, setSections] = useState<SectionState[]>([])
  const [active, setActive] = useState(0)
  const [cursor, setCursor] = useState(0)
  const [dirty, setDirty] = useState(false)
  const [pasteOpen, setPasteOpen] = useState(false)
  const [pasteText, setPasteText] = useState('')
  const [bulk, setBulk] = useState({ from: '1', to: '10', topic: '' })
  const [cancelConfirm, setCancelConfirm] = useState<number | null>(null)
  const gridRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!data) return
    setSections(data.sections.map((s) => ({
      code: s.code, name: s.name, subject_id: s.subject_id, n: s.question_count,
      answers: s.answers.padEnd(s.question_count, ' ').slice(0, s.question_count).split(''),
      orders: Object.fromEntries(Object.entries(s.booklet_orders ?? {}).map(([b, o]) => [b, [...o]])),
      topics: Object.fromEntries(Array.from({ length: s.question_count }, (_, i) => [i + 1, s.topics?.[String(i + 1)] ?? null])),
      cancelled: new Set(s.cancelled), ids: s.question_ids ?? {},
    })))
    setDirty(false)
    setActive(0)
    setCursor(0)
  }, [data])

  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => { if (dirty) { e.preventDefault() } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  const sec = sections[active]
  const booklets = data?.exam.booklets ?? ['A']
  const otherBooklets = booklets.filter((b) => b !== 'A')

  const update = useCallback((fn: (s: SectionState) => SectionState) => {
    setSections((list) => list.map((s, i) => (i === active ? fn(s) : s)))
    setDirty(true)
  }, [active])

  const setAnswer = useCallback((idx: number, letter: string) => {
    update((s) => { const a = [...s.answers]; a[idx] = letter; return { ...s, answers: a } })
  }, [update])

  const inGrid = (target: EventTarget | null) => !!gridRef.current && gridRef.current.contains(target as Node)

  const onKey = (e: React.KeyboardEvent) => {
    if (!sec || !inGrid(e.target)) return
    const k = e.key.toUpperCase()
    if (LETTERS.includes(k) && !e.ctrlKey && !e.metaKey) {
      e.preventDefault()
      setAnswer(cursor, k)
      setCursor((c) => Math.min(sec.n - 1, c + 1))
    } else if (e.key === 'Backspace') {
      e.preventDefault()
      if (sec.answers[cursor] !== ' ') setAnswer(cursor, ' ')
      else setCursor((c) => Math.max(0, c - 1))
    } else if (e.key === ' ' || e.key === '-' || e.key === '.' || e.key === 'Delete') {
      e.preventDefault()
      setAnswer(cursor, ' ')
      setCursor((c) => Math.min(sec.n - 1, c + 1))
    } else if (e.key === 'ArrowRight') { e.preventDefault(); setCursor((c) => Math.min(sec.n - 1, c + 1)) }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); setCursor((c) => Math.max(0, c - 1)) }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setCursor((c) => Math.min(sec.n - 1, c + 10)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setCursor((c) => Math.max(0, c - 10)) }
    else if (e.key === 'Home') { e.preventDefault(); setCursor(0) }
    else if (e.key === 'End') { e.preventDefault(); setCursor(sec.n - 1) }
    else if (e.key === 'Tab' && !e.shiftKey && active < sections.length - 1) { e.preventDefault(); setActive(active + 1); setCursor(0) }
  }

  const onPaste = (e: React.ClipboardEvent) => {
    if (!inGrid(e.target)) return
    const text = e.clipboardData.getData('text')
    if (!text) return
    e.preventDefault()
    applyPaste(text, cursor)
  }

  const applyPaste = (text: string, from: number) => {
    if (!sec) return
    const letters = text.toUpperCase().replace(/[^ABCDE\-\.\s]/g, '').replace(/[\-\.]/g, ' ').replace(/\s+/g, (m) => m.includes('\n') ? '' : ' ').replace(/\n/g, '')
    const cleaned = letters.replace(/ /g, '').length === letters.length ? letters : letters
    update((s) => {
      const a = [...s.answers]
      let i = from
      for (const ch of cleaned) { if (i >= s.n) break; if (LETTERS.includes(ch) || ch === ' ') { a[i] = ch; i++ } }
      return { ...s, answers: a }
    })
    const placed = Math.min(sec.n, from + cleaned.length)
    setCursor(Math.min(sec.n - 1, placed))
    toast.success(`${Math.min(cleaned.length, sec.n - from)} cevap yerleştirildi.`)
  }

  const setOrder = (b: string, mode: 'same' | 'reverse' | 'shift' | 'custom', arg?: string) => {
    if (!sec) return
    const n = sec.n
    let order: number[] | null = null
    if (mode === 'same') order = Array.from({ length: n }, (_, i) => i + 1)
    if (mode === 'reverse') order = Array.from({ length: n }, (_, i) => n - i)
    if (mode === 'shift') { const k = ((Number(arg || 0) % n) + n) % n; order = Array.from({ length: n }, (_, i) => ((i + k) % n) + 1) }
    if (mode === 'custom') {
      const nums = (arg ?? '').split(/[\s,;]+/).filter(Boolean).map(Number)
      if (nums.length !== n || new Set(nums).size !== n || nums.some((x) => !Number.isInteger(x) || x < 1 || x > n)) { toast.error(`${n} adet, 1–${n} arasında ve tekrarsız numara girin.`); return }
      order = nums
    }
    if (order) update((s) => ({ ...s, orders: { ...s.orders, [b]: order! } }))
  }

  const applyBulkTopic = () => {
    if (!sec) return
    const from = Math.max(1, Number(bulk.from)), to = Math.min(sec.n, Number(bulk.to))
    if (!from || !to || from > to) { toast.error('Geçerli bir soru aralığı girin.'); return }
    update((s) => { const t = { ...s.topics }; for (let q = from; q <= to; q++) t[q] = bulk.topic ? Number(bulk.topic) : null; return { ...s, topics: t } })
    toast.success(`${to - from + 1} soruya konu atandı.`)
  }

  const save = useMutation({
    mutationFn: () => api.put<{ message: string; status: ExamStatus }>(`/exams/${examId}/answer-key`, {
      sections: sections.map((s) => ({ code: s.code, answers: s.answers.join(''), booklet_orders: s.orders, topics: s.topics })),
    }),
    onSuccess: (r) => { toast.success(r.message); setDirty(false); qc.invalidateQueries({ queryKey: ['exam', examId] }); qc.invalidateQueries({ queryKey: ['exams'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const cancelQ = useMutation({
    mutationFn: ({ qid, cancelled }: { qid: number; cancelled: boolean }) => api.post<{ message: string }>(`/exams/${examId}/questions/${qid}/cancel`, { cancelled }),
    onSuccess: (r) => { toast.success(r.message); setCancelConfirm(null); qc.invalidateQueries({ queryKey: ['exam', examId] }) },
    onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'İşlem yapılamadı.'); setCancelConfirm(null) },
  })

  const topicOptions = useMemo(() => {
    const subjects = options.data?.subjects ?? []
    const own = subjects.find((s) => s.id === sec?.subject_id)
    const list = own ? [own] : subjects
    return list.flatMap((s) => s.topics.map((t) => ({ value: t.id, label: own ? (t.outcome_code ? `${t.outcome_code} · ${t.name}` : t.name) : `${s.name} › ${t.name}` })))
  }, [options.data, sec?.subject_id])
  const topicName = (tid: number | null) => (tid ? (options.data?.subjects.flatMap((s) => s.topics).find((t) => t.id === tid)?.name ?? '—') : null)

  if (error) return <EmptyState title="Deneme bulunamadı" action={<ButtonLink to="/sinavlar">Denemelere dön</ButtonLink>} />
  if (isLoading || !data || !sec) return <div className="animate-fade-in"><Skeleton className="h-7 w-72 mb-6" /><Skeleton className="h-96" /></div>
  if (!can('exams.manage')) return <EmptyState title="Bu sayfa için yetkiniz yok" action={<ButtonLink to={`/sinavlar/${examId}`}>Sınava dön</ButtonLink>} />

  const filled = (s: SectionState) => s.answers.filter((a) => a !== ' ').length
  const totalFilled = sections.reduce((a, s) => a + filled(s), 0)
  const totalN = sections.reduce((a, s) => a + s.n, 0)
  const activeTopic = sec.topics[cursor + 1] ?? null
  const activeQid = sec.ids[String(cursor + 1)]

  return (
    <div className="animate-fade-in" onKeyDown={onKey} onPaste={onPaste}>
      <PageHeader
        breadcrumbs={[{ label: 'Denemeler', to: '/sinavlar' }, { label: data.exam.name, to: `/sinavlar/${examId}` }, { label: 'Cevap anahtarı' }]}
        title={<span className="inline-flex items-center gap-2.5">Cevap anahtarı <ExamStatusBadge status={data.exam.status} /></span>}
        description={`${totalFilled}/${totalN} cevap girildi · Kitapçık ${booklets.join('/')}`}
        actions={
          <>
            {dirty && <Badge tone="warning" dot>Kaydedilmemiş değişiklik</Badge>}
            <Button variant="ghost" onClick={() => (dirty ? window.confirm('Kaydedilmemiş değişiklikler var. Çıkılsın mı?') && navigate(`/sinavlar/${examId}`) : navigate(`/sinavlar/${examId}`))}>Sınava dön</Button>
            <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} disabled={!dirty && data.exam.status !== 'draft'} onClick={() => save.mutate()}>Kaydet</Button>
          </>
        }
      />

      {data.exam.has_results && <Alert tone="info" className="mb-4">Bu sınavda puanlanmış sonuçlar var. Anahtar kaydedilince tüm sonuçlar yeniden puanlanır ve sıralamalar güncellenir.</Alert>}

      <div className="grid grid-cols-1 lg:grid-cols-[220px_minmax(0,1fr)_300px] gap-4 items-start">
        {/* Bölümler */}
        <Panel flush className="lg:sticky lg:top-[72px]">
          <ul className="p-1.5">
            {sections.map((s, i) => {
              const f = filled(s)
              return (
                <li key={s.code}>
                  <button type="button" onClick={() => { setActive(i); setCursor(0); gridRef.current?.focus() }} className={cn('flex w-full items-center gap-2 rounded-[6px] px-2.5 py-2 text-left text-[13px] transition-colors', i === active ? 'bg-primary-soft text-primary-ink' : 'hover:bg-surface-2')}>
                    <span className="flex-1 truncate font-medium">{s.name}</span>
                    <span className={cn('tabular text-[12px]', f === s.n ? 'text-success' : 'text-ink-3')}>{f}/{s.n}</span>
                  </button>
                </li>
              )
            })}
          </ul>
          <div className="border-t border-line px-3.5 py-3 text-[12px] text-ink-3 leading-relaxed">
            <p className="mb-1 font-medium text-ink-2">Klavye</p>
            <p><Kbd>A</Kbd>–<Kbd>E</Kbd> cevap, otomatik ilerler</p>
            <p><Kbd>Boşluk</Kbd> boş bırak · <Kbd>⌫</Kbd> sil</p>
            <p><Kbd>←</Kbd><Kbd>→</Kbd> gez · <Kbd>Tab</Kbd> sonraki bölüm</p>
            <p><Kbd>Ctrl</Kbd>+<Kbd>V</Kbd> "ABCDE…" yapıştır</p>
          </div>
        </Panel>

        {/* Izgara */}
        <div className="flex flex-col gap-4 min-w-0">
          <Panel
            title={`${sec.name} · ${sec.n} soru`}
            description={sec.subject_id ? undefined : 'Bu bölüme ders atanmadığından tüm konular listelenir'}
            actions={
              <>
                <Button size="sm" variant="ghost" icon={<ClipboardPaste className="size-3.5" />} onClick={() => setPasteOpen((v) => !v)}>Yapıştır</Button>
                <Button size="sm" variant="ghost" onClick={() => { update((s) => ({ ...s, answers: Array(s.n).fill(' ') })); setCursor(0) }}>Temizle</Button>
              </>
            }
          >
            {pasteOpen && (
              <div className="mb-3 flex flex-col gap-2 rounded-[var(--radius-md)] bg-surface-2 p-3">
                <Textarea rows={2} value={pasteText} onChange={(e) => setPasteText(e.target.value)} placeholder="ABCDEABCDE… (boşluk, - ya da . boş soru; satır sonları yok sayılır)" className="font-mono tracking-widest" />
                <div className="flex items-center gap-2">
                  <Button size="sm" variant="primary" disabled={!pasteText.trim()} onClick={() => { applyPaste(pasteText, 0); setPasteText(''); setPasteOpen(false) }}>1. sorudan itibaren yerleştir</Button>
                  <Button size="sm" disabled={!pasteText.trim()} onClick={() => { applyPaste(pasteText, cursor); setPasteText(''); setPasteOpen(false) }}>{cursor + 1}. sorudan itibaren</Button>
                </div>
              </div>
            )}
            <div ref={gridRef} tabIndex={0} className="grid grid-cols-5 sm:grid-cols-10 gap-1.5 outline-none rounded-[var(--radius-md)] focus-visible:ring-2 focus-visible:ring-primary/40 p-0.5" aria-label="Cevap anahtarı ızgarası">
              {sec.answers.map((a, i) => {
                const isCancelled = sec.cancelled.has(i + 1)
                const hasTopic = !!sec.topics[i + 1]
                return (
                  <button
                    key={i}
                    type="button"
                    onClick={() => { setCursor(i); gridRef.current?.focus() }}
                    className={cn(
                      'relative flex h-12 flex-col items-center justify-center rounded-[6px] ring-1 ring-inset text-[13px] transition-colors',
                      i === cursor ? 'ring-2 ring-primary bg-primary-soft' : a === ' ' ? 'ring-line bg-surface hover:bg-surface-2' : 'ring-line bg-surface-2/70 hover:bg-surface-2',
                      isCancelled && 'opacity-60 line-through',
                    )}
                    title={`${i + 1}. soru${otherBooklets.map((b) => ` · ${b}: ${sec.orders[b]?.[i] ?? i + 1}`).join('')}${hasTopic ? ` · ${topicName(sec.topics[i + 1] ?? null)}` : ''}`}
                  >
                    <span className="text-[10px] text-ink-3 tabular leading-none">{i + 1}</span>
                    <span className={cn('mt-0.5 text-[15px] font-semibold leading-none', a === ' ' ? 'text-ink-3' : 'text-ink')}>{a === ' ' ? '·' : a}</span>
                    {hasTopic && <span className="absolute right-1 top-1 size-1.5 rounded-full bg-accent" />}
                  </button>
                )
              })}
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-[12px] text-ink-3">
              <span className="inline-flex items-center gap-1"><span className="size-1.5 rounded-full bg-accent" /> konu atanmış</span>
              <span>· üstü çizili: iptal</span>
              <span className="ml-auto">Aktif: <b className="text-ink">{cursor + 1}. soru</b></span>
            </div>
          </Panel>

          {otherBooklets.length > 0 && (
            <Panel title="Kitapçık eşlemesi" description="B kitapçığında sorular farklı sırada olabilir; A'daki i. sorunun B'deki numarası">
              {otherBooklets.map((b) => <BookletOrderEditor key={b} booklet={b} n={sec.n} order={sec.orders[b] ?? Array.from({ length: sec.n }, (_, i) => i + 1)} onApply={(mode, arg) => setOrder(b, mode, arg)} />)}
            </Panel>
          )}
        </div>

        {/* Sağ: aktif soru + toplu konu */}
        <div className="flex flex-col gap-4 lg:sticky lg:top-[72px]">
          <Panel title={`${cursor + 1}. soru`} description={`Cevap: ${sec.answers[cursor] === ' ' ? 'boş' : sec.answers[cursor]}${otherBooklets.map((b) => ` · ${b}: ${sec.orders[b]?.[cursor] ?? cursor + 1}`).join('')}`}>
            <div className="flex gap-1 mb-3">
              {LETTERS.map((l) => (
                <button key={l} type="button" onClick={() => { setAnswer(cursor, l); setCursor((c) => Math.min(sec.n - 1, c + 1)); gridRef.current?.focus() }} className={cn('h-9 flex-1 rounded-[6px] text-[13.5px] font-semibold ring-1 ring-inset transition-colors', sec.answers[cursor] === l ? 'bg-primary text-white ring-primary' : 'bg-surface ring-line hover:bg-surface-2')}>{l}</button>
              ))}
            </div>
            <Field label="Konu / kazanım">
              <Select value={activeTopic ? String(activeTopic) : ''} onChange={(e) => update((s) => ({ ...s, topics: { ...s.topics, [cursor + 1]: e.target.value ? Number(e.target.value) : null } }))} placeholder="Konu seçin" options={topicOptions} />
              <div className="mt-1.5">
                <TopicQuickAdd subjectId={sec.subject_id} onCreated={(t) => { qc.invalidateQueries({ queryKey: ['exams', 'options'] }); update((s) => ({ ...s, topics: { ...s.topics, [cursor + 1]: t.id } })) }} />
              </div>
            </Field>
            {activeQid ? (
              <div className="mt-3 flex items-center justify-between rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[12.5px]">
                <span>{sec.cancelled.has(cursor + 1) ? <Badge tone="primary">İptal edilmiş</Badge> : 'Soru geçerli'}</span>
                <Button size="xs" variant={sec.cancelled.has(cursor + 1) ? 'ghost' : 'outline'} icon={sec.cancelled.has(cursor + 1) ? <Undo2 className="size-3.5" /> : <Ban className="size-3.5" />} onClick={() => setCancelConfirm(activeQid)}>{sec.cancelled.has(cursor + 1) ? 'İptali kaldır' : 'İptal et'}</Button>
              </div>
            ) : (
              <p className="mt-3 text-[12px] text-ink-3">Soru iptali, anahtar bir kez kaydedildikten sonra yapılabilir.</p>
            )}
          </Panel>

          <Panel title="Toplu konu atama" description='"1-10 → Problemler" gibi'>
            <div className="grid grid-cols-2 gap-2">
              <Field label="Başlangıç"><Input type="number" min={1} max={sec.n} value={bulk.from} onChange={(e) => setBulk((b) => ({ ...b, from: e.target.value }))} /></Field>
              <Field label="Bitiş"><Input type="number" min={1} max={sec.n} value={bulk.to} onChange={(e) => setBulk((b) => ({ ...b, to: e.target.value }))} /></Field>
            </div>
            <Field label="Konu" className="mt-2"><Select value={bulk.topic} onChange={(e) => setBulk((b) => ({ ...b, topic: e.target.value }))} placeholder="Konu (boş = temizle)" options={topicOptions} /></Field>
            <div className="mt-1.5"><TopicQuickAdd subjectId={sec.subject_id} onCreated={(t) => { qc.invalidateQueries({ queryKey: ['exams', 'options'] }); setBulk((b) => ({ ...b, topic: String(t.id) })) }} /></div>
            <Button className="mt-3 w-full" size="sm" onClick={applyBulkTopic}>Aralığa uygula</Button>
            <ul className="mt-3 max-h-48 overflow-y-auto scroll-thin divide-y divide-line text-[12px]">
              {Object.entries(sec.topics).filter(([, t]) => t).map(([q, t]) => (
                <li key={q} className="flex items-center gap-2 py-1"><span className="w-6 tabular text-ink-3">{q}</span><span className="flex-1 truncate text-ink-2">{topicName(t)}</span></li>
              ))}
              {Object.values(sec.topics).every((t) => !t) && <li className="py-1 text-ink-3">Henüz konu atanmadı.</li>}
            </ul>
          </Panel>
        </div>
      </div>

      <div className="mt-6 flex items-center justify-between rounded-[var(--radius-lg)] bg-surface ring-1 ring-line px-4 py-3">
        <span className="inline-flex items-center gap-2 text-[13px] text-ink-2"><KeyRound className="size-4 text-ink-3" /> {totalFilled === totalN ? 'Anahtar eksiksiz; kaydedince sınav optik okumaya hazır olur.' : `${totalN - totalFilled} cevap eksik.`}</span>
        <Button variant="primary" icon={<Save className="size-4" />} loading={save.isPending} onClick={() => save.mutate()}>Kaydet</Button>
      </div>

      <ConfirmDialog
        open={cancelConfirm !== null}
        onClose={() => setCancelConfirm(null)}
        onConfirm={() => cancelConfirm && cancelQ.mutate({ qid: cancelConfirm, cancelled: !sec.cancelled.has(cursor + 1) })}
        loading={cancelQ.isPending}
        title={sec.cancelled.has(cursor + 1) ? 'Soru iptali kaldırılsın mı?' : 'Soru iptal edilsin mi?'}
        description={`${sec.name} ${cursor + 1}. soru. ${sec.cancelled.has(cursor + 1) ? 'Soru yeniden puanlamaya dahil edilir' : 'İptal edilen soru tüm öğrencilere doğru sayılır'}; varsa sonuçlar hemen yeniden puanlanır.${dirty ? ' Kaydedilmemiş anahtar değişiklikleri bu işlemden etkilenmez.' : ''}`}
        confirmLabel={sec.cancelled.has(cursor + 1) ? 'İptali kaldır' : 'İptal et'}
        danger={!sec.cancelled.has(cursor + 1)}
      />
    </div>
  )
}

function BookletOrderEditor({ booklet, n, order, onApply }: { booklet: string; n: number; order: number[]; onApply: (mode: 'same' | 'reverse' | 'shift' | 'custom', arg?: string) => void }) {
  const [mode, setMode] = useState<'same' | 'reverse' | 'shift' | 'custom'>('same')
  const [shift, setShift] = useState('10')
  const [custom, setCustom] = useState('')
  const isSame = order.every((v, i) => v === i + 1)
  const isReverse = order.every((v, i) => v === n - i)

  return (
    <div className="mb-3 last:mb-0">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone="primary">{booklet} kitapçığı</Badge>
        <span className="text-[12.5px] text-ink-3">{isSame ? 'A ile aynı sıra' : isReverse ? 'A’nın tersi' : 'özel sıra'}</span>
        <Segmented size="sm" className="ml-auto" value={mode} onChange={setMode} options={[{ value: 'same', label: 'A ile aynı' }, { value: 'reverse', label: 'Ters' }, { value: 'shift', label: 'Kaydırma' }, { value: 'custom', label: 'Yapıştır' }]} />
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        {mode === 'shift' && <Input type="number" value={shift} onChange={(e) => setShift(e.target.value)} className="w-24" placeholder="+n" />}
        {mode === 'custom' && <Input value={custom} onChange={(e) => setCustom(e.target.value)} className="flex-1 min-w-[200px] font-mono" placeholder={`${n} numara: 7, 3, 12, … (virgül/boşluk/CSV)`} />}
        <Button size="sm" onClick={() => onApply(mode, mode === 'shift' ? shift : custom)}>Uygula</Button>
      </div>
      <div className="mt-2 flex flex-wrap gap-1">
        {order.map((b, i) => (
          <span key={i} className={cn('inline-flex h-6 items-center gap-0.5 rounded px-1.5 text-[11px] tabular ring-1 ring-inset', b === i + 1 ? 'bg-surface-2 text-ink-3 ring-line' : 'bg-primary-soft text-primary-ink ring-primary/15')}>
            <span>{i + 1}</span><span className="opacity-50">→</span><span className="font-semibold">{b}</span>
          </span>
        ))}
      </div>
    </div>
  )
}

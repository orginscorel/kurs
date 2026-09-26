import { useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  AlarmClock, Camera, CheckCircle2, ChevronDown, Clock, Eye, FilePlus2, FileSignature, FileText, GraduationCap, LogIn, LogOut, MessageCircle, MoreHorizontal, NotebookPen,
  Pencil, Phone, Pin, Printer, Sparkles, ThumbsDown, ThumbsUp, Trash2, TrendingUp, UserX, Wallet,
} from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, duration, money, num, relative, time } from '@/lib/format'
import { AddressText, MailText, PhoneText, formatPhone } from '@/components/ui/contact'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { Panel, Tabs } from '@/components/ui/layout'
import { Alert, Avatar, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { HandCoins, KeyRound, UserPlus } from 'lucide-react'
import { Checkbox, Segmented, Textarea } from '@/components/ui/form'
import { ConfirmDialog, Menu } from '@/components/ui/overlay'
import { axisProps, ChartTooltip, gridProps } from '@/components/charts/ChartKit'
import { riskMeta, statusTone, waLink, type StudentDetailData, type StudentOptions } from './types'
import { StudentFormDrawer } from './StudentFormDrawer'
import { StudentGuidanceTab } from '@/modules/guidance/StudentGuidanceTab'
import { StudentDisciplineTab } from '@/modules/discipline/StudentDisciplineTab'
import { SendMessageDialog } from '@/modules/communication/SendMessageDialog'
import { StudentClassPanel } from '@/modules/placement/StudentClassPanel'
import { ImpersonateButton, StudentPortalAccountPanel } from './StudentPortalAccountPanel'
import { StudentPackagesPanel } from './StudentPackages'
import { NotePrintDialog } from '@/modules/finance/PromissoryNotes'
import { openPdf } from '@/modules/finance/shared'
import { INVOICE_STATUS, type InvoiceRow } from '@/modules/finance/ledger'

type TabKey = 'overview' | 'exams' | 'attendance' | 'finance' | 'homework' | 'notes' | 'messages' | 'timeline' | 'guidance' | 'discipline' | 'observations'

export default function StudentDetail() {
  const { id } = useParams()
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [tab, setTab] = useState<TabKey>('overview')
  const [editOpen, setEditOpen] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [messageOpen, setMessageOpen] = useState(false)
  const photoInput = useRef<HTMLInputElement>(null)

  // Profil her açılışta taze çekilir: veli/paket/sınıf gibi başka ekrandan eklenen kayıtlar bayat önbellekten dolayı "yok" görünmesin.
  const { data, isLoading, error } = useQuery({ queryKey: ['student', id], queryFn: () => api.get<StudentDetailData>(`/students/${id}`), refetchOnMount: 'always', staleTime: 0 })
  const options = useQuery({ queryKey: ['students', 'options'], queryFn: () => api.get<StudentOptions>('/students/options'), staleTime: 5 * 60_000, enabled: can('students.update') })

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['student', id] })
    qc.invalidateQueries({ queryKey: ['students', 'list'] })
  }

  const statusMutation = useMutation({
    mutationFn: (status: string) => api.post<{ message: string }>(`/students/${id}/status`, { status }),
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Durum değiştirilemedi.'),
  })

  const deleteMutation = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`/students/${id}`),
    onSuccess: (r) => { toast.success(r.message); navigate('/ogrenciler') },
    onError: (e) => { toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'); setConfirmDelete(false) },
  })

  const photoMutation = useMutation({
    mutationFn: (file: File) => {
      const fd = new FormData()
      fd.append('photo', file)
      return api.post<{ message: string }>(`/students/${id}/photo`, fd)
    },
    onSuccess: (r) => { toast.success(r.message); invalidate() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Fotoğraf yüklenemedi.'),
  })

  if (error) {
    return <EmptyState title="Öğrenci bulunamadı" description={error instanceof ApiError ? error.message : undefined} action={<Button onClick={() => navigate('/ogrenciler')}>Öğrencilere dön</Button>} />
  }
  if (isLoading || !data) return <DetailSkeleton />

  const s = data.student
  const primary = s.guardians.find((g) => g.is_primary) ?? s.guardians[0]
  const wa = waLink(primary?.whatsapp_phone || primary?.phone)
  const att = data.attendance_30
  const attendanceRate = att.total ? Math.round(((Number(att.present) + Number(att.late)) / att.total) * 100) : null
  const lastExam = data.exams[0]
  const homeworkRate = data.homework.total ? Math.round((Number(data.homework.done) / Number(data.homework.total)) * 100) : null
  const classLine = s.class_groups.map((g) => `${g.name}${g.program ? ` · ${g.program}` : ''}`).join(', ') || 'Sınıf atanmamış'

  return (
    <div className="animate-fade-in mx-auto max-w-[1200px]">
      <nav className="mb-3 text-[12.5px] text-ink-3">
        <Link to="/ogrenciler" className="hover:text-ink">Öğrenciler</Link> <span className="mx-1">/</span> <span className="text-ink-2">{s.full_name}</span>
      </nav>

      {/* Başlık: kimlik + hızlı işlemler + özet sayılar */}
      <section className="rounded-[var(--radius-lg)] bg-surface ring-1 ring-line">
        <div className="flex flex-col gap-4 p-4 sm:p-5 md:flex-row md:items-center">
          <div className="flex min-w-0 flex-1 items-center gap-4">
            <div className="group relative shrink-0">
              <Avatar name={s.full_name} src={s.photo_url} size={64} />
              {can('students.update') && (
                <>
                  <button onClick={() => photoInput.current?.click()} className="absolute inset-0 grid place-items-center rounded-full bg-black/45 text-white opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100" aria-label="Fotoğraf değiştir">
                    <Camera className="size-4" />
                  </button>
                  <input ref={photoInput} type="file" accept="image/*" className="hidden" onChange={(e) => e.target.files?.[0] && photoMutation.mutate(e.target.files[0])} />
                </>
              )}
              {data.today?.is_inside ? <span className="absolute bottom-0.5 right-0.5 size-3.5 rounded-full bg-success ring-2 ring-surface" title="Şu an kurumda" /> : null}
            </div>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="truncate text-[20px] font-semibold tracking-[-0.02em]">{s.full_name}</h1>
                {can('students.update') ? (
                  <Menu
                    align="start"
                    trigger={<button className="inline-flex"><Badge tone={statusTone[s.status] ?? 'neutral'} dot>{s.status_label}<ChevronDown className="size-3 -mr-0.5" /></Badge></button>}
                    items={Object.entries(options.data?.statuses ?? {}).filter(([k]) => ['active', 'frozen', 'withdrawn', 'graduated'].includes(k)).map(([value, label]) => ({ label, onClick: () => statusMutation.mutate(value), disabled: value === s.status }))}
                  />
                ) : (
                  <Badge tone={statusTone[s.status] ?? 'neutral'} dot>{s.status_label}</Badge>
                )}
                {data.risk.level !== 'low' && <Badge tone={riskMeta[data.risk.level].tone}>Risk {riskMeta[data.risk.level].label.toLocaleLowerCase('tr-TR')}</Badge>}
              </div>
              <p className="mt-0.5 truncate text-[13px] text-ink-2">{classLine}</p>
              <p className="text-[12.5px] text-ink-3 tabular">
                Öğrenci no {s.student_no}
                {s.school_grade && ` · ${/^\d+$/.test(s.school_grade) ? `${s.school_grade}. sınıf` : s.school_grade}${s.field ? ` · Alan: ${s.field}` : ''}`}
                {primary && ` · Veli: ${primary.name}`}
              </p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2 md:justify-end">
            {wa && (
              <a href={wa} target="_blank" rel="noopener" className="inline-flex h-9 items-center gap-2 rounded-[var(--radius-sm)] border border-line bg-surface px-3 text-[13.5px] font-medium text-ink shadow-[var(--shadow-soft)] transition-colors hover:border-line-strong hover:bg-surface-2">
                <MessageCircle className="size-4 text-ink-2" /> WhatsApp
              </a>
            )}
            {primary?.phone && !primary.phone.includes('•') && (
              <a href={`tel:${primary.phone}`}><Button icon={<Phone className="size-4" />}>Veliyi ara</Button></a>
            )}
            {can('payments.create') && <ButtonLink to={`/finans/tahsilat?ogrenci=${s.id}`} variant="primary" icon={<HandCoins className="size-4" />}>Tahsilat al</ButtonLink>}
            {can('students.impersonate') && <ImpersonateButton studentId={s.id} />}
            {can('students.update') && <Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button>}
            <Menu
              trigger={<Button variant="ghost" size="icon" aria-label="Diğer işlemler"><MoreHorizontal className="size-4" /></Button>}
              items={[
                { label: 'Mesaj gönder (WhatsApp/SMS)', icon: <MessageCircle />, onClick: () => setMessageOpen(true), hidden: !can('messages.send') },
                { label: 'Not ekle', icon: <NotebookPen />, onClick: () => setTab('notes') },
                { label: 'Yoklama geçmişi', icon: <Clock />, onClick: () => setTab('attendance') },
                { label: 'Ödemeler', icon: <Wallet />, onClick: () => setTab('finance'), hidden: !can('finance.view') },
                { label: 'Yeni kayıt (dönem/program)', icon: <UserPlus />, onClick: () => navigate(`/finans/kayitlar/yeni?ogrenci=${s.id}`), hidden: !can('enrollments.create') },
                'divider',
                { label: 'Öğrenciyi sil', icon: <Trash2 />, danger: true, onClick: () => setConfirmDelete(true), hidden: !can('students.delete') },
              ]}
            />
          </div>
        </div>

        <div className="grid grid-cols-2 border-t border-line sm:grid-cols-3 lg:grid-cols-5 [&>*]:border-line [&>*:not(:last-child)]:border-r max-sm:[&>*:nth-child(2n)]:border-r-0 max-sm:[&>*]:border-b">
          <Metric label="Bugün kuruma giriş" value={data.today?.first_entry_at ? `Giriş ${time(data.today.first_entry_at)}` : 'Giriş yok'} sub={data.today?.first_entry_at ? (data.today.is_inside ? `Kurumda · ${duration(data.today.minutes_inside)}` : `Çıkış ${time(data.today.last_exit_at)}`) : undefined} />
          <Metric label="Devam oranı (30 gün)" value={attendanceRate === null ? '—' : `%${attendanceRate}`} sub={`Gelmedi: ${num(att.absent)} · Geç: ${num(att.late)}`} tone={att.absent >= 5 ? 'text-danger' : undefined} />
          <Metric label="Son deneme neti" value={lastExam ? `${num(lastExam.net, 2)} net` : '—'} sub={lastExam ? `Kurum sırası ${lastExam.institution_rank ?? '—'} / ${lastExam.participant_count}` : undefined} />
          {data.finance ? (
            <Metric label="Kalan ödeme" value={money(data.finance.remaining, { short: true })} sub={Number(data.finance.overdue) > 0 ? `${money(data.finance.overdue, { short: true })} gecikmiş` : 'Gecikme yok'} tone={Number(data.finance.overdue) > 0 ? 'text-danger' : undefined} onClick={() => setTab('finance')} />
          ) : (
            <Metric label="Ödev teslim oranı" value={homeworkRate === null ? '—' : `%${homeworkRate}`} sub={`Teslim bekleyen: ${num(data.homework.open)}`} />
          )}
          <Metric label="Risk puanı" value={String(data.risk.score)} sub={`${riskMeta[data.risk.level].label} risk (0–100)`} tone={data.risk.level === 'high' ? 'text-danger' : data.risk.level === 'medium' ? 'text-warning' : 'text-success'} />
        </div>
      </section>

      <div className="sticky top-14 z-10 -mx-4 mt-4 bg-bg/90 px-4 backdrop-blur-md sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { value: 'overview', label: 'Genel' },
            { value: 'exams', label: 'Sınavlar', count: data.exams.length || null },
            { value: 'attendance', label: 'Yoklama' },
            { value: 'finance', label: 'Ödemeler', hidden: !can('finance.view') },
            { value: 'homework', label: 'Ödevler', count: data.homework.open || null },
            { value: 'guidance', label: 'Rehberlik', count: data.counts.guidance || null, hidden: !can('guidance.view') },
            { value: 'discipline', label: 'Disiplin', hidden: !can('discipline.view') },
            { value: 'observations', label: 'Gözlemler', count: data.counts.observations || null },
            { value: 'notes', label: 'Notlar', count: data.counts.notes || null },
            { value: 'messages', label: 'Mesajlar', count: data.counts.messages || null, hidden: !can('messages.view') },
            { value: 'timeline', label: 'Geçmiş' },
          ]}
        />
      </div>

      <div className="mt-4">
        {tab === 'overview' && <Overview data={data} studentId={s.id} onEdit={() => setEditOpen(true)} />}
        {tab === 'exams' && <ExamsTab data={data} />}
        {tab === 'attendance' && <AttendanceTab studentId={s.id} />}
        {tab === 'finance' && <FinanceTab studentId={s.id} studentName={s.full_name} />}
        {tab === 'homework' && <HomeworkTab studentId={s.id} />}
        {tab === 'guidance' && <StudentGuidanceTab studentId={s.id} studentName={s.full_name} />}
        {tab === 'discipline' && <StudentDisciplineTab studentId={s.id} studentName={s.full_name} studentNo={s.student_no} />}
        {tab === 'observations' && <ObservationsTab studentId={s.id} />}
        {tab === 'notes' && <NotesTab studentId={s.id} />}
        {tab === 'messages' && <MessagesTab studentId={s.id} />}
        {tab === 'timeline' && <TimelineTab studentId={s.id} />}
      </div>

      <StudentFormDrawer open={editOpen} onClose={() => setEditOpen(false)} options={options.data} student={s} onSaved={invalidate} />
      {can('messages.send') && <SendMessageDialog open={messageOpen} onClose={() => { setMessageOpen(false); qc.invalidateQueries({ queryKey: ['student', id, 'messages'] }) }} studentIds={[s.id]} studentsLabel={s.full_name} />}
      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => deleteMutation.mutate()}
        loading={deleteMutation.isPending}
        danger
        title="Öğrenci silinsin mi?"
        description="Kayıt arşivlenir; tahsilatı olan öğrenciler silinemez, durumu 'Ayrıldı' yapılmalıdır."
        confirmLabel="Sil"
      />
    </div>
  )
}

function Metric({ label, value, sub, tone, onClick }: { label: string; value: string; sub?: string; tone?: string; onClick?: () => void }) {
  const Comp = onClick ? 'button' : 'div'
  return (
    <Comp onClick={onClick} className={cn('min-w-0 px-4 py-3 text-left sm:px-5', onClick && 'hover:bg-surface-2/60 transition-colors')}>
      <p className="truncate text-[12px] text-ink-3">{label}</p>
      <p className={cn('mt-0.5 truncate text-[16px] font-semibold tracking-tight tabular', tone)}>{value}</p>
      {sub && <p className="truncate text-[12px] text-ink-3">{sub}</p>}
    </Comp>
  )
}

function InfoRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-3 py-2 text-[13px] border-b border-line/70 last:border-0">
      <span className="shrink-0 text-ink-3">{label}</span>
      <span className="min-w-0 break-words text-right text-ink">{value}</span>
    </div>
  )
}

function Overview({ data, studentId, onEdit }: { data: StudentDetailData; studentId: number; onEdit?: () => void }) {
  const can = useCan()
  const qc = useQueryClient()
  const s = data.student
  const [nationalId, setNationalId] = useState<string | null>(null)
  const types = [...new Set(data.exams.map((e) => e.type))]
  const [type, setType] = useState(types[0] ?? 'TYT')
  const trend = data.exams.filter((e) => e.type === type).slice().reverse().map((e) => ({ label: date(e.exam_date).slice(0, 5), name: e.name, net: Number(e.net) }))
  const goal = s.goals[0]
  const targetNet = goal ? Number(type === 'TYT' ? goal.target_tyt_net : goal.target_ayt_net) || null : null

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 items-start">
      <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
        {data.risk.insights.length > 0 && (
          <Panel title={<span className="inline-flex items-center gap-1.5"><Sparkles className="size-4 text-primary" /> Akıllı analiz</span>} description="Veriye dayalı sistem önerisi · karar öğretmen ve rehberliğe aittir">
            <ul className="flex flex-col gap-2">
              {data.risk.insights.map((i, idx) => (
                <li key={idx} className="flex items-start gap-2.5 text-[13.5px]">
                  <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', i.tone === 'danger' ? 'bg-danger' : i.tone === 'warning' ? 'bg-warning' : i.tone === 'success' ? 'bg-success' : 'bg-info')} />
                  <span>{i.text}</span>
                </li>
              ))}
            </ul>
          </Panel>
        )}

        <Panel title="Net gelişimi" actions={types.length > 1 ? <Segmented size="sm" value={type} onChange={setType} options={types.map((t) => ({ value: t, label: t.replace('_', ' ') }))} /> : undefined}>
          {trend.length < 2 ? (
            <EmptyState compact icon={<TrendingUp />} title="Gelişim grafiği için en az iki sınav gerekli" />
          ) : (
            <>
              <div className="mb-2 flex flex-wrap gap-x-6 gap-y-1 text-[12.5px]">
                <span className="text-ink-3">Son: <b className="text-ink tabular">{num(trend.at(-1)!.net, 2)}</b></span>
                <span className="text-ink-3">Değişim: <b className={cn('tabular', trend.at(-1)!.net - trend[0]!.net >= 0 ? 'text-success' : 'text-danger')}>{trend.at(-1)!.net - trend[0]!.net >= 0 ? '+' : ''}{num(trend.at(-1)!.net - trend[0]!.net, 2)}</b></span>
                {targetNet && <span className="text-ink-3">Hedef: <b className="text-ink tabular">{num(targetNet)}</b></span>}
              </div>
              <div className="h-[220px]">
                <ResponsiveContainer>
                  <LineChart data={trend} margin={{ top: 10, right: 12, left: 0, bottom: 0 }}>
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="label" {...axisProps} dy={6} />
                    <YAxis {...axisProps} width={40} domain={[(min: number) => Math.floor((Math.min(min, targetNet ?? min) - 5) / 10) * 10, (max: number) => Math.ceil((Math.max(max, targetNet ?? max) + 5) / 10) * 10]} allowDecimals={false} />
                    <Tooltip cursor={{ stroke: 'var(--line-strong)' }} content={<ChartTooltip formatLabel={(l) => trend.find((t) => t.label === l)?.name ?? l} formatValue={(v) => `${num(v, 2)} net`} />} />
                    <Line type="linear" dataKey="net" name="Net" stroke="var(--series-1)" strokeWidth={2} dot={{ r: 3.5, fill: 'var(--surface)', stroke: 'var(--series-1)', strokeWidth: 2 }} activeDot={{ r: 5 }} />
                    {targetNet && <Line type="linear" dataKey={() => targetNet} name="Hedef" stroke="var(--ink-3)" strokeDasharray="4 4" strokeWidth={1.5} dot={false} />}
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </>
          )}
        </Panel>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <TopicPanel title="Güçlü konular" rows={data.topics.strong} tone="success" />
          <TopicPanel title="Gelişmesi gereken konular" rows={data.topics.weak} tone="danger" />
        </div>

        <Panel title="Son sınavlar" flush>
          {data.exams.length === 0 ? (
            <EmptyState compact icon={<GraduationCap />} title="Henüz sınav sonucu yok" />
          ) : (
            <ul>
              {data.exams.slice(0, 5).map((e) => (
                <li key={e.id} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-[13px]">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{e.name}</p>
                    <p className="truncate text-[12px] text-ink-3">{date(e.exam_date)} · kurum sırası {e.institution_rank ?? '—'}/{e.participant_count}</p>
                  </div>
                  <span className="shrink-0 font-semibold tabular">{num(e.net, 2)}</span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <div className="flex min-w-0 flex-col gap-4">
        <StudentClassPanel studentId={studentId} onEditStudent={can('students.update') ? onEdit : undefined} />
        <StudentPackagesPanel studentId={studentId} enrollments={data.enrollments} onChanged={() => qc.invalidateQueries({ queryKey: ['student', String(studentId)] })} />
        {data.finance && (
          <Panel title="Ödeme durumu">
            <ProgressBar value={Number(data.finance.total) ? (Number(data.finance.paid) / Number(data.finance.total)) * 100 : 0} tone="success" />
            <p className="mt-2 text-[12.5px] text-ink-3 tabular">{money(data.finance.paid, { short: true })} / {money(data.finance.total, { short: true })} ödendi</p>
            {Number(data.finance.overdue) > 0 && <Alert tone="danger" className="mt-3">{data.finance.overdue_count} taksit gecikmiş · {money(data.finance.overdue)}</Alert>}
            {data.finance.next_installment && (
              <div className="mt-3 flex items-center justify-between gap-2 rounded-[var(--radius-sm)] bg-surface-2 px-3 py-2 text-[12.5px]">
                <span className="text-ink-2">Sıradaki taksit: {date(data.finance.next_installment.due_date)}</span>
                <span className="whitespace-nowrap font-medium tabular">{money(Number(data.finance.next_installment.amount) - Number(data.finance.next_installment.paid_amount))}</span>
              </div>
            )}
          </Panel>
        )}
        <StudentPortalAccountPanel
          studentId={studentId}
          studentName={s.full_name}
          guardianName={(s.guardians.find((g) => g.is_primary) ?? s.guardians[0])?.name}
          guardianPhone={(() => { const g = s.guardians.find((x) => x.is_primary) ?? s.guardians[0]; return g?.whatsapp_phone || g?.phone })()}
        />
        <Panel title="Bilgiler" actions={can('students.update') && onEdit && <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={onEdit}>Düzenle</Button>}>
          <div>
            <InfoRow label="Okul" value={s.school_name ?? '—'} />
            <InfoRow label="Hedef bölüm / üniversite" value={s.target_department || s.target_university ? `${s.target_department ?? ''}${s.target_university ? ` · ${s.target_university}` : ''}` : '—'} />
            <InfoRow label="Rehber öğretmen" value={s.guidance_teacher?.name ?? '—'} />
            <InfoRow label="Doğum tarihi" value={date(s.birth_date)} />
            <InfoRow
              label="TC kimlik no"
              value={
                <span className="inline-flex items-center gap-1.5 tabular">
                  {nationalId ?? s.national_id_masked ?? '—'}
                  {s.national_id_masked && !nationalId && can('students.view_sensitive') && (
                    <button className="text-ink-3 hover:text-ink" title="Göster (denetim kaydına yazılır)" onClick={() => api.get<{ national_id: string }>(`/students/${studentId}/national-id`).then((r) => setNationalId(r.national_id))}>
                      <Eye className="size-3.5" />
                    </button>
                  )}
                </span>
              }
            />
            <InfoRow label="Öğrenci telefonu" value={<PhoneText value={s.phone} whatsapp />} />
            {s.email && <InfoRow label="E-posta" value={<MailText value={s.email} />} />}
            {s.address && <InfoRow label="Adres" value={<AddressText value={s.address} />} />}
            <InfoRow label="Kayıt tarihi" value={date(s.registered_on)} />
          </div>
          {s.tags.filter((t) => t.name !== 'Demo').length > 0 && (
            <div className="mt-3 flex flex-wrap gap-1.5">
              {s.tags.filter((t) => t.name !== 'Demo').map((t) => <Badge key={t.id}>{t.name}</Badge>)}
            </div>
          )}
        </Panel>

        <Panel title="Veliler" flush>
          {s.guardians.length === 0 ? (
            <p className="px-4 pb-4 text-[13px] text-ink-3">Veli kaydı yok.</p>
          ) : (
            <ul>
              {s.guardians.map((g) => (
                <li key={g.id} className="flex items-center gap-3 border-t border-line px-4 py-2.5">
                  <Avatar name={g.name} size={32} />
                  <div className="min-w-0 flex-1">
                    <Link to={`/veliler/${g.id}`} className="flex items-center gap-1.5 text-[13.5px] font-medium hover:text-primary"><span className="truncate">{g.name}</span>{g.is_primary && <Badge tone="primary" className="h-[20px]">Birincil</Badge>}</Link>
                    <p className="truncate text-[12.5px]"><PhoneText value={g.phone} muted /></p>
                  </div>
                  <Link to={`/veliler/${g.id}?portal=1`} className="grid size-8 place-items-center rounded-[var(--radius-sm)] text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Veli portal girişi" title="Veli portal girişi (kullanıcı adı, şifre, önizleme)"><KeyRound className="size-4" /></Link>
                  {g.phone && !g.phone.includes('•') && (
                    <a href={`tel:${g.phone}`} className="grid size-8 place-items-center rounded-[var(--radius-sm)] text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Ara"><Phone className="size-4" /></a>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="Yaklaşan dersler" flush>
          {data.upcoming_lessons.length === 0 ? (
            <p className="px-4 pb-4 text-[13px] text-ink-3">Planlanmış ders yok.</p>
          ) : (
            <ul>
              {data.upcoming_lessons.slice(0, 5).map((l) => (
                <li key={l.id} className="flex items-center gap-3 border-t border-line px-4 py-2.5 text-[13px]">
                  <div className="w-12 shrink-0">
                    <p className="font-medium tabular">{time(l.starts_at)}</p>
                    <p className="text-[12px] text-ink-3">{date(l.starts_at).slice(0, 5)}</p>
                  </div>
                  <div className="min-w-0">
                    <p className="truncate font-medium">{l.subject}</p>
                    <p className="truncate text-[12px] text-ink-3">Derslik: {l.classroom ?? '—'} · Öğretmen: {l.teacher ?? '—'}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="Risk faktörleri" description={`Son hesaplama: ${relative(data.risk.calculated_at)}`}>
          <ul className="flex flex-col gap-2.5">
            {data.risk.factors.map((f) => (
              <li key={f.key} className="text-[12.5px]">
                <div className="flex justify-between gap-2"><span className="text-ink-2">{f.label}</span><span className="tabular">{f.value}</span></div>
                <ProgressBar value={(f.points / f.weight) * 100} tone={f.points / f.weight > 0.6 ? 'danger' : f.points / f.weight > 0.3 ? 'warning' : 'neutral'} className="mt-1 h-1" />
              </li>
            ))}
          </ul>
        </Panel>

      </div>
    </div>
  )
}

function TopicPanel({ title, rows, tone }: { title: string; rows: StudentDetailData['topics']['strong']; tone: 'success' | 'danger' }) {
  return (
    <Panel title={title} description="Sınavlardaki soru başarısına göre">
      {rows.length === 0 ? (
        <p className="text-[13px] text-ink-3">Yeterli veri yok.</p>
      ) : (
        <ul className="flex flex-col gap-2.5">
          {rows.map((t) => (
            <li key={t.id}>
              <div className="mb-1 flex justify-between gap-2 text-[12.5px]">
                <span className="truncate"><span className="text-ink-3">{t.subject} ·</span> {t.topic}</span>
                <span className="font-medium tabular">%{t.rate}</span>
              </div>
              <ProgressBar value={t.rate} tone={tone === 'success' ? 'success' : t.rate < 40 ? 'danger' : 'warning'} />
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

function ExamsTab({ data }: { data: StudentDetailData }) {
  if (!data.exams.length) return <EmptyState icon={<GraduationCap />} title="Henüz sınav sonucu yok" />
  return (
    <Panel flush>
      <div className="overflow-x-auto scroll-thin">
        <table className="tbl w-full min-w-[640px] text-[13px]">
          <thead>
            <tr className="border-b border-line bg-surface-2/60 text-[12px] text-ink-3">
              <th className="px-4 h-9 text-left font-medium">Sınav</th>
              <th className="px-3 text-center font-medium">Ders netleri</th>
              <th className="px-3 text-center font-medium" title="Doğru / Yanlış / Boş">Doğru / Yanlış / Boş</th>
              <th className="px-3 text-center font-medium">Net</th>
              <th className="px-4 text-center font-medium">Kurum sırası</th>
            </tr>
          </thead>
          <tbody>
            {data.exams.map((e) => (
              <tr key={e.id} className="border-b border-line last:border-0">
                <td className="px-4 py-2.5">
                  <p className="font-medium">{e.name}</p>
                  <p className="text-[12px] text-ink-3">{date(e.exam_date)} · {e.type.replace('_', ' ')}{e.score ? ` · ${num(e.score, 1)} puan` : ''}</p>
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex flex-wrap justify-center gap-x-3 gap-y-0.5 text-[12px]">
                    {e.sections.map((sec) => (
                      <span key={sec.code} className="whitespace-nowrap"><span className="text-ink-3">{sec.code}</span> <span className="tabular">{num(sec.net, 2)}</span></span>
                    ))}
                  </div>
                </td>
                <td className="px-3 py-2.5 text-center tabular text-ink-2 whitespace-nowrap">{e.correct}/{e.wrong}/{e.blank}</td>
                <td className="px-3 py-2.5 text-center font-semibold tabular">{num(e.net, 2)}</td>
                <td className="px-4 py-2.5 text-center tabular whitespace-nowrap">
                  <p>{e.institution_rank ?? '—'}<span className="text-ink-3">/{e.participant_count}</span></p>
                  {e.national_rank && <p className="text-[12px] text-ink-3">Türkiye: {num(e.national_rank)}</p>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  )
}

const attTone: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = { present: 'success', late: 'warning', absent: 'danger', excused: 'info', medical: 'info' }
const attLabel: Record<string, string> = { present: 'Var', late: 'Geç', absent: 'Yok', excused: 'İzinli', medical: 'Raporlu' }
const methodLabel: Record<string, string> = { auto: 'Biyometrik (otomatik)', teacher: 'Öğretmen', admin: 'Yönetici', qr: 'QR', rfid: 'Kart', biometric: 'Biyometrik' }

function AttendanceTab({ studentId }: { studentId: number }) {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({
    queryKey: ['student', studentId, 'attendance', status, page],
    queryFn: () => api.get<any>(`/students/${studentId}/attendance`, { status, page }),
  })

  if (isLoading) return <Skeleton className="h-64" />
  const summary = data.meta.summary

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 items-start">
      <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
        <div className="grid grid-cols-3 sm:grid-cols-5 gap-2">
          {(['present', 'late', 'absent', 'excused', 'medical'] as const).map((k) => (
            <button key={k} onClick={() => { setStatus(status === k ? '' : k); setPage(1) }} className={cn('rounded-[var(--radius-md)] bg-surface ring-1 px-3 py-2 text-left transition-colors', status === k ? 'ring-primary' : 'ring-line hover:ring-line-strong')}>
              <p className="text-[12px] text-ink-3">{attLabel[k]}</p>
              <p className="text-[17px] font-semibold tabular">{num(summary?.[k])}</p>
            </button>
          ))}
        </div>

        <Panel title="Ders yoklamaları" flush>
          {data.data.length === 0 ? (
            <EmptyState compact icon={<CheckCircle2 />} title="Kayıt yok" />
          ) : (
            <ul>
              {data.data.map((a: any) => {
                const missed = ['absent', 'excused', 'medical'].includes(a.status)
                return (
                  <li key={a.id} className="flex flex-col gap-1.5 border-t border-line px-4 py-2.5 text-[13px]">
                    <div className="flex items-center gap-3">
                      <div className="w-20 shrink-0">
                        <p className="tabular">{date(a.starts_at).slice(0, 5)}</p>
                        <p className="text-[12px] text-ink-3 tabular">{time(a.starts_at)}</p>
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{a.subject}</p>
                        <p className="truncate text-[12px] text-ink-3">Öğretmen: {a.teacher ?? '—'} · Yöntem: {methodLabel[a.method] ?? a.method}</p>
                      </div>
                      <Badge tone={attTone[a.status] ?? 'neutral'}>{attLabel[a.status] ?? a.status}{a.late_minutes ? ` · ${a.late_minutes} dk` : ''}</Badge>
                    </div>
                    {a.topics && a.topics.length > 0 && (
                      <div className="ml-[92px] flex flex-wrap items-center gap-1">
                        <span className={cn('text-[11.5px] font-medium', missed ? 'text-warning' : 'text-ink-3')}>{missed ? 'Kaçırdığı konular:' : 'İşlenen:'}</span>
                        {a.topics.map((t: any) => (
                          <span key={t.id} className="rounded bg-surface-2 px-1.5 py-0.5 text-[11.5px] text-ink-2 ring-1 ring-line">{t.outcome_code ? `${t.outcome_code} · ` : ''}{t.name}</span>
                        ))}
                      </div>
                    )}
                  </li>
                )
              })}
            </ul>
          )}
          {data.meta.last_page > 1 && (
            <div className="flex items-center justify-end gap-2 border-t border-line px-4 py-2">
              <span className="mr-auto text-[12px] text-ink-3 tabular">{page}/{data.meta.last_page}</span>
              <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>Önceki</Button>
              <Button size="sm" variant="ghost" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Sonraki</Button>
            </div>
          )}
        </Panel>
      </div>

      <Panel title="Günlük giriş–çıkış" description="Son 31 gün" flush>
        <ul className="max-h-[560px] overflow-y-auto scroll-thin">
          {data.meta.presences.map((p: any) => (
            <li key={p.date} className="flex items-center justify-between gap-2 border-t border-line px-4 py-2 text-[12.5px]">
              <span className="text-ink-2">{date(p.date).slice(0, 5)}</span>
              <span className="tabular" title="Giriş – çıkış">{time(p.first_entry_at)} – {p.is_inside ? 'kurumda' : time(p.last_exit_at)}</span>
              <span className="w-20 text-right text-ink-3 tabular" title="Kurumda kalma süresi">{duration(p.minutes_inside)}</span>
            </li>
          ))}
          {data.meta.presences.length === 0 && <li className="px-4 py-6 text-center text-[13px] text-ink-3">Giriş kaydı yok.</li>}
        </ul>
      </Panel>
    </div>
  )
}

const instTone: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = { paid: 'success', partial: 'info', pending: 'warning', overdue: 'danger', cancelled: 'neutral' }
const instLabel: Record<string, string> = { paid: 'Ödendi', partial: 'Kısmen ödendi', pending: 'Vadesi gelmedi', overdue: 'Gecikti', cancelled: 'İptal' }

function FinanceTab({ studentId, studentName }: { studentId: number; studentName: string }) {
  const can = useCan()
  const [notesOpen, setNotesOpen] = useState(false)
  const { data, isLoading } = useQuery({ queryKey: ['student', studentId, 'payments'], queryFn: () => api.get<any>(`/students/${studentId}/payments`) })
  const invoices = useQuery({
    queryKey: ['student', studentId, 'invoices'],
    queryFn: () => api.get<{ data: InvoiceRow[]; meta: { total: number } }>('/finance/invoices', { student_id: studentId, per_page: 8, sort: '-issue_date' }),
  })
  const canNotes = can('installments.manage') || can('enrollments.create') || can('finance.invoice')
  const openInstallments = (data?.installments ?? []).filter((i: any) => ['pending', 'partial', 'overdue'].includes(i.status)).length
  const pdf = (path: string) => openPdf(path).catch((e: Error) => toast.error(e.message))
  if (isLoading) return <Skeleton className="h-64" />

  return (
    <div className="flex flex-col gap-4">
    {/* Belgeler ve kısayollar: ekstre, senet, fatura */}
    <div className="flex flex-wrap items-center gap-2 rounded-[var(--radius-lg)] bg-surface px-3 py-2.5 ring-1 ring-line">
      <span className="mr-1 text-[12.5px] font-medium text-ink-2">Belgeler</span>
      <Button size="sm" icon={<FileText className="size-4" />} onClick={() => pdf(`/finance/statements/students/${studentId}/pdf`)}>Hesap ekstresi (PDF)</Button>
      {canNotes && (
        <Button size="sm" icon={<FileSignature className="size-4" />} disabled={openInstallments === 0} title={openInstallments === 0 ? 'Açık taksit yok' : undefined} onClick={() => setNotesOpen(true)}>
          Senetleri yazdır{openInstallments > 0 ? ` (${openInstallments})` : ''}
        </Button>
      )}
      <ButtonLink size="sm" variant="ghost" to={`/finans/faturalar?q=${encodeURIComponent(studentName)}`} icon={<FileText className="size-4" />}>Tüm faturalar</ButtonLink>
      {can('finance.invoice') && <ButtonLink size="sm" variant="info" className="sm:ml-auto" to={`/finans/faturalar/yeni?ogrenci=${studentId}`} icon={<FilePlus2 className="size-4" />}>Yeni fatura taslağı</ButtonLink>}
    </div>
    {canNotes && <NotePrintDialog open={notesOpen} onClose={() => setNotesOpen(false)} selector={{ student_id: studentId }} title={`Senet yazdır · ${studentName}`} />}

    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 items-start">
      <Panel title="Taksitler" flush>
        {data.installments.length === 0 ? (
          <EmptyState compact icon={<Wallet />} title="Ödeme planı yok" />
        ) : (
          <ul>
            {data.installments.map((i: any) => (
              <li key={i.id} className={cn('flex items-center gap-3 border-t border-line px-4 py-2.5 text-[13px]', i.status === 'overdue' && 'bg-danger-soft/40')}>
                <div className="min-w-0 flex-1">
                  <p className="tabular"><span className="text-ink-3">{i.sequence}. taksit · vade</span> {date(i.due_date)}</p>
                  <p className="text-[12px] text-ink-3 tabular">Ödenen {money(i.paid_amount, { short: true })} / tutar {money(i.amount, { short: true })}</p>
                </div>
                <div className="text-right">
                  <p className="text-[11.5px] text-ink-3">Kalan</p>
                  <p className="font-medium tabular whitespace-nowrap">{money(i.remaining, { short: true })}</p>
                </div>
                <Badge tone={instTone[i.status]}>{instLabel[i.status]}</Badge>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <Panel title="Tahsilatlar" flush>
        {data.payments.length === 0 ? (
          <EmptyState compact icon={<Wallet />} title="Henüz tahsilat yok" />
        ) : (
          <ul>
            {data.payments.map((p: any) => (
              <li key={p.id} className={cn('flex items-center gap-3 border-t border-line px-4 py-2.5 text-[13px]', p.voided_at && 'opacity-60')}>
                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium tabular"><span className="font-normal text-ink-3">Makbuz</span> {p.receipt_no}</p>
                  <p className="truncate text-[12px] text-ink-3">{dateTime(p.paid_at)} · Kasa / banka: {p.account ?? '—'}</p>
                </div>
                <span className="font-medium tabular whitespace-nowrap">{p.voided_at ? <s>{money(p.amount, { short: true })}</s> : money(p.amount, { short: true })}</span>
                {p.voided_at && <Badge tone="danger">İptal</Badge>}
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>

    <Panel
      title="Faturalar"
      description={invoices.data ? `${num(invoices.data.meta.total)} fatura` : undefined}
      flush
      actions={invoices.data && invoices.data.meta.total > invoices.data.data.length ? <ButtonLink size="xs" variant="ghost" to={`/finans/faturalar?q=${encodeURIComponent(studentName)}`}>Tümü</ButtonLink> : undefined}
    >
      {invoices.isLoading ? (
        <Skeleton className="m-4 h-20" />
      ) : !invoices.data?.data.length ? (
        <EmptyState compact icon={<FileText />} title="Bu öğrenci için fatura yok" description={can('finance.invoice') ? 'Yeni fatura taslağıyla başlayabilirsiniz.' : undefined} />
      ) : (
        <ul>
          {invoices.data.data.map((inv) => (
            <li key={inv.id} className={cn('flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-line px-4 py-2.5 text-[13px]', inv.status === 'cancelled' && 'opacity-60')}>
              <Link to={`/finans/faturalar/${inv.id}`} className="min-w-0 flex-1 basis-48 hover:underline">
                <p className="truncate font-medium tabular">{inv.invoice_no ?? inv.label}</p>
                <p className="truncate text-[12px] text-ink-3">{date(inv.issue_date)} · Alıcı: {inv.buyer_name}{inv.kind === 'return' ? ' · İade faturası' : ''}</p>
              </Link>
              <span className="font-medium tabular whitespace-nowrap">{money(inv.payable_total, { short: true })}</span>
              <Badge tone={INVOICE_STATUS[inv.status]?.tone ?? 'neutral'}>{INVOICE_STATUS[inv.status]?.label ?? inv.status}</Badge>
              <Button size="icon-sm" variant="ghost" aria-label="Yazdır" onClick={() => pdf(`/finance/invoices/${inv.id}/pdf`)}><Printer className="size-4" /></Button>
            </li>
          ))}
        </ul>
      )}
    </Panel>
    </div>
  )
}

const hwTone: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = { submitted: 'success', late: 'warning', missed: 'danger', seen: 'info', assigned: 'neutral' }
const hwLabel: Record<string, string> = { submitted: 'Teslim edildi', late: 'Geç teslim', missed: 'Yapılmadı', seen: 'Görüldü', assigned: 'Atandı' }

function HomeworkTab({ studentId }: { studentId: number }) {
  const { data, isLoading } = useQuery({ queryKey: ['student', studentId, 'homework'], queryFn: () => api.get<{ data: any[] }>(`/students/${studentId}/homework`) })
  if (isLoading) return <Skeleton className="h-48" />
  if (!data?.data.length) return <EmptyState icon={<NotebookPen />} title="Ödev kaydı yok" />
  return (
    <Panel flush>
      <ul>
        {data.data.map((h) => (
          <li key={h.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line last:border-0 px-4 py-3">
            <div className="min-w-0 flex-1 basis-60">
              <p className="truncate text-[13.5px] font-medium">{h.title}</p>
              <p className="truncate text-[12px] text-ink-3">{h.subject} · Öğretmen: {h.teacher} · Son teslim: {dateTime(h.due_at)}</p>
            </div>
            {h.score !== null && <span className="text-[13px] tabular text-ink-2">{h.score} puan</span>}
            <Badge tone={hwTone[h.status]}>{hwLabel[h.status]}</Badge>
          </li>
        ))}
      </ul>
    </Panel>
  )
}

const obsTone: Record<string, 'success' | 'warning' | 'neutral'> = { positive: 'success', improve: 'warning', note: 'neutral' }

type ObservationRow = {
  id: number
  kind: string
  kind_label: string
  category_label: string
  points: number
  body: string
  teacher: { id: number; name: string } | null
  subject: string | null
  class_group: string | null
  visible_to_guardian: boolean
  visible_to_student: boolean
  created_at: string
}
type ObservationSummary = { total: number; points: number; positive: number; improve: number; note: number; shared: number }

/** Öğretmenlerin öğretmen portalından girdiği gözlem notları (salt okunur). */
function ObservationsTab({ studentId }: { studentId: number }) {
  const [kind, setKind] = useState<'all' | 'positive' | 'improve' | 'note'>('all')
  const { data, isLoading } = useQuery({
    queryKey: ['student', studentId, 'observations', kind],
    queryFn: () => api.get<{ data: ObservationRow[]; summary: ObservationSummary }>(`/students/${studentId}/observations`, kind === 'all' ? {} : { kind }),
  })
  if (isLoading || !data) return <Skeleton className="h-48" />
  const sm = data.summary

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-3">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <MiniStat label="Toplam gözlem" value={num(sm.total)} />
        <MiniStat label="Davranış puanı" value={`${sm.points > 0 ? '+' : ''}${num(sm.points)}`} tone={sm.points > 0 ? 'text-success' : sm.points < 0 ? 'text-danger' : undefined} />
        <MiniStat label="Olumlu / gelişmeli" value={`${num(sm.positive)} / ${num(sm.improve)}`} />
        <MiniStat label="Veli/öğrenciye açık" value={num(sm.shared)} />
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Segmented size="sm" value={kind} onChange={setKind} options={[{ value: 'all', label: 'Tümü' }, { value: 'positive', label: 'Olumlu' }, { value: 'improve', label: 'Gelişmeli' }, { value: 'note', label: 'Not' }]} />
        <p className="text-[12px] text-ink-3">Gözlemleri öğretmenler öğretmen portalından girer.</p>
      </div>
      {data.data.length === 0 ? (
        <EmptyState compact icon={<Eye />} title={kind === 'all' ? 'Henüz öğretmen gözlemi yok' : 'Bu türde gözlem yok'} />
      ) : (
        <ul className="flex flex-col gap-2">
          {data.data.map((o) => (
            <li key={o.id} className="rounded-[var(--radius-md)] bg-surface px-4 py-3 ring-1 ring-line">
              <div className="flex flex-wrap items-center gap-1.5">
                <Badge tone={obsTone[o.kind] ?? 'neutral'}>
                  {o.kind === 'positive' ? <ThumbsUp className="size-3" /> : o.kind === 'improve' ? <ThumbsDown className="size-3" /> : null}
                  {o.kind_label}
                </Badge>
                <Badge tone="neutral">{o.category_label}</Badge>
                {o.points !== 0 && <span className={cn('text-[12.5px] font-semibold tabular', o.points > 0 ? 'text-success' : 'text-danger')}>{o.points > 0 ? '+' : ''}{o.points} puan</span>}
                <span className="ml-auto text-[12px] text-ink-3 tabular whitespace-nowrap">{dateTime(o.created_at)}</span>
              </div>
              <p className="mt-2 whitespace-pre-wrap text-[13.5px]">{o.body}</p>
              <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-ink-3">
                <span>{o.teacher?.name ?? 'Öğretmen'}{o.subject ? ` · ${o.subject}` : ''}{o.class_group ? ` · ${o.class_group}` : ''}</span>
                <span className="ml-auto flex flex-wrap gap-1">
                  {o.visible_to_guardian || o.visible_to_student ? (
                    <>
                      {o.visible_to_guardian && <Badge tone="info">Veli görür</Badge>}
                      {o.visible_to_student && <Badge tone="info">Öğrenci görür</Badge>}
                    </>
                  ) : (
                    <Badge tone="neutral">Yalnız personel</Badge>
                  )}
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function MiniStat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="rounded-[var(--radius-md)] bg-surface px-3 py-2.5 ring-1 ring-line">
      <p className="text-[12px] text-ink-3">{label}</p>
      <p className={cn('mt-0.5 text-[16px] font-semibold tabular', tone)}>{value}</p>
    </div>
  )
}

function NotesTab({ studentId }: { studentId: number }) {
  const qc = useQueryClient()
  const [body, setBody] = useState('')
  const [pinned, setPinned] = useState(false)
  const { data, isLoading } = useQuery({ queryKey: ['student', studentId, 'notes'], queryFn: () => api.get<{ data: any[] }>(`/students/${studentId}/notes`) })
  const add = useMutation({
    mutationFn: () => api.post(`/students/${studentId}/notes`, { body, is_pinned: pinned }),
    onSuccess: () => { setBody(''); setPinned(false); qc.invalidateQueries({ queryKey: ['student', studentId] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Not eklenemedi.'),
  })
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/students/${studentId}/notes/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['student', studentId] }),
  })

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <Panel>
        <Textarea rows={3} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Öğrenci hakkında not yazın…" />
        <div className="mt-2.5 flex items-center justify-between gap-2">
          <Checkbox checked={pinned} onChange={setPinned} label="Üste sabitle" />
          <Button variant="primary" size="sm" disabled={!body.trim()} loading={add.isPending} onClick={() => add.mutate()}>Notu kaydet</Button>
        </div>
      </Panel>
      {isLoading ? <Skeleton className="h-32" /> : data?.data.length === 0 ? <EmptyState compact icon={<NotebookPen />} title="Henüz not yok" /> : (
        <ul className="flex flex-col gap-2">
          {data?.data.map((n) => (
            <li key={n.id} className="rounded-[var(--radius-md)] bg-surface ring-1 ring-line px-4 py-3">
              <div className="flex items-start gap-2">
                {n.is_pinned && <Pin className="mt-0.5 size-3.5 text-primary" />}
                <p className="flex-1 whitespace-pre-wrap text-[13.5px]">{n.body}</p>
                <button onClick={() => remove.mutate(n.id)} className="text-ink-3 hover:text-danger" aria-label="Sil"><Trash2 className="size-3.5" /></button>
              </div>
              <p className="mt-1.5 text-[12px] text-ink-3">{n.user?.name} · {relative(n.created_at)}</p>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

const msgTone: Record<string, 'success' | 'warning' | 'danger' | 'neutral' | 'info'> = { read: 'success', delivered: 'info', sent: 'neutral', queued: 'neutral', sending: 'neutral', failed: 'danger' }
const chLabel: Record<string, string> = { whatsapp: 'WhatsApp', sms: 'SMS', email: 'E-posta', push: 'Uygulama bildirimi' }
const msgLabel: Record<string, string> = { pending: 'Sırada', simulated: 'Deneme gönderimi', cancelled: 'İptal edildi', read: 'Okundu', delivered: 'İletildi', sent: 'Gönderildi', queued: 'Kuyrukta', sending: 'Gönderiliyor', failed: 'Başarısız' }

function MessagesTab({ studentId }: { studentId: number }) {
  const { data, isLoading } = useQuery({ queryKey: ['student', studentId, 'messages'], queryFn: () => api.get<any>(`/students/${studentId}/messages`) })
  if (isLoading) return <Skeleton className="h-48" />
  if (!data?.data.length) return <EmptyState icon={<MessageCircle />} title="Bu öğrenci için gönderilmiş mesaj yok" />
  return (
    <Panel flush className="mx-auto max-w-3xl">
      <ul>
        {data.data.map((m: any) => (
          <li key={m.id} className="border-b border-line last:border-0 px-4 py-3">
            <div className="flex flex-wrap items-center gap-2 text-[12px] text-ink-3">
              <span>{chLabel[m.channel] ?? m.channel}</span> · <span className="tabular">{formatPhone(m.to)}</span> · {dateTime(m.created_at)}
              <Badge className="ml-auto" tone={msgTone[m.status] ?? 'neutral'}>{msgLabel[m.status] ?? m.status}</Badge>
            </div>
            <p className="mt-1.5 whitespace-pre-wrap text-[13px] text-ink-2">{m.body}</p>
            {m.error && <p className="mt-1 text-[12px] text-danger">{m.error}</p>}
          </li>
        ))}
      </ul>
    </Panel>
  )
}

const tlIcon: Record<string, { icon: ReactNode; className: string }> = {
  entry: { icon: <LogIn />, className: 'bg-success-soft text-success' },
  exit: { icon: <LogOut />, className: 'bg-surface-2 text-ink-2' },
  absent: { icon: <UserX />, className: 'bg-danger-soft text-danger' },
  late: { icon: <AlarmClock />, className: 'bg-warning-soft text-warning' },
  payment: { icon: <Wallet />, className: 'bg-primary-soft text-primary-ink' },
  exam: { icon: <GraduationCap />, className: 'bg-info-soft text-info' },
  guidance: { icon: <Sparkles />, className: 'bg-accent-soft text-accent' },
  note: { icon: <NotebookPen />, className: 'bg-surface-2 text-ink-2' },
  enrollment: { icon: <CheckCircle2 />, className: 'bg-success-soft text-success' },
}

function TimelineTab({ studentId }: { studentId: number }) {
  const { data, isLoading } = useQuery({ queryKey: ['student', studentId, 'timeline'], queryFn: () => api.get<{ data: any[] }>(`/students/${studentId}/timeline`) })
  if (isLoading) return <Skeleton className="h-64" />
  if (!data?.data.length) return <EmptyState icon={<Clock />} title="Henüz aktivite yok" />

  const groups = data.data.reduce<Record<string, any[]>>((acc, item) => {
    const key = String(item.at).slice(0, 10)
    ;(acc[key] ??= []).push(item)
    return acc
  }, {})

  return (
    <Panel className="mx-auto max-w-3xl">
      <div className="flex flex-col gap-5">
        {Object.entries(groups).map(([day, items]) => (
          <div key={day}>
            <p className="mb-2 text-[12.5px] font-semibold text-ink-2">{date(day, 'day')}</p>
            <ol className="relative ml-3.5 border-l border-line pl-5">
              {items.map((item, i) => {
                const meta = tlIcon[item.kind] ?? tlIcon.note!
                return (
                  <li key={i} className="relative pb-3 last:pb-0">
                    <span className={cn('absolute -left-[33px] grid size-6 place-items-center rounded-full ring-4 ring-surface [&_svg]:size-3', meta.className)}>{meta.icon}</span>
                    <p className="text-[13px]"><span className="mr-2 tabular text-ink-3">{time(item.at)}</span>{item.title}</p>
                    {item.detail && <p className="mt-0.5 text-[12.5px] text-ink-3">{item.detail}</p>}
                  </li>
                )
              })}
            </ol>
          </div>
        ))}
      </div>
    </Panel>
  )
}

function DetailSkeleton() {
  return (
    <div className="mx-auto flex max-w-[1200px] flex-col gap-4">
      <Skeleton className="h-4 w-40" />
      <Skeleton className="h-[172px] rounded-[var(--radius-lg)]" />
      <Skeleton className="h-10" />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Skeleton className="h-80 rounded-[var(--radius-lg)] lg:col-span-2" />
        <Skeleton className="h-80 rounded-[var(--radius-lg)]" />
      </div>
    </div>
  )
}

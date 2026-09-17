import { useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CalendarOff, Download, FileText, Pencil, Plus, Trash2, Upload } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, dateTime, percent, time } from '@/lib/format'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat, Tabs, DescriptionList } from '@/components/ui/layout'
import { Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog, Drawer, Menu } from '@/components/ui/overlay'
import { Field, Input, Select, Textarea } from '@/components/ui/form'
import type { TeacherOptions, TeacherProfile } from './types'
import { leaveKindLabels, weekdayLabels } from './types'
import { TeacherFormDrawer } from './TeacherFormDrawer'
import { ImpersonateTeacherButton, TeacherPortalAccountPanel } from '@/modules/students/StudentPortalAccountPanel'

type Tab = 'program' | 'siniflar' | 'izinler' | 'belgeler' | 'performans'

export default function TeacherDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const can = useCan()
  const qc = useQueryClient()
  const [tab, setTab] = useState<Tab>('program')
  const [editOpen, setEditOpen] = useState(false)
  const [leaveOpen, setLeaveOpen] = useState(false)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  const options = useQuery({ queryKey: ['teachers', 'options'], queryFn: () => api.get<TeacherOptions>('/teachers/options'), staleTime: 5 * 60_000 })
  const { data, isLoading, error } = useQuery({ queryKey: ['teachers', id], queryFn: () => api.get<TeacherProfile>(`/teachers/${id}`), enabled: !!id })

  const del = useMutation({
    mutationFn: () => api.delete<{ message: string }>(`/teachers/${id}`),
    onSuccess: (r) => { toast.success(r.message); navigate('/ogretmenler') },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Silinemedi.'),
  })

  const uploadDoc = useMutation({
    mutationFn: (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      fd.append('category', 'other')
      return api.post(`/teachers/${id}/documents`, fd)
    },
    onSuccess: () => { toast.success('Belge yüklendi.'); qc.invalidateQueries({ queryKey: ['teachers', id] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Belge yüklenemedi.'),
  })

  if (error) {
    return <EmptyState title="Öğretmen bulunamadı" description={error instanceof ApiError && error.status !== 404 ? error.message : 'Kayıt silinmiş ya da adres hatalı olabilir.'} action={<Button onClick={() => navigate('/ogretmenler')}>Öğretmenlere dön</Button>} />
  }
  if (isLoading || !data) {
    return (
      <div className="animate-fade-in space-y-4">
        <Skeleton className="h-20 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  const t = data.teacher

  return (
    <div className="animate-fade-in">
      <PageHeader
        breadcrumbs={[{ label: 'Öğretmenler', to: '/ogretmenler' }, { label: t.full_name }]}
        title={
          <div className="flex items-center gap-3">
            <Avatar name={t.full_name} src={t.avatar_url} size={40} />
            <span>{t.full_name}</span>
            <Badge tone={t.is_active ? 'success' : 'neutral'} dot>{t.is_active ? 'Aktif' : 'Pasif'}</Badge>
          </div>
        }
        description={[t.title, t.specialty].filter(Boolean).join(' · ') || 'Öğretmen profili'}
        actions={
          <>
          {can('teachers.impersonate') && t.is_active && <ImpersonateTeacherButton teacherId={t.id} />}
          {can('teachers.manage') && (
            <>
              <Button icon={<Pencil className="size-4" />} onClick={() => setEditOpen(true)}>Düzenle</Button>
              <Menu
                trigger={<Button variant="ghost">…</Button>}
                items={[
                  { label: 'Fotoğraf yükle', icon: <Upload />, onClick: () => fileRef.current?.click() },
                  'divider',
                  { label: 'Sil', icon: <Trash2 />, danger: true, onClick: () => setDeleteOpen(true) },
                ]}
              />
              <input ref={fileRef} type="file" accept="image/*" hidden onChange={(e) => {
                const file = e.target.files?.[0]
                if (!file) return
                const fd = new FormData()
                fd.append('photo', file)
                api.post(`/teachers/${id}/photo`, fd).then(() => { toast.success('Fotoğraf güncellendi.'); qc.invalidateQueries({ queryKey: ['teachers', id] }) }).catch((err) => toast.error(err.message))
              }} />
            </>
          )}
          </>
        }
      />

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <Stat label="Bu hafta ders saati" value={`${Number(t.weekly_hours).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} saat`} />
        <Stat label="Bu ay ders saati" value={`${Number(data.hours_this_month).toLocaleString('tr-TR', { maximumFractionDigits: 1 })} saat`} />
        <Stat label="Yoklama alma oranı" value={data.attendance_rate !== null ? percent(data.attendance_rate) : '—'} tone={data.attendance_rate !== null && data.attendance_rate < 80 ? 'warning' : undefined} />
        <Stat label="Ödev notlandırma oranı" value={data.performance.grading_rate !== null ? percent(data.performance.grading_rate) : '—'} />
      </div>

      <div className="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
      <Panel className="lg:col-span-2">
        <DescriptionList
          columns={2}
          items={[
            { label: 'Cep telefonu', value: <PhoneText value={t.phone} whatsapp={!t.whatsapp_phone || t.whatsapp_phone === t.phone} /> },
            { label: 'WhatsApp numarası', value: t.whatsapp_phone && t.whatsapp_phone !== t.phone ? <PhoneText value={t.whatsapp_phone} whatsapp /> : <span className="text-ink-3">Cep telefonuyla aynı</span> },
            { label: 'E-posta', value: <MailText value={t.email} /> },
            { label: 'İşe başlama tarihi', value: date(t.hired_on) },
            { label: 'Çalışma türü', value: t.employment_type_label },
            { label: 'Ders saati ücreti', value: t.hourly_rate ? `${t.hourly_rate} ₺` : '—' },
            { label: 'Portal kullanıcı adı', value: t.user ? <span>{t.user.username} {t.user.must_change_password && <Badge tone="warning">Şifre değişikliği bekleniyor</Badge>}</span> : 'Hesap yok' },
            { label: 'Not', value: t.notes, hidden: !t.notes },
          ]}
        />
      </Panel>
      <TeacherPortalAccountPanel teacherId={t.id} teacherName={t.full_name} phone={t.whatsapp_phone || t.phone} />
      </div>

      <Tabs
        value={tab}
        onChange={setTab}
        className="mb-4"
        tabs={[
          { value: 'program', label: 'Ders programı', count: data.week_schedule.length },
          { value: 'siniflar', label: 'Sınıflar', count: data.classes.length },
          { value: 'izinler', label: 'İzinler', count: data.leaves.length },
          { value: 'belgeler', label: 'Belgeler', count: data.documents.length },
          { value: 'performans', label: 'Performans' },
        ]}
      />

      {tab === 'program' && data.availabilities.length > 0 && (
        <Panel title="Uygunluk saatleri" description="Düzenleme Akademik modülünden yapılır" className="mb-4">
          <div className="flex flex-wrap gap-2">
            {data.availabilities.map((a) => (
              <Badge key={a.id}>{weekdayLabels[a.weekday]} {a.starts_at.slice(0, 5)}–{a.ends_at.slice(0, 5)}</Badge>
            ))}
          </div>
        </Panel>
      )}

      {tab === 'program' && (
        <Panel title="Bu haftanın programı">
          {data.week_schedule.length === 0 ? (
            <EmptyState title="Bu hafta ders yok" />
          ) : (
            <div className="overflow-x-auto scroll-thin">
              <table className="w-full text-[13px]">
                <thead>
                  <tr className="text-center text-ink-3 border-b border-line [&>th:first-child]:text-left">
                    <th className="py-2 pr-3 font-medium">Tarih</th><th className="py-2 pr-3 font-medium">Saat</th>
                    <th className="py-2 pr-3 font-medium">Ders</th><th className="py-2 pr-3 font-medium">Girdiği sınıf</th>
                    <th className="py-2 pr-3 font-medium">Derslik</th><th className="py-2 pr-3 font-medium">Durum</th>
                  </tr>
                </thead>
                <tbody>
                  {data.week_schedule.map((s) => (
                    <tr key={s.id} className="border-b border-line last:border-0 text-center [&>td:first-child]:text-left">
                      <td className="py-2 pr-3">{date(s.date)}</td>
                      <td className="py-2 pr-3 tabular">{time(s.starts_at)}–{time(s.ends_at)}</td>
                      <td className="py-2 pr-3">{s.subject}</td>
                      <td className="py-2 pr-3">{s.class_group}</td>
                      <td className="py-2 pr-3">{s.classroom}</td>
                      <td className="py-2 pr-3"><Badge tone={s.status === 'completed' ? 'success' : s.status === 'cancelled' ? 'danger' : 'neutral'}>{s.status === 'completed' ? 'Tamamlandı' : s.status === 'cancelled' ? 'İptal' : 'Planlandı'}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      )}

      {tab === 'siniflar' && (
        <Panel title="Verdiği sınıflar">
          {data.classes.length === 0 ? <EmptyState title="Atanmış sınıf yok" /> : (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              {data.classes.map((c) => (
                <div key={c.id} className="rounded-[var(--radius-md)] ring-1 ring-line p-3.5">
                  <p className="font-medium text-ink">{c.name}</p>
                  <p className="text-[12.5px] text-ink-3 mt-0.5">{c.student_count} öğrenci</p>
                </div>
              ))}
            </div>
          )}
        </Panel>
      )}

      {tab === 'izinler' && (
        <Panel title="İzinler" actions={can('teachers.manage') && <Button size="sm" icon={<Plus className="size-3.5" />} onClick={() => setLeaveOpen(true)}>İzin ekle</Button>}>
          {data.leaves.length === 0 ? <EmptyState icon={<CalendarOff />} title="İzin kaydı yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.leaves.map((l) => (
                <div key={l.id} className="flex items-center justify-between py-2.5">
                  <div>
                    <p className="text-[13.5px] text-ink">{date(l.starts_on)} – {date(l.ends_on)}</p>
                    <p className="text-[12px] text-ink-3">İzin türü: {leaveKindLabels[l.kind] ?? l.kind}{l.reason ? ` · Açıklama: ${l.reason}` : ''}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={l.status === 'approved' ? 'success' : l.status === 'rejected' ? 'danger' : 'warning'}>{l.status === 'approved' ? 'Onaylandı' : l.status === 'rejected' ? 'Reddedildi' : 'Onay bekliyor'}</Badge>
                    {can('teachers.manage') && (
                      <Button size="xs" variant="ghost" className="text-danger" aria-label="İzni sil" title="İzni sil" icon={<Trash2 className="size-3.5" />} onClick={() => {
                        api.delete(`/teachers/${id}/leaves/${l.id}`).then(() => { toast.success('İzin silindi.'); qc.invalidateQueries({ queryKey: ['teachers', id] }) }).catch((e) => toast.error(e.message))
                      }} />
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Panel>
      )}

      {tab === 'belgeler' && (
        <Panel title="Belgeler" actions={can('teachers.manage') && <Button size="sm" icon={<Upload className="size-3.5" />} onClick={() => document.getElementById('doc-upload-input')?.click()}>Belge yükle</Button>}>
          <input id="doc-upload-input" type="file" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) uploadDoc.mutate(f) }} />
          {data.documents.length === 0 ? <EmptyState icon={<FileText />} title="Belge yok" /> : (
            <div className="flex flex-col divide-y divide-line">
              {data.documents.map((d) => (
                <div key={d.id} className="flex items-center justify-between py-2.5">
                  <div className="min-w-0">
                    <p className="text-[13.5px] text-ink truncate">{d.title}</p>
                    <p className="text-[12px] text-ink-3">Yüklenme: {dateTime(d.created_at)} · {(d.size / 1024).toFixed(0)} KB</p>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    <Button size="xs" variant="ghost" aria-label="İndir" title="İndir" icon={<Download className="size-3.5" />} onClick={() => api.download(`/teachers/${id}/documents/${d.id}/download`, undefined, d.title)} />
                    {can('teachers.manage') && (
                      <Button size="xs" variant="ghost" className="text-danger" aria-label="Belgeyi sil" title="Belgeyi sil" icon={<Trash2 className="size-3.5" />} onClick={() => {
                        api.delete(`/teachers/${id}/documents/${d.id}`).then(() => { toast.success('Belge silindi.'); qc.invalidateQueries({ queryKey: ['teachers', id] }) }).catch((e) => toast.error(e.message))
                      }} />
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Panel>
      )}

      {tab === 'performans' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Panel title="Sınav ortalaması eğilimi (sınıflarının son sınavları)">
            {data.performance.exam_trend.length === 0 ? <EmptyState title="Henüz sonuçlanmış sınav yok" /> : (
              <div className="flex flex-col divide-y divide-line">
                {data.performance.exam_trend.map((e) => (
                  <div key={e.id} className="flex items-center justify-between py-2">
                    <div>
                      <p className="text-[13.5px] text-ink">{e.name}</p>
                      <p className="text-[12px] text-ink-3">{date(e.exam_date)}</p>
                    </div>
                    <span className="tabular text-ink font-medium" title="Ortalama net">Ortalama {Number(e.avg_net).toLocaleString('tr-TR', { maximumFractionDigits: 1 })}</span>
                  </div>
                ))}
              </div>
            )}
          </Panel>
          <Panel title="Ödev değerlendirme">
            <DescriptionList
              columns={2}
              items={[
                { label: 'Toplam ödev', value: data.performance.homework_total },
                { label: 'Teslim edilen', value: data.performance.homework_submitted },
                { label: 'Notlandırılan', value: data.performance.homework_graded },
                { label: 'Notlandırma oranı', value: data.performance.grading_rate !== null ? percent(data.performance.grading_rate) : '—' },
              ]}
            />
          </Panel>
        </div>
      )}

      <TeacherFormDrawer open={editOpen} options={options.data} teacher={t} onClose={() => setEditOpen(false)} onSaved={() => qc.invalidateQueries({ queryKey: ['teachers', id] })} />
      <LeaveDrawer open={leaveOpen} teacherId={Number(id)} onClose={() => setLeaveOpen(false)} onSaved={() => qc.invalidateQueries({ queryKey: ['teachers', id] })} />
      <ConfirmDialog open={deleteOpen} onClose={() => setDeleteOpen(false)} onConfirm={() => del.mutate()} title="Öğretmeni sil" description={`${t.full_name} kaydı silinecek.`} danger loading={del.isPending} confirmLabel="Sil" />
    </div>
  )
}

function LeaveDrawer({ open, teacherId, onClose, onSaved }: { open: boolean; teacherId: number; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState<Record<string, string>>({ kind: 'annual' })
  const save = useMutation({
    mutationFn: () => api.post(`/teachers/${teacherId}/leaves`, form),
    onSuccess: () => { toast.success('İzin eklendi.'); onClose(); onSaved() },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Eklenemedi.'),
  })
  return (
    <Drawer open={open} onClose={onClose} width={420} title="İzin ekle" footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>Ekle</Button></>}>
      <div className="grid grid-cols-1 gap-3">
        <Field label="İlk izin günü" required><Input type="date" value={form.starts_on ?? ''} onChange={(e) => setForm((f) => ({ ...f, starts_on: e.target.value }))} /></Field>
        <Field label="Son izin günü" required><Input type="date" value={form.ends_on ?? ''} onChange={(e) => setForm((f) => ({ ...f, ends_on: e.target.value }))} /></Field>
        <Field label="İzin türü" required><Select value={form.kind} onChange={(e) => setForm((f) => ({ ...f, kind: e.target.value }))} options={Object.entries(leaveKindLabels).map(([value, label]) => ({ value, label }))} /></Field>
        <Field label="Açıklama" optional><Textarea rows={2} value={form.reason ?? ''} onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))} /></Field>
      </div>
    </Drawer>
  )
}

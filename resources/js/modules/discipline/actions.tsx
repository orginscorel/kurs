import { useEffect, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Copy, MessageCircle } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date as fmtDate, todayISO } from '@/lib/format'
import { Modal } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Input, Segmented, Select, Textarea } from '@/components/ui/form'
import { Alert, Badge, Skeleton } from '@/components/ui/feedback'
import { PersonText, PhoneText } from '@/components/ui/contact'
import type { DisciplineOptions, IncidentDetail, Participant, Sanction } from './types'

/** Tek adımlı işlem penceresi: gönder → bildirim → disiplin sorgularını yenile. */
function ActionModal({ open, onClose, title, description, submitLabel, tone = 'primary', run, disabled, children, size }: {
  open: boolean; onClose: () => void; title: string; description?: ReactNode; submitLabel: string
  tone?: 'primary' | 'danger'; run: () => Promise<{ message?: string }>; disabled?: boolean; children?: ReactNode; size?: 'sm' | 'md' | 'lg'
}) {
  const qc = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const m = useMutation({
    mutationFn: run,
    onSuccess: (res) => {
      toast.success(res?.message ?? 'Kaydedildi.')
      qc.invalidateQueries({ queryKey: ['discipline'] })
      qc.invalidateQueries({ queryKey: ['risk'] })
      onClose()
    },
    onError: (e) => setError(e instanceof ApiError ? e.firstError() : 'İşlem tamamlanamadı.'),
  })
  useEffect(() => { if (open) setError(null) }, [open])
  return (
    <Modal open={open} onClose={onClose} title={title} description={description} size={size}
      footer={<><Button variant="ghost" onClick={onClose}>Vazgeç</Button><Button variant={tone} loading={m.isPending} disabled={disabled} onClick={() => m.mutate()}>{submitLabel}</Button></>}>
      <div className="flex flex-col gap-3.5">
        {children}
        {error && <Alert tone="danger">{error}</Alert>}
      </div>
    </Modal>
  )
}

const addDays = (n: number) => {
  const d = new Date()
  d.setDate(d.getDate() + n)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function DefenseRequestModal({ incident, student, opt, onClose }: { incident: IncidentDetail; student: Participant | null; opt?: DisciplineOptions; onClose: () => void }) {
  const [due, setDue] = useState('')
  const [note, setNote] = useState('')
  useEffect(() => { if (student) { setDue(addDays(opt?.settings.default_defense_days ?? 3)); setNote('') } }, [student, opt])
  return (
    <ActionModal open={!!student} onClose={onClose} title="Yazılı savunma iste" description={student?.full_name} submitLabel="Savunma iste"
      run={() => api.post(`/discipline/incidents/${incident.id}/defenses`, { student_id: student!.student_id, due_on: due, note: note || null })}>
      <Field label="Son teslim tarihi" required><Input type="date" min={todayISO()} value={due} onChange={(e) => setDue(e.target.value)} /></Field>
      <Field label="Öğrenciye açıklama" optional hint="İstem yazısında ve (açıksa) öğrenci portalında görünür."><Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Örn. Olayı kendi bakış açınızla anlatınız." /></Field>
      <p className="text-[12.5px] text-ink-3">Kaydettikten sonra "İstem yazısı" ile PDF basabilirsiniz. Öğrenci portalı açıksa savunmasını oradan da yazabilir.</p>
    </ActionModal>
  )
}

export function DefenseRecordModal({ defenseId, name, onClose }: { defenseId: number | null; name?: string; onClose: () => void }) {
  const [text, setText] = useState('')
  const [at, setAt] = useState('')
  useEffect(() => { if (defenseId) { setText(''); setAt(todayISO()) } }, [defenseId])
  return (
    <ActionModal open={!!defenseId} onClose={onClose} title="Savunmayı kaydet" description={name} submitLabel="Savunmayı kaydet" size="lg" disabled={text.trim().length < 3}
      run={() => api.post(`/discipline/defenses/${defenseId}/record`, { statement: text, submitted_at: at })}>
      <Field label="Teslim tarihi" optional hint="Boş bırakılırsa bugün"><Input type="date" max={todayISO()} value={at} onChange={(e) => setAt(e.target.value)} /></Field>
      <Field label="Savunma metni" required hint="Öğrencinin yazılı savunmasını aynen aktarın."><Textarea rows={9} value={text} onChange={(e) => setText(e.target.value)} /></Field>
    </ActionModal>
  )
}

export function WaiveModal({ incident, student, onClose }: { incident: IncidentDetail; student: Participant | null; onClose: () => void }) {
  const [reason, setReason] = useState('')
  useEffect(() => setReason(''), [student])
  return (
    <ActionModal open={!!student} onClose={onClose} title="Savunma alınmadı" description={student?.full_name} submitLabel="İşaretle" disabled={reason.trim().length < 3}
      run={() => api.post(`/discipline/incidents/${incident.id}/defenses/waive`, { student_id: student!.student_id, reason })}>
      <Field label="Gerekçe" required hint="Listeden seçin ya da aşağıya kendiniz yazın (en az 3 karakter)">
        <Select value={['Süresi içinde savunma vermedi', 'Savunma vermeyi reddetti', 'Sözlü uyarı için savunma gerekmedi'].includes(reason) ? reason : ''} onChange={(e) => setReason(e.target.value)} placeholder="Seçin ya da yazın"
          options={['Süresi içinde savunma vermedi', 'Savunma vermeyi reddetti', 'Sözlü uyarı için savunma gerekmedi'].map((v) => ({ value: v, label: v }))} />
      </Field>
      <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Gerekçeyi yazın" aria-label="Gerekçe" />
    </ActionModal>
  )
}

export function SanctionModal({ incident, student, opt, onClose }: { incident: IncidentDetail; student: Participant | null; opt?: DisciplineOptions; onClose: () => void }) {
  const [typeId, setTypeId] = useState('')
  const [f, setF] = useState<Record<string, any>>({})
  const types = (opt?.sanction_types ?? [])
  useEffect(() => {
    if (!student) return
    const suggested = types.find((t) => t.code === student.suggested_sanction)
    setTypeId(suggested ? String(suggested.id) : '')
    setF({ starts_on: addDays(1), days: 1, duty_description: '', decision_note: '', visible_to_portal: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [student])
  const type = types.find((t) => String(t.id) === typeId)
  const set = (k: string, v: unknown) => setF((x) => ({ ...x, [k]: v }))
  const end = type?.is_suspension && f.starts_on && f.days ? (() => { const d = new Date(f.starts_on); d.setDate(d.getDate() + Number(f.days) - 1); return fmtDate(d) })() : null

  return (
    <ActionModal open={!!student} onClose={onClose} title="Yaptırım" description={student ? `${student.full_name} · ${student.behavior ?? ''}` : undefined}
      submitLabel={type?.authority === 'board' ? 'Kurula öner' : 'Yaptırımı ver'} disabled={!type} size="lg"
      run={() => api.post(`/discipline/incidents/${incident.id}/sanctions`, {
        student_id: student!.student_id, sanction_type_id: Number(typeId), decision_note: f.decision_note || null,
        duty_description: type?.has_duty ? f.duty_description : null, starts_on: type?.is_suspension ? f.starts_on : null,
        days: type?.is_suspension ? Number(f.days) : null, visible_to_portal: !!f.visible_to_portal,
      })}>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        {types.map((t) => (
          <button key={t.id} type="button" onClick={() => setTypeId(String(t.id))} aria-pressed={String(t.id) === typeId}
            className={`flex items-start gap-2.5 rounded-[var(--radius-md)] p-2.5 text-left ring-1 transition-colors ${String(t.id) === typeId ? 'bg-primary-soft ring-primary' : 'ring-line hover:bg-surface-2'}`}>
            <span className="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-surface-2 text-[11px] font-semibold tabular ring-1 ring-line">{t.level}</span>
            <span className="min-w-0">
              <span className="block text-[13px] font-medium text-ink">{t.name}{t.code === student?.suggested_sanction && <Badge tone="info" className="ml-1.5">Önerilen</Badge>}</span>
              <span className="block text-[12px] text-ink-3">{t.authority === 'board' ? 'Kurul kararı gerekir' : 'Yetkili tek başına verir'}{t.expires_after_days ? ` · ${t.expires_after_days} gün sonra düşer` : ''}</span>
            </span>
          </button>
        ))}
      </div>
      {type?.authority === 'board' && (
        <Alert tone="warning">Bu yaptırım <b>öneri</b> olarak kaydedilir; disiplin kurulu kabul edince yürürlüğe girer. Kurul öncesi öğrencinin savunması alınmış olmalı.</Alert>
      )}
      {type?.is_suspension && (
        <div className="grid grid-cols-2 gap-3">
          <Field label="Başlangıç tarihi" required><Input type="date" value={f.starts_on ?? ''} onChange={(e) => set('starts_on', e.target.value)} /></Field>
          <Field label="Gün sayısı" required hint={end ? `Bitiş: ${end}` : undefined}><Input type="number" min={1} max={30} value={f.days ?? 1} onChange={(e) => set('days', e.target.value)} /></Field>
        </div>
      )}
      {type?.has_duty && (
        <Field label="Görev açıklaması" required><Input value={f.duty_description ?? ''} onChange={(e) => set('duty_description', e.target.value)} placeholder="Örn. 3 gün 17:00-18:00 kütüphane düzeni / ek etüt" maxLength={300} /></Field>
      )}
      <Field label="Karar notu (iç kullanım)" optional><Textarea rows={3} value={f.decision_note ?? ''} onChange={(e) => set('decision_note', e.target.value)} /></Field>
      <Checkbox checked={!!f.visible_to_portal} onChange={(v) => set('visible_to_portal', v)} label="Veli ve öğrenci portalında göster (kurum ayarı açıksa)" />
    </ActionModal>
  )
}

export function SanctionStatusModal({ sanction, to, onClose }: { sanction: Sanction | null; to: 'completed' | 'cancelled'; onClose: () => void }) {
  const [reason, setReason] = useState('')
  useEffect(() => setReason(''), [sanction])
  return (
    <ActionModal open={!!sanction} onClose={onClose} tone={to === 'cancelled' ? 'danger' : 'primary'}
      title={to === 'cancelled' ? 'Yaptırımı iptal et' : 'Yaptırımı tamamlandı say'} description={sanction ? `${sanction.sanction_no} · ${sanction.type?.name}` : undefined}
      submitLabel={to === 'cancelled' ? 'İptal et' : 'Tamamlandı'} disabled={to === 'cancelled' && reason.trim().length < 3}
      run={() => api.post(`/discipline/sanctions/${sanction!.id}/status`, { status: to, reason: reason || null })}>
      <Field label={to === 'cancelled' ? 'İptal gerekçesi' : 'Not'} required={to === 'cancelled'} optional={to !== 'cancelled'}><Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
      {to === 'cancelled' && <p className="text-[12.5px] text-ink-3">İptal edilen yaptırım puan hesabına ve portala yansımaz; kayıt silinmez.</p>}
    </ActionModal>
  )
}

export function AppealModal({ sanction, onClose }: { sanction: Sanction | null; onClose: () => void }) {
  const [f, setF] = useState({ appellant: 'guardian', appealed_on: todayISO(), reason: '' })
  useEffect(() => setF({ appellant: 'guardian', appealed_on: todayISO(), reason: '' }), [sanction])
  return (
    <ActionModal open={!!sanction} onClose={onClose} title="İtiraz kaydet" description={sanction ? `${sanction.sanction_no} · ${sanction.type?.name} · ${sanction.student?.full_name}` : undefined}
      submitLabel="İtirazı kaydet" disabled={f.reason.trim().length < 3}
      run={() => api.post(`/discipline/sanctions/${sanction!.id}/appeal`, f)}>
      <div className="grid grid-cols-2 gap-3">
        <Field label="İtiraz eden" required><Segmented value={f.appellant} onChange={(v) => setF({ ...f, appellant: v })} options={[{ value: 'guardian', label: 'Veli' }, { value: 'student', label: 'Öğrenci' }]} /></Field>
        <Field label="İtiraz tarihi" optional hint="Boş bırakılırsa bugün"><Input type="date" max={todayISO()} value={f.appealed_on} onChange={(e) => setF({ ...f, appealed_on: e.target.value })} /></Field>
      </div>
      <Field label="İtiraz gerekçesi" required><Textarea rows={5} value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
    </ActionModal>
  )
}

export function AppealDecideModal({ sanction, opt, onClose }: { sanction: Sanction | null; opt?: DisciplineOptions; onClose: () => void }) {
  const appeal = sanction?.appeals.find((a) => a.status === 'pending')
  const [f, setF] = useState<Record<string, any>>({ status: 'rejected' })
  useEffect(() => setF({ status: 'rejected', result_note: '', new_sanction_type_id: '', starts_on: sanction?.starts_on ?? addDays(1), days: 1 }), [sanction])
  const lighter = (opt?.sanction_types ?? []).filter((t) => t.level < (sanction?.type?.level ?? 0))
  const newType = lighter.find((t) => String(t.id) === String(f.new_sanction_type_id))
  return (
    <ActionModal open={!!sanction && !!appeal} onClose={onClose} title="İtirazı karara bağla" description={sanction ? `${sanction.sanction_no} · ${sanction.type?.name}` : undefined}
      submitLabel="Kararı kaydet" size="lg" disabled={f.status === 'modified' && !f.new_sanction_type_id}
      run={() => api.post(`/discipline/appeals/${appeal!.id}/decide`, { ...f, new_sanction_type_id: f.new_sanction_type_id || null, days: newType?.is_suspension ? Number(f.days) : null, starts_on: newType?.is_suspension ? f.starts_on : null })}>
      {appeal && <div className="rounded-[var(--radius-md)] bg-surface-2 p-3 text-[13px]"><p className="mb-1 text-[12px] text-ink-3">{appeal.appellant === 'student' ? 'Öğrenci' : 'Veli'} · {fmtDate(appeal.appealed_on)}</p>{appeal.reason}</div>}
      <Field label="Karar" required>
        <Segmented value={f.status} onChange={(v) => setF({ ...f, status: v })}
          options={[{ value: 'rejected', label: 'Reddet (yaptırım sürer)' }, { value: 'modified', label: 'Hafiflet' }, { value: 'accepted', label: 'Kabul (kaldır)' }]} />
      </Field>
      {f.status === 'modified' && (
        <Field label="Yeni yaptırım" required>
          <Select value={f.new_sanction_type_id} onChange={(e) => setF({ ...f, new_sanction_type_id: e.target.value })} placeholder="Daha hafif yaptırım seçin"
            options={lighter.map((t) => ({ value: t.id, label: t.name }))} />
        </Field>
      )}
      {newType?.is_suspension && (
        <div className="grid grid-cols-2 gap-3">
          <Field label="Başlangıç tarihi" required><Input type="date" value={f.starts_on} onChange={(e) => setF({ ...f, starts_on: e.target.value })} /></Field>
          <Field label="Gün sayısı" required><Input type="number" min={1} max={30} value={f.days} onChange={(e) => setF({ ...f, days: e.target.value })} /></Field>
        </div>
      )}
      <Field label="Karar gerekçesi" optional><Textarea rows={3} value={f.result_note} onChange={(e) => setF({ ...f, result_note: e.target.value })} /></Field>
    </ActionModal>
  )
}

export function StatusModal({ incident, to, onClose }: { incident: IncidentDetail; to: string | null; onClose: () => void }) {
  const [note, setNote] = useState('')
  const [unfounded, setUnfounded] = useState(false)
  useEffect(() => { setNote(''); setUnfounded(false) }, [to])
  const labels: Record<string, string> = { open: 'Yeniden aç', review: 'İncelemeye al', decided: 'Karara bağlandı olarak işaretle', closed: 'Olayı kapat', appealed: 'İtiraz' }
  return (
    <ActionModal open={!!to} onClose={onClose} title={to ? labels[to] ?? 'Durum' : ''} description={incident.incident_no} submitLabel="Onayla" tone={unfounded ? 'danger' : 'primary'}
      run={() => api.post(`/discipline/incidents/${incident.id}/status`, { status: to, note: note || null, outcome: unfounded ? 'unfounded' : null })}>
      {to === 'closed' && incident.kind === 'negative' && (
        <Checkbox checked={unfounded} onChange={setUnfounded} label="Olay asılsız çıktı (öğrencilerin puanları sayılmaz)" />
      )}
      {to === 'decided' && incident.sanctions.length === 0 && <Alert tone="info">Yaptırım verilmeden karara bağlanıyor. Nedenini nota yazın (ör. "Uyarı ile yetinildi").</Alert>}
      <Field label="Not" optional><Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
    </ActionModal>
  )
}

export function PointsModal({ incident, student, onClose }: { incident: IncidentDetail; student: Participant | null; onClose: () => void }) {
  const [pts, setPts] = useState(0)
  const [reason, setReason] = useState('')
  useEffect(() => { if (student) { setPts(incident.kind === 'positive' ? student.merit_points : student.penalty_points); setReason('') } }, [student, incident.kind])
  return (
    <ActionModal open={!!student} onClose={onClose} title="Puanı düzelt" description={student?.full_name} submitLabel="Kaydet" disabled={reason.trim().length < 3}
      run={() => api.post(`/discipline/incidents/${incident.id}/points`, { student_id: student!.student_id, points: pts, reason })}>
      <Field label={incident.kind === 'positive' ? 'Olumlu puan' : 'Ceza puanı'} required hint="0–100 arası"><Input type="number" min={0} max={100} value={pts} onChange={(e) => setPts(Number(e.target.value))} /></Field>
      <Field label="Gerekçe" required><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Örn. Hafifletici durum: ilk kez" /></Field>
    </ActionModal>
  )
}

export function DeleteModal({ incident, open, onClose, onDone }: { incident: IncidentDetail; open: boolean; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  return (
    <ActionModal open={open} onClose={onClose} tone="danger" title="Kaydı sil" description={incident.incident_no} submitLabel="Sil" disabled={reason.trim().length < 3}
      run={async () => { const r = await api.delete<{ message: string }>(`/discipline/incidents/${incident.id}`, { reason }); onDone(); return r }}>
      <Alert tone="warning">Yanlışlıkla açılan kayıtlar için. Yaptırım uygulanmış olaylar silinemez; "asılsız" olarak kapatın.</Alert>
      <Field label="Silme gerekçesi" required><Input value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
    </ActionModal>
  )
}

type Draft = { text: string; template_found: boolean; guardians: { name: string; phone: string | null; relationship: string | null; is_primary: boolean }[]; notified_at: string | null }

/** Veli bildirim TASLAĞI: şablondan metin; gönderim yapılmaz, kopyalanır ve "bilgilendirildi" işaretlenir. */
export function NotifyModal({ incident, target, onClose }: { incident: IncidentDetail; target: { student: Participant; kind: 'sanction' | 'defense' | 'positive'; sanctionId?: number } | null; onClose: () => void }) {
  const [via, setVia] = useState('phone')
  const draft = useQuery({
    queryKey: ['discipline', 'notify-draft', incident.id, target?.student.student_id, target?.kind, target?.sanctionId],
    queryFn: () => api.get<{ data: Draft }>(`/discipline/incidents/${incident.id}/notify-draft`, { student_id: target!.student.student_id, kind: target!.kind, sanction_id: target!.sanctionId }).then((r) => r.data),
    enabled: !!target,
  })
  const [text, setText] = useState('')
  useEffect(() => { if (draft.data) setText(draft.data.text) }, [draft.data])
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success('Metin kopyalandı.')
    } catch {
      toast.error('Kopyalanamadı; metni seçip kopyalayın.')
    }
  }
  return (
    <ActionModal open={!!target} onClose={onClose} size="lg" title="Veli bildirimi (taslak)" description={target?.student.full_name}
      submitLabel="Veli bilgilendirildi olarak işaretle"
      run={() => api.post(`/discipline/incidents/${incident.id}/notified`, { via, sanction_id: target?.sanctionId ?? null })}>
      <Alert tone="info" icon={<MessageCircle />}>Sistem mesaj GÖNDERMEZ. Metni kopyalayıp telefonla/WhatsApp ile iletin, ardından işaretleyin.</Alert>
      {draft.isLoading ? <Skeleton className="h-40" /> : (
        <>
          <div className="flex flex-wrap gap-2">
            {(draft.data?.guardians ?? []).map((g) => (
              <span key={g.name} className="rounded-[var(--radius-sm)] bg-surface-2 px-2.5 py-1 text-[12.5px] ring-1 ring-line">
                <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5"><PersonText>{g.name}</PersonText>{g.is_primary && <Badge tone="primary">Birincil veli</Badge>}{g.phone ? <PhoneText value={g.phone} whatsapp /> : <span className="text-ink-3">Telefonu kayıtlı değil</span>}</span>
              </span>
            ))}
            {draft.data && draft.data.guardians.length === 0 && <span className="text-[12.5px] text-warning">Öğrencinin kayıtlı velisi yok.</span>}
          </div>
          <Field label="Mesaj metni" hint={draft.data?.template_found ? 'İletişim › Şablonlar ekranındaki şablondan üretildi.' : 'Şablon bulunamadı; varsayılan metin.'}>
            <Textarea rows={8} value={text} onChange={(e) => setText(e.target.value)} />
          </Field>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <Button size="sm" icon={<Copy className="size-3.5" />} onClick={copy}>Metni kopyala</Button>
            <Segmented size="sm" value={via} onChange={setVia} options={[{ value: 'phone', label: 'Telefon' }, { value: 'whatsapp', label: 'WhatsApp' }, { value: 'meeting', label: 'Yüz yüze' }, { value: 'letter', label: 'Yazılı' }]} />
          </div>
        </>
      )}
    </ActionModal>
  )
}

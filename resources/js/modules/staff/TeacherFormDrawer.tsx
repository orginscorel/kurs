import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { Drawer } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Switch, Textarea } from '@/components/ui/form'
import { Alert } from '@/components/ui/feedback'
import type { TeacherDetail, TeacherOptions } from './types'

type Props = {
  open: boolean
  onClose: () => void
  onSaved: (id: number) => void
  options?: TeacherOptions
  teacher?: TeacherDetail
}

const COLOR_TR: Record<string, string> = {
  indigo: 'Çivit mavisi', sky: 'Açık mavi', emerald: 'Zümrüt yeşili', amber: 'Kehribar', rose: 'Gül kurusu', violet: 'Menekşe', orange: 'Turuncu', slate: 'Gri',
}

export function TeacherFormDrawer({ open, onClose, onSaved, options, teacher }: Props) {
  const editing = !!teacher
  const [form, setForm] = useState<Record<string, any>>({})
  const [subjectIds, setSubjectIds] = useState<number[]>([])
  const [createUser, setCreateUser] = useState(false)
  const [username, setUsername] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [tempResult, setTempResult] = useState<{ id: number; username: string; password: string } | null>(null)

  useEffect(() => {
    if (!open) return
    setErrors({})
    setTempResult(null)
    if (teacher) {
      setForm({
        first_name: teacher.first_name, last_name: teacher.last_name, title: teacher.title ?? '', specialty: teacher.specialty ?? '',
        phone: teacher.phone ?? '', whatsapp_phone: teacher.whatsapp_phone ?? '', email: teacher.email ?? '', color: teacher.color,
        hired_on: teacher.hired_on ?? '', employment_type: teacher.employment_type, hourly_rate: teacher.hourly_rate ?? '',
        max_weekly_hours: teacher.max_weekly_hours ?? '', notes: teacher.notes ?? '',
      })
      setSubjectIds(teacher.subject_ids)
      setCreateUser(false)
      setUsername('')
    } else {
      setForm({ employment_type: 'full_time', color: 'indigo' })
      setSubjectIds([])
      setCreateUser(false)
      setUsername('')
    }
  }, [open, teacher])

  const set = (key: string, value: unknown) => setForm((f) => ({ ...f, [key]: value }))
  const err = (key: string) => errors[key]?.[0]

  const save = useMutation({
    mutationFn: () => {
      const payload: Record<string, unknown> = { ...form, subject_ids: subjectIds, create_user: createUser, username: username || undefined }
      Object.keys(payload).forEach((k) => payload[k] === '' && delete payload[k])
      return editing
        ? api.put<{ teacher: TeacherDetail; temp_password: string | null }>(`/teachers/${teacher!.id}`, payload)
        : api.post<{ teacher: TeacherDetail; temp_password: string | null }>('/teachers', payload)
    },
    onSuccess: (res) => {
      if (res.temp_password) {
        setTempResult({ id: res.teacher.id, username: res.teacher.user?.username ?? (username || res.teacher.first_name), password: res.temp_password })
        toast.success('Öğretmen kaydedildi. Geçici şifreyi not edin — bir daha gösterilmeyecek.')
        return
      }
      toast.success(editing ? 'Öğretmen güncellendi.' : 'Öğretmen oluşturuldu.')
      onClose()
      onSaved(res.teacher.id)
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        setErrors(e.errors)
        toast.error(e.firstError())
      }
    },
  })

  if (tempResult) {
    const finish = () => { onClose(); onSaved(tempResult.id) }
    return (
      <Drawer open={open} onClose={finish} width={480} title="Portal hesabı oluşturuldu">
        <Alert tone="warning" title="Bu şifre yalnızca bir kez gösterilir">
          Kullanıcı adı: <strong>{tempResult.username}</strong>
          <div className="mt-2 rounded-[var(--radius-md)] bg-surface-2 px-3 py-2 font-mono text-[15px] tracking-wide">{tempResult.password}</div>
          <p className="mt-2 text-[12.5px] text-ink-3">Öğretmen ilk girişte bu şifreyi değiştirmek zorunda kalacak.</p>
        </Alert>
        <div className="mt-4 flex justify-end">
          <Button variant="primary" onClick={finish}>Tamam</Button>
        </div>
      </Drawer>
    )
  }

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width={600}
      title={editing ? 'Öğretmeni düzenle' : 'Yeni öğretmen'}
      description={editing ? teacher?.full_name : 'Kişisel bilgiler, dersler ve isteğe bağlı sistem kullanıcısı'}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Vazgeç</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>{editing ? 'Kaydet' : 'Öğretmeni oluştur'}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-7">
        <p className="-mb-3 text-[12.5px] text-ink-3"><span className="text-danger">*</span> işaretli alanlar zorunludur; diğerleri isteğe bağlıdır.</p>
        <Section title="Kişisel bilgiler">
          <Field label="Ad" required error={err('first_name')}><Input value={form.first_name ?? ''} onChange={(e) => set('first_name', e.target.value)} autoFocus /></Field>
          <Field label="Soyad" required error={err('last_name')}><Input value={form.last_name ?? ''} onChange={(e) => set('last_name', e.target.value)} /></Field>
          <Field label="Unvan" optional error={err('title')}><Input value={form.title ?? ''} onChange={(e) => set('title', e.target.value)} placeholder="Matematik Öğretmeni" /></Field>
          <Field label="Uzmanlık alanı" optional error={err('specialty')}><Input value={form.specialty ?? ''} onChange={(e) => set('specialty', e.target.value)} /></Field>
          <Field label="Cep telefonu" optional error={err('phone')}><Input type="tel" value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} placeholder="05xx xxx xx xx" /></Field>
          <Field label="WhatsApp numarası" optional hint="Cep telefonundan farklıysa" error={err('whatsapp_phone')}><Input type="tel" value={form.whatsapp_phone ?? ''} onChange={(e) => set('whatsapp_phone', e.target.value)} placeholder="05xx xxx xx xx" /></Field>
          <Field label="E-posta" optional error={err('email')}><Input type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} /></Field>
          <Field label="İşe başlama tarihi" optional error={err('hired_on')}><Input type="date" value={form.hired_on ?? ''} onChange={(e) => set('hired_on', e.target.value)} /></Field>
        </Section>

        <Section title="Branşlar (verdiği dersler)">
          <p className="sm:col-span-2 -mt-1 text-[12.5px] text-ink-3">Birden çok seçilebilir. Ders programı botu yalnız seçili derslere atar.</p>
          <div className="sm:col-span-2 flex flex-wrap gap-2">
            {(options?.subjects ?? []).map((s) => {
              const on = subjectIds.includes(s.id)
              return (
                <button
                  key={s.id}
                  type="button"
                  onClick={() => setSubjectIds((ids) => (on ? ids.filter((x) => x !== s.id) : [...ids, s.id]))}
                  className={on ? 'h-7 rounded-full px-3 text-[12.5px] font-medium bg-primary-soft text-primary-ink ring-1 ring-primary/20' : 'h-7 rounded-full px-3 text-[12.5px] text-ink-2 ring-1 ring-line hover:bg-surface-2'}
                >
                  {s.name}
                </button>
              )
            })}
          </div>
        </Section>

        <Section title="Çalışma koşulları">
          <Field label="Çalışma türü" required error={err('employment_type')}><Select value={form.employment_type ?? 'full_time'} onChange={(e) => set('employment_type', e.target.value)} options={Object.entries(options?.employment_types ?? {}).map(([value, label]) => ({ value, label }))} /></Field>
          <Field label="Takvim rengi" optional hint="Ders programında öğretmenin rengi"><Select value={form.color ?? 'indigo'} onChange={(e) => set('color', e.target.value)} options={(options?.colors ?? []).map((c) => ({ value: c, label: COLOR_TR[c] ?? c }))} /></Field>
          <Field label="Ders saati ücreti (₺)" optional error={err('hourly_rate')}><Input type="number" min={0} value={form.hourly_rate ?? ''} onChange={(e) => set('hourly_rate', e.target.value)} /></Field>
          <Field label="Haftalık en fazla ders saati" optional error={err('max_weekly_hours')}><Input type="number" min={0} max={168} value={form.max_weekly_hours ?? ''} onChange={(e) => set('max_weekly_hours', e.target.value)} /></Field>
        </Section>

        <Section title="Notlar">
          <Field label="Not" optional error={err('notes')} className="sm:col-span-2"><Textarea rows={2} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></Field>
        </Section>

        {!teacher?.user && (
          <div className="rounded-[var(--radius-lg)] bg-surface-2/60 ring-1 ring-line p-4">
            <Switch checked={createUser} onChange={setCreateUser} label={<span className="font-medium">Öğretmen portalı hesabı oluştur <span className="font-normal text-ink-3">(isteğe bağlı)</span></span>} />
            {createUser && (
              <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 animate-fade-in">
                <Field label="Kullanıcı adı" optional error={err('username')} hint="Boş bırakılırsa isimden üretilir"><Input value={username} onChange={(e) => setUsername(e.target.value)} placeholder="ad.soyad" /></Field>
                <p className="sm:col-span-2 text-[12.5px] text-ink-3">Geçici bir şifre üretilir ve bir kez gösterilir. Öğretmen, öğretmen portalına bu kullanıcı adıyla girer; ilk girişte şifresini değiştirmesi zorunludur.</p>
              </div>
            )}
          </div>
        )}
      </div>
    </Drawer>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <h3 className="mb-3 text-[13px] font-semibold uppercase tracking-[0.05em] text-ink-3">{title}</h3>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">{children}</div>
    </div>
  )
}

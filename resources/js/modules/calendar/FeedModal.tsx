import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Copy, Link2, Link2Off, Smartphone } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime } from '@/lib/format'
import { useCan } from '@/app/auth'
import { ConfirmDialog, Modal } from '@/components/ui/overlay'
import { Button } from '@/components/ui/Button'
import { Field, Input, Segmented, Select } from '@/components/ui/form'
import { Alert, Skeleton } from '@/components/ui/feedback'

export type FeedOwner = { type: 'teacher' | 'student' | 'class_group' | 'classroom'; id: number; name: string }

type Pickable = 'teacher' | 'class_group' | 'classroom'
export type FeedChoices = Record<Pickable, { id: number; name: string }[]>

const TYPE_LABEL: Record<Pickable, string> = { teacher: 'Öğretmen', class_group: 'Sınıf', classroom: 'Derslik' }

type Status = { active: boolean; created_at: string | null; last_accessed_at: string | null; access_count: number }

/**
 * iCal abonelik bağlantısı: telefonun takvim uygulamasına eklenir, program değiştikçe güncellenir.
 * Sahip (öğretmen/sınıf/derslik) takvim filtresinden gelir; yoksa pencerede seçilir. Etkin bağlantı iptal edilebilir.
 */
export function FeedModal({ owner: initial, choices, onClose }: { owner: FeedOwner | null; choices?: FeedChoices; onClose: () => void }) {
  const can = useCan()
  const [type, setType] = useState<Pickable>(initial && initial.type !== 'student' ? initial.type : 'teacher')
  const [picked, setPicked] = useState<number>(initial?.id ?? 0)
  const owner: FeedOwner | null = initial && initial.type === 'student' ? initial
    : picked ? { type, id: picked, name: choices?.[type].find((x) => x.id === picked)?.name ?? initial?.name ?? '' } : null

  const [link, setLink] = useState<{ url: string; webcal_url: string } | null>(null)
  const [revoking, setRevoking] = useState(false)
  const status = useQuery({
    queryKey: ['calendar', 'feed-status', owner?.type, owner?.id],
    queryFn: () => api.get<Status>('/calendar/feeds', { owner_type: owner!.type, owner_id: owner!.id }),
    enabled: !!owner,
  })
  const create = useMutation({
    mutationFn: () => api.post<{ url: string; webcal_url: string; message: string }>('/calendar/feeds', { owner_type: owner!.type, owner_id: owner!.id }),
    onSuccess: (r) => {
      setLink(r)
      status.refetch()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Bağlantı oluşturulamadı.'),
  })
  const revoke = useMutation({
    mutationFn: () => api.post<{ message: string }>('/calendar/feeds/revoke', { owner_type: owner!.type, owner_id: owner!.id }),
    onSuccess: (r) => {
      toast.success(r.message)
      setLink(null)
      setRevoking(false)
      status.refetch()
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'İptal edilemedi.'),
  })
  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success('Bağlantı kopyalandı.')
    } catch {
      toast.error('Kopyalanamadı; bağlantıyı elle seçin.')
    }
  }
  const choose = (t: Pickable, id: number) => {
    setType(t)
    setPicked(id)
    setLink(null)
  }
  const list = choices?.[type] ?? []
  const st = status.data
  const ownerLabel = owner && owner.type !== 'student' ? TYPE_LABEL[owner.type].toLocaleLowerCase('tr-TR') : 'öğrenci'

  return (
    <>
      <Modal open onClose={onClose} title="Telefon takvimine ekle" description={owner ? `${owner.name} için ders programı aboneliği` : 'Ders programı aboneliği'}
        footer={<Button variant="primary" onClick={onClose}>Kapat</Button>}>
        <div className="flex flex-col gap-4 text-[13px]">
          <p className="text-ink-2">Bağlantı iPhone Takvim, Google Takvim ve Outlook’a abonelik olarak eklenir; iptal, derslik değişikliği ve telafiler kendiliğinden güncellenir (uygulamaya göre 1–12 saat).</p>

          {choices && initial?.type !== 'student' && (
            <div className="flex flex-col gap-2">
              <Segmented<Pickable> size="sm" className="self-start" value={type} onChange={(t) => choose(t, 0)}
                options={(Object.keys(TYPE_LABEL) as Pickable[]).map((t) => ({ value: t, label: TYPE_LABEL[t] }))} />
              <Field label={`${TYPE_LABEL[type]} seçin`}>
                <Select value={picked || ''} onChange={(e) => choose(type, Number(e.target.value) || 0)} placeholder={list.length ? 'Seçin' : 'Kayıt yok'} disabled={!list.length}
                  options={list.map((x) => ({ value: x.id, label: x.name }))} />
              </Field>
            </div>
          )}

          {!owner ? (
            <Alert tone="neutral">Bağlantı oluşturmak için bir {TYPE_LABEL[type].toLocaleLowerCase('tr-TR')} seçin.</Alert>
          ) : status.isLoading ? (
            <Skeleton className="h-16" />
          ) : !link ? (
            <>
              {st?.active ? (
                <Alert tone="info" title="Etkin bağlantı var">
                  {st.created_at ? `${dateTime(st.created_at)} tarihinde oluşturuldu` : 'Oluşturulmuş'}
                  {st.access_count ? ` · ${st.access_count} kez eşitlendi${st.last_accessed_at ? `, son ${dateTime(st.last_accessed_at)}` : ''}` : ' · henüz eşitlenmedi'}.
                  {' '}Güvenlik gereği bağlantı yeniden gösterilmez; yenisini oluşturursanız eskisi çalışmaz.
                </Alert>
              ) : (
                <Alert tone="neutral">Bu {ownerLabel} için etkin bağlantı yok.</Alert>
              )}
              <div className="flex flex-wrap gap-2">
                <Button variant="primary" icon={<Link2 className="size-4" />} loading={create.isPending} onClick={() => create.mutate()}>{st?.active ? 'Yeni bağlantı oluştur' : 'Bağlantı oluştur'}</Button>
                {st?.active && can('schedule.manage') && <Button variant="danger-soft" icon={<Link2Off className="size-4" />} onClick={() => setRevoking(true)}>Bağlantıyı iptal et</Button>}
              </div>
            </>
          ) : (
            <>
              <div className="flex gap-2">
                <Input readOnly value={link.url} onFocus={(e) => e.currentTarget.select()} className="font-mono text-[12px]" />
                <Button size="icon" aria-label="Kopyala" onClick={() => copy(link.url)}><Copy className="size-4" /></Button>
              </div>
              <a href={link.webcal_url} className="inline-flex items-center gap-2 self-start rounded-[var(--radius-sm)] border border-line px-3 py-2 font-medium hover:bg-surface-2"><Smartphone className="size-4" />Bu cihazda takvime ekle</a>
              <ul className="flex flex-col gap-1.5 text-[12.5px] text-ink-2">
                <li><span className="font-medium text-ink">iPhone:</span> Ayarlar → Takvim → Hesaplar → Hesap ekle → Diğer → Takvim aboneliği ekle → bağlantıyı yapıştırın.</li>
                <li><span className="font-medium text-ink">Google Takvim:</span> bilgisayarda Diğer takvimler → + → URL ile ekle → bağlantıyı yapıştırın.</li>
              </ul>
              <p className="text-[12px] text-ink-3">Bağlantı gizli bir anahtar içerir; yalnızca ilgili kişiyle paylaşın. Bu pencere kapanınca bağlantı bir daha gösterilmez.</p>
              {can('schedule.manage') && <Button size="sm" variant="danger-soft" className="self-start" icon={<Link2Off className="size-4" />} onClick={() => setRevoking(true)}>Bağlantıyı iptal et</Button>}
            </>
          )}
        </div>
      </Modal>
      <ConfirmDialog
        open={revoking}
        onClose={() => setRevoking(false)}
        onConfirm={() => revoke.mutate()}
        loading={revoke.isPending}
        danger
        title="Takvim bağlantısını iptal et"
        confirmLabel="İptal et"
        description={`${owner?.name ?? ''} için verilen takvim bağlantısı çalışmaz hale gelir; telefondaki abonelik artık güncellenmez.`}
      />
    </>
  )
}

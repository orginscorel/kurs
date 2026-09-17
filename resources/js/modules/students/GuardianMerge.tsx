import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowLeftRight, ArrowRight, CheckCircle2, Combine, Contact, KeyRound, TriangleAlert, Wallet } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { date, num, relative } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel } from '@/components/ui/layout'
import { Alert, Avatar, Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/form'

type DupGuardian = {
  id: number
  name: string
  phone: string | null
  email: string | null
  created_at: string | null
  students: { id: number; full_name: string; status: string }[]
  portal: { is_active: boolean; last_login_at: string | null } | null
}
type DupGroup = { key: string; guardians: DupGuardian[] }

type Side = {
  id: number
  name: string
  phone: string | null
  email: string | null
  occupation: string | null
  created_at: string | null
  user_id: number | null
  portal: { is_active: boolean; last_login_at: string | null } | null
  students: { id: number; full_name: string; relationship: string | null; is_primary: boolean }[]
}
type Preview = {
  target: Side
  source: Side
  students: { student_id: number; student: string; relationship: string | null; action: 'move' | 'merge' }[]
  counts: { key: string; label: string; count: number; financial: boolean }[]
  financial_total: number
  fills: { field: string; label: string }[]
  account: 'move' | 'deactivate_source' | 'none'
  warnings: string[]
  blocking: boolean
  fingerprint: string
}

const accountText: Record<Preview['account'], string> = {
  move: 'Birleşen velinin portal hesabı kalan veliye geçer (kullanıcı adı ve şifre değişmez).',
  deactivate_source: 'İkisinin de portal hesabı var: kalan velinin hesabı kullanılır; birleşen velinin hesabı kapatılır, talepleri ve bildirimleri kalan hesaba taşınır.',
  none: 'Birleşen velinin portal hesabı yok; kalan velinin hesabı olduğu gibi kalır.',
}

/**
 * Mükerrer veli birleştirme: aynı telefonlu veli grupları → kalacak/birleşecek seçimi → önizleme → onay.
 * Birleşen kayıt silinmez (arşivlenir); işlem tek adımda yapılır ve denetim kaydına yazılır.
 */
export default function GuardianMerge() {
  const can = useCan()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const targetId = Number(params.get('hedef') || 0) || null
  const sourceId = Number(params.get('kaynak') || 0) || null
  const [agree, setAgree] = useState(false)

  const groups = useQuery({ queryKey: ['guardians', 'duplicates'], queryFn: () => api.get<{ data: DupGroup[]; meta: { groups: number; guardians: number } }>('/guardians-duplicates') })

  const setPair = (t: number | null, s: number | null) =>
    setParams((p) => {
      t ? p.set('hedef', String(t)) : p.delete('hedef')
      s ? p.set('kaynak', String(s)) : p.delete('kaynak')
      return p
    }, { replace: true })

  // Seçim yoksa ilk grubu öner: en eski kayıt kalır
  useEffect(() => {
    const first = groups.data?.data[0]
    if (!targetId && !sourceId && first && first.guardians.length >= 2) {
      const sorted = [...first.guardians].sort((a, b) => a.id - b.id)
      setPair(sorted[0]!.id, sorted[1]!.id)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groups.data])

  useEffect(() => setAgree(false), [targetId, sourceId])

  const ready = !!targetId && !!sourceId && targetId !== sourceId
  const preview = useQuery({
    queryKey: ['guardian-merge-preview', targetId, sourceId],
    queryFn: () => api.post<{ data: Preview }>('/guardians-merge/preview', { target_id: targetId, source_id: sourceId }).then((r) => r.data),
    enabled: ready && can('guardians.manage'),
    retry: false,
  })

  const merge = useMutation({
    mutationFn: () => api.post<{ message: string; data: { target_id: number } }>('/guardians-merge', { target_id: targetId, source_id: sourceId, fingerprint: preview.data?.fingerprint, confirm: true }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['guardians'] })
      qc.invalidateQueries({ queryKey: ['guardian'] })
      qc.invalidateQueries({ queryKey: ['student'] })
      navigate(`/veliler/${r.data.target_id}`)
    },
    onError: (e) => {
      toast.error(e instanceof ApiError ? e.firstError() : 'Birleştirilemedi.')
      if (e instanceof ApiError && e.status === 409) preview.refetch()
    },
  })

  const activeGroup = useMemo(() => groups.data?.data.find((g) => g.guardians.some((x) => x.id === targetId) && g.guardians.some((x) => x.id === sourceId)), [groups.data, targetId, sourceId])

  if (!can('guardians.manage')) {
    return <EmptyState icon={<Contact />} title="Bu ekran için veli yönetimi yetkisi gerekir" action={<ButtonLink to="/veliler">Velilere dön</ButtonLink>} />
  }

  const p = preview.data

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Mükerrer veli birleştirme"
        breadcrumbs={[{ label: 'Veliler', to: '/veliler' }, { label: 'Birleştirme' }]}
        description="Aynı telefon numarasıyla iki kez açılmış veli kayıtlarını tek kayıtta toplayın"
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)] items-start">
        {/* Gruplar */}
        <Panel title="Olası mükerrerler" description={groups.data ? `${num(groups.data.meta.groups)} grup · ${num(groups.data.meta.guardians)} veli` : undefined} flush>
          {groups.isLoading ? (
            <Skeleton className="m-4 h-32" />
          ) : !groups.data?.data.length ? (
            <EmptyState compact icon={<CheckCircle2 />} title="Mükerrer veli bulunmadı" description="Aynı telefon numarasına sahip iki veli kaydı yok." />
          ) : (
            <ul className="max-h-[560px] overflow-y-auto scroll-thin">
              {groups.data.data.map((g) => {
                const active = g === activeGroup
                return (
                  <li key={g.key + g.guardians[0]?.id} className={cn('border-t border-line px-4 py-3', active && 'bg-primary-soft/40')}>
                    <div className="mb-1.5 flex items-center justify-between gap-2">
                      <span className="text-[12px] text-ink-3 tabular">Numara …{g.key.slice(-4)} · {g.guardians.length} kayıt</span>
                      {!active && (
                        <Button size="xs" variant="ghost" onClick={() => { const s = [...g.guardians].sort((a, b) => a.id - b.id); setPair(s[0]!.id, s[1]!.id) }}>Seç</Button>
                      )}
                    </div>
                    <ul className="flex flex-col gap-1.5">
                      {g.guardians.map((x) => {
                        const role = x.id === targetId ? 'target' : x.id === sourceId ? 'source' : null
                        return (
                          <li key={x.id} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]">
                            <span className="min-w-0 flex-1 truncate font-medium">{x.name}</span>
                            <span className="text-[12px] text-ink-3 whitespace-nowrap">Kayıt no {x.id} · {x.students.length} öğrenci</span>
                            {active && role === 'target' && <Badge tone="success">Kalacak</Badge>}
                            {active && role === 'source' && <Badge tone="warning">Birleşecek</Badge>}
                            {active && !role && (
                              <Button size="xs" variant="ghost" onClick={() => setPair(targetId, x.id)}>Birleşecek yap</Button>
                            )}
                          </li>
                        )
                      })}
                    </ul>
                  </li>
                )
              })}
            </ul>
          )}
        </Panel>

        {/* Önizleme */}
        <div className="flex min-w-0 flex-col gap-4">
          {!ready ? (
            <Panel><EmptyState compact icon={<Combine />} title="Birleştirilecek iki veli seçin" description="Soldaki listeden bir grup seçin." /></Panel>
          ) : preview.isLoading ? (
            <Skeleton className="h-80 rounded-[var(--radius-lg)]" />
          ) : preview.error ? (
            <Alert tone="danger" title="Önizleme açılamadı">{preview.error instanceof ApiError ? preview.error.firstError() : 'Bilinmeyen hata'}</Alert>
          ) : p ? (
            <>
              <div className="grid grid-cols-1 items-stretch gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                <SideCard side={p.source} role="source" />
                <div className="flex items-center justify-center gap-2 md:flex-col">
                  <ArrowRight className="size-5 rotate-90 text-ink-3 md:rotate-0" />
                  <Button size="xs" variant="ghost" icon={<ArrowLeftRight className="size-3.5" />} onClick={() => setPair(sourceId, targetId)}>Yer değiştir</Button>
                </div>
                <SideCard side={p.target} role="target" />
              </div>

              {p.warnings.length > 0 && (
                <Alert tone={p.blocking ? 'danger' : 'warning'} title={p.blocking ? 'Bu iki kayıt birleştirilemez' : 'Kontrol edin'}>
                  <ul className="list-disc pl-4">{p.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                </Alert>
              )}

              <Panel title="Ne taşınacak?" flush>
                <div className="grid grid-cols-1 divide-y divide-line md:grid-cols-2 md:divide-x md:divide-y-0">
                  <div className="px-4 py-3">
                    <p className="mb-2 text-[12.5px] font-semibold text-ink-2">Öğrenci bağları</p>
                    {p.students.length === 0 ? <p className="text-[13px] text-ink-3">Bağlı öğrenci yok.</p> : (
                      <ul className="flex flex-col gap-1.5 text-[13px]">
                        {p.students.map((s) => (
                          <li key={s.student_id} className="flex flex-wrap items-center gap-2">
                            <Link to={`/ogrenciler/${s.student_id}`} className="min-w-0 flex-1 truncate hover:underline">{s.student}</Link>
                            <Badge tone={s.action === 'move' ? 'info' : 'neutral'}>{s.action === 'move' ? 'Taşınır' : 'Zaten bağlı · birleşir'}</Badge>
                          </li>
                        ))}
                      </ul>
                    )}
                    <p className="mt-3 mb-1.5 text-[12.5px] font-semibold text-ink-2 flex items-center gap-1.5"><KeyRound className="size-3.5" /> Portal hesabı</p>
                    <p className="text-[13px] text-ink-2">{accountText[p.account]}</p>
                    {p.fills.length > 0 && (
                      <>
                        <p className="mt-3 mb-1 text-[12.5px] font-semibold text-ink-2">Kalan kayıtta boş olup doldurulacak alanlar</p>
                        <p className="text-[13px] text-ink-2">{p.fills.map((f) => f.label).join(', ')}</p>
                      </>
                    )}
                  </div>
                  <div className="px-4 py-3">
                    <p className="mb-2 flex items-center gap-1.5 text-[12.5px] font-semibold text-ink-2"><Wallet className="size-3.5" /> Kayıtlar</p>
                    <table className="w-full text-[13px]">
                      <tbody>
                        {p.counts.filter((c) => c.count > 0).map((c) => (
                          <tr key={c.key} className="border-b border-line last:border-0">
                            <td className="py-1.5 pr-2 text-left">{c.label}{c.financial && <Badge tone="accent" className="ml-1.5">Finans</Badge>}</td>
                            <td className="py-1.5 font-medium tabular text-center">{num(c.count)}</td>
                          </tr>
                        ))}
                        {p.counts.every((c) => c.count === 0) && (
                          <tr><td className="py-1.5 text-ink-3 text-left">Taşınacak finans/mesaj kaydı yok.</td></tr>
                        )}
                      </tbody>
                    </table>
                    {p.financial_total > 0 && (
                      <p className="mt-2 text-[12px] text-ink-3">
                        Fatura alıcı adı ve senet borçlu adı gibi belge üzerindeki yazılar değişmez; yalnız kaydın hangi veliye bağlı olduğu güncellenir.
                      </p>
                    )}
                  </div>
                </div>
              </Panel>

              <Panel>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                  <div className="min-w-0 flex-1">
                    <Checkbox
                      checked={agree}
                      onChange={setAgree}
                      disabled={p.blocking}
                      label={<span><b>{p.source.name}</b> kaydının <b>{p.target.name}</b> kaydına katılacağını ve arşivleneceğini onaylıyorum.</span>}
                    />
                    <p className="mt-1 pl-6 text-[12px] text-ink-3">İşlem tek adımda yapılır ve denetim kayıtlarına yazılır. Birleşen kayıt silinmez; arşivde kalır.</p>
                  </div>
                  <Button variant="primary" icon={<Combine className="size-4" />} disabled={!agree || p.blocking} loading={merge.isPending} onClick={() => merge.mutate()}>
                    Birleştir
                  </Button>
                </div>
              </Panel>
            </>
          ) : null}
        </div>
      </div>
    </div>
  )
}

function SideCard({ side, role }: { side: Side; role: 'source' | 'target' }) {
  return (
    <div className={cn('min-w-0 rounded-[var(--radius-lg)] bg-surface p-4 ring-1', role === 'target' ? 'ring-success/40' : 'ring-warning/40')}>
      <div className="mb-2 flex items-center justify-between gap-2">
        <Badge tone={role === 'target' ? 'success' : 'warning'}>{role === 'target' ? 'Kalacak kayıt' : 'Birleşecek (arşivlenir)'}</Badge>
        <span className="text-[12px] text-ink-3 tabular">Kayıt no {side.id}</span>
      </div>
      <div className="flex items-center gap-3">
        <Avatar name={side.name} size={36} />
        <div className="min-w-0">
          <Link to={`/veliler/${side.id}`} className="block truncate text-[14px] font-semibold hover:underline">{side.name}</Link>
          <p className="truncate text-[12px] text-ink-3 tabular">{side.phone ?? 'telefon yok'}{side.email ? ` · ${side.email}` : ''}</p>
        </div>
      </div>
      <dl className="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[12.5px]">
        <dt className="text-ink-3">Oluşturma</dt>
        <dd className="truncate">{side.created_at ? date(side.created_at) : '—'}</dd>
        <dt className="text-ink-3">Portal</dt>
        <dd className="truncate">
          {side.portal ? `${side.portal.is_active ? 'Aktif' : 'Pasif'} · ${side.portal.last_login_at ? `giriş ${relative(side.portal.last_login_at)}` : 'hiç giriş yok'}` : 'Hesap yok'}
        </dd>
        <dt className="text-ink-3">Öğrenciler</dt>
        <dd className="min-w-0">{side.students.length ? side.students.map((s) => s.full_name).join(', ') : '—'}</dd>
      </dl>
      {role === 'source' && side.portal?.is_active && (
        <p className="mt-2 flex items-center gap-1.5 text-[12px] text-warning"><TriangleAlert className="size-3.5 shrink-0" /> Bu hesap kullanılıyor; birleştirme sonrası durumu yukarıdaki açıklamaya göre değişir.</p>
      )}
    </div>
  )
}

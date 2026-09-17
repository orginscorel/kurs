import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Apple, Copy, KeyRound, Laptop, MonitorSmartphone, RefreshCw, ShieldOff, Smartphone } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { dateTime, num, relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, Skeleton, StatusDot } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import { ConfirmDialog } from '@/components/ui/overlay'
import { isOnline, type SyncDevice, type SyncOverview } from './types'

type Filter = 'aktif' | 'iptal' | 'tumu'

function PlatformIcon({ platform }: { platform: string }) {
  if (platform === 'android' || platform === 'ios') return <Smartphone className="size-4" />
  if (platform === 'macos') return <Apple className="size-4" />
  return <Laptop className="size-4" />
}

export default function DevicesPage() {
  const qc = useQueryClient()
  const can = useCan()
  const [filter, setFilter] = useState<Filter>('aktif')
  const [revoking, setRevoking] = useState<SyncDevice | null>(null)
  const [rotating, setRotating] = useState(false)

  const overview = useQuery({ queryKey: ['sync', 'overview'], queryFn: () => api.get<SyncOverview>('/sync/overview'), refetchInterval: 30_000 })
  const code = useQuery({ queryKey: ['sync', 'pairing-code'], queryFn: () => api.get<{ code: string; server_url: string }>('/sync/pairing-code') })
  const devices = useQuery({
    queryKey: ['sync', 'devices', filter],
    queryFn: () => api.get<{ data: SyncDevice[] }>('/sync/devices', { durum: filter }),
    refetchInterval: 30_000,
  })

  const revoke = useMutation({
    mutationFn: (d: SyncDevice) => api.post<{ message: string }>(`/sync/devices/${d.id}/revoke`),
    onSuccess: (r) => {
      toast.success(r.message)
      setRevoking(null)
      qc.invalidateQueries({ queryKey: ['sync'] })
    },
    onError: (e: ApiError) => toast.error(e.firstError()),
  })
  const rotate = useMutation({
    mutationFn: () => api.post<{ code: string; message: string }>('/sync/pairing-code/rotate'),
    onSuccess: (r) => {
      toast.success(r.message)
      setRotating(false)
      qc.invalidateQueries({ queryKey: ['sync', 'pairing-code'] })
    },
    onError: (e: ApiError) => toast.error(e.firstError()),
  })

  const columns = useMemo<Column<SyncDevice>[]>(
    () => [
      {
        key: 'name',
        header: 'Cihaz',
        cell: (d) => (
          <div className="flex items-center gap-2.5 min-w-0">
            <span className="grid size-8 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2 ring-1 ring-line">
              <PlatformIcon platform={d.platform} />
            </span>
            <div className="min-w-0">
              <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                <span className="min-w-0 break-words font-medium text-ink">{d.name}</span>
                <Badge tone="neutral" className="font-mono">{d.code}</Badge>
              </div>
              <div className="text-[12px] text-ink-3 truncate">
                {d.platform_label} · {d.mode === 'desktop' ? 'Yerel kurulum' : 'Mobil'}{d.app_version ? ` · v${d.app_version}` : ''}
              </div>
            </div>
          </div>
        ),
      },
      { key: 'user', header: 'Eşleştiren', mobileLabel: 'Kullanıcı', cell: (d) => <span className="text-ink-2">{d.user?.name ?? '—'}</span> },
      {
        key: 'seen',
        header: 'Son görülme',
        cell: (d) =>
          d.status === 'revoked' ? (
            <span className="text-ink-3">—</span>
          ) : (
            <span className="inline-flex items-center gap-1.5 whitespace-nowrap" title={dateTime(d.last_seen_at)}>
              <StatusDot tone={isOnline(d) ? 'success' : 'neutral'} pulse={isOnline(d)} />
              <span className={isOnline(d) ? 'text-ink' : 'text-ink-2'}>{isOnline(d) ? 'Çevrimiçi' : relative(d.last_seen_at)}</span>
            </span>
          ),
      },
      {
        key: 'sync',
        header: 'Son eşitleme',
        hideable: true,
        mobileHidden: true,
        cell: (d) => (
          <div className="text-[12.5px] leading-tight whitespace-nowrap">
            <div className="text-ink-2">Gönderim: <span className="text-ink">{d.last_push_at ? relative(d.last_push_at) : '—'}</span></div>
            <div className="text-ink-2">Çekme: <span className="text-ink">{d.last_pull_at ? relative(d.last_pull_at) : '—'}</span></div>
          </div>
        ),
      },
      {
        key: 'queue',
        header: 'Kuyruk',
        hideable: true,
        cell: (d) => (
          <div className="flex flex-wrap items-center gap-1">
            {d.pending_reported > 0 && <Badge tone="warning">{num(d.pending_reported)} bekleyen</Badge>}
            {d.behind > 0 && d.status === 'active' && <Badge tone="info">{num(d.behind)} geride</Badge>}
            {d.open_conflicts > 0 && <Badge tone="danger">{num(d.open_conflicts)} çakışma</Badge>}
            {d.rejected_total > 0 && <Badge tone="neutral">{num(d.rejected_total)} red</Badge>}
            {d.pending_reported === 0 && d.behind === 0 && d.open_conflicts === 0 && d.rejected_total === 0 && <span className="text-ink-3">Güncel</span>}
          </div>
        ),
      },
      {
        key: 'key',
        header: 'Veri anahtarı',
        hideable: true,
        defaultHidden: true,
        cell: (d) => (d.key_issued_at ? <span className="text-ink-2" title={dateTime(d.key_issued_at)}>Verildi</span> : <span className="text-ink-3">Yok</span>),
      },
      {
        key: 'status',
        header: 'Durum',
        cell: (d) => (d.status === 'active' ? <Badge tone="success" dot>Etkin</Badge> : <Badge tone="neutral" dot>İptal · {relative(d.revoked_at)}</Badge>),
      },
      {
        key: 'actions',
        header: '',
        align: 'right',
        cell: (d) =>
          d.status === 'active' && can('sync.manage') ? (
            <Button size="xs" variant="danger-soft" title="Cihazın eşitleme erişimini iptal et" icon={<ShieldOff className="size-3.5" />} onClick={(e) => { e.stopPropagation(); setRevoking(d) }}>
              İptal et
            </Button>
          ) : null,
      },
    ],
    [can],
  )

  const o = overview.data
  const copy = (text: string) => navigator.clipboard?.writeText(text).then(() => toast.success('Kopyalandı'), () => toast.error('Kopyalanamadı'))

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Bağlı cihazlar"
        description="Çevrimdışı çalışan masaüstü kurulumları ve mobil uygulamalar web ile iki yönlü eşitlenir."
        actions={
          <Button icon={<RefreshCw className="size-4" />} onClick={() => qc.invalidateQueries({ queryKey: ['sync'] })}>
            Yenile
          </Button>
        }
      />

      <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat label="Etkin cihaz" value={num(o?.devices_active ?? 0)} sub={`${num(o?.devices_online ?? 0)} tanesi çevrimiçi`} loading={overview.isLoading} icon={<MonitorSmartphone />} />
        <Stat label="Son 24 saatte değişiklik" value={num(o?.changes_24h ?? 0)} sub={`${num(o?.device_changes_24h ?? 0)} tanesi cihazlardan`} loading={overview.isLoading} />
        <Stat label="Açık çakışma" value={num(o?.open_conflicts ?? 0)} tone={(o?.open_conflicts ?? 0) > 0 ? 'warning' : undefined} to="/ayarlar/esitleme-cakismalari" sub="İncele" loading={overview.isLoading} />
        <Stat label="Finans mutabakatı" value={num(o?.finance_pending ?? 0)} tone={(o?.finance_pending ?? 0) > 0 ? 'danger' : undefined} to="/ayarlar/esitleme-cakismalari?tur=finance" sub="Bekleyen finans işlemi" loading={overview.isLoading} />
      </div>

      <div className="mb-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
        <Panel
          title="Yeni cihaz eşleştir"
          description="Masaüstü uygulamasında “Sunucuya bağlan” adımında bu bilgileri girin."
          actions={
            <Button size="sm" variant="ghost" icon={<KeyRound className="size-3.5" />} onClick={() => setRotating(true)}>
              Kodu yenile
            </Button>
          }
        >
          {code.isLoading ? (
            <Skeleton className="h-16 w-full" />
          ) : code.data ? (
            <dl className="grid gap-3 text-[13px]">
              <div>
                <dt className="text-ink-3">Kurum kodu</dt>
                <dd className="mt-1 flex flex-wrap items-center gap-2">
                  <span className="rounded-[var(--radius-sm)] bg-surface-2 px-3 py-1.5 font-mono text-[18px] sm:text-[20px] font-semibold tracking-[0.12em] text-ink ring-1 ring-line">{code.data.code}</span>
                  <Button size="icon-sm" variant="ghost" aria-label="Kurum kodunu kopyala" onClick={() => copy(code.data!.code)}><Copy className="size-3.5" /></Button>
                </dd>
              </div>
              <div>
                <dt className="text-ink-3">Sunucu adresi</dt>
                <dd className="mt-1 flex items-center gap-2 min-w-0">
                  <span className="min-w-0 break-all font-mono text-[12.5px] text-ink">{code.data.server_url}</span>
                  <Button size="icon-sm" variant="ghost" aria-label="Sunucu adresini kopyala" onClick={() => copy(code.data!.server_url)}><Copy className="size-3.5" /></Button>
                </dd>
              </div>
              <p className="text-[12px] leading-relaxed text-ink-3">
                Giriş, “Eşitleme” yetkisi olan bir personel hesabıyla yapılır. Öğrenci, veli ve öğretmen portalı hesapları cihaz eşleştiremez.
              </p>
            </dl>
          ) : null}
        </Panel>

        <Panel title="Nasıl çalışır?">
          <ul className="grid gap-2 text-[13px] leading-relaxed text-ink-2">
            <li><span className="font-medium text-ink">Yerel kurulum</span> internet yokken tam çalışır; bağlantı gelince değişiklikler otomatik gönderilir ve web'deki değişiklikler çekilir.</li>
            <li><span className="font-medium text-ink">Aynı alan iki yerde değişirse</span> son yazılan kazanır; eski değer kaybolmaz, “Eşitleme çakışmaları”nda görünür ve geri alınabilir.</li>
            <li><span className="font-medium text-ink">Finans işlemleri asla ezilmez.</span> Tahsilat, iade, gelir-gider, aktarım, POS yatışı ve senet web'de aynı numarayla yeniden işlenir; farklılık (ör. aynı taksite iki ödeme) mutabakat kuyruğuna düşer.</li>
            <li><span className="font-medium text-ink">Fotoğraf ve belgeler</span> (öğrenci fotoğrafı, ödev dosyaları, disiplin ekleri, logo) arka planda aktarılır; bağlantı koparsa kuyrukta bekler.</li>
            <li><span className="font-medium text-ink">Belge numaraları çakışmaz:</span> cihaz makbuz, iade ve senetleri cihaz kodunu taşır (ör. MKB-D2-…). Fatura ve parola değişikliği yalnız çevrimiçi yapılır.</li>
          </ul>
        </Panel>
      </div>

      <DataTable
        storageKey="sync-devices"
        columns={columns}
        rows={devices.data?.data}
        rowKey={(d) => d.id}
        loading={devices.isLoading}
        toolbar={
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <Segmented<Filter>
              value={filter}
              onChange={setFilter}
              options={[{ value: 'aktif', label: 'Etkin' }, { value: 'iptal', label: 'İptal edilen' }, { value: 'tumu', label: 'Tümü' }]}
            />
            {o?.last_sweep_at && <span className="min-w-0 break-words text-[12px] text-ink-3">Değişiklik günlüğü imleci {num(o.cursor)} · son tarama {relative(o.last_sweep_at)}</span>}
          </div>
        }
        empty={
          <EmptyState
            icon={<MonitorSmartphone />}
            title={filter === 'iptal' ? 'İptal edilmiş cihaz yok' : 'Henüz eşleşmiş cihaz yok'}
            description={filter === 'iptal' ? undefined : 'Masaüstü uygulamasını kurup yukarıdaki kurum koduyla eşleştirin.'}
          />
        }
      />

      <ConfirmDialog
        open={!!revoking}
        onClose={() => setRevoking(null)}
        onConfirm={() => revoking && revoke.mutate(revoking)}
        loading={revoke.isPending}
        danger
        title="Cihaz erişimi iptal edilsin mi?"
        confirmLabel="Erişimi iptal et"
        description={
          revoking ? (
            <>
              <span className="font-medium text-ink">{revoking.name}</span> ({revoking.code}) artık eşitleme yapamaz. Cihazda gönderilmemiş
              {revoking.pending_reported > 0 ? ` ${num(revoking.pending_reported)} ` : ' '}değişiklik varsa web'e ulaşmaz; tekrar kullanmak için yeniden eşleştirilmesi gerekir.
            </>
          ) : undefined
        }
      />
      <ConfirmDialog
        open={rotating}
        onClose={() => setRotating(false)}
        onConfirm={() => rotate.mutate()}
        loading={rotate.isPending}
        title="Kurum kodu yenilensin mi?"
        confirmLabel="Yeni kod oluştur"
        description="Eski kodla yeni cihaz eşleştirilemez. Mevcut eşleşmiş cihazlar etkilenmez."
      />
      <p className="mt-4 text-[12px] text-ink-3">
        Çakışmaları ve reddedilen değişiklikleri <Link to="/ayarlar/esitleme-cakismalari" className="text-primary hover:underline">Eşitleme çakışmaları</Link> ekranında inceleyin.
      </p>
    </div>
  )
}

import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertTriangle, CheckCircle2, Download, PlayCircle, RefreshCw, ShieldQuestion, Trash2, XCircle } from 'lucide-react'
import { api, ApiError, type Paginated } from '@/lib/api'
import { dateTime, relative } from '@/lib/format'
import { PageHeader, Panel, Stat, Tabs } from '@/components/ui/layout'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import type { BackupRun, FailedJob, HealthStatus, SystemHealth as SystemHealthData } from './types'
import { backupKindLabel, healthLabel, healthTone, integrationLabel } from './types'

type Tab = 'saglik' | 'yedekler' | 'geri-yukleme'

export default function SystemHealth() {
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) ?? 'saglik'
  const setTab = (t: Tab) => setParams((p) => { p.set('tab', t); return p })

  return (
    <div className="animate-fade-in">
      <PageHeader title="Sistem sağlığı" description="API, veritabanı, kuyruk, zamanlayıcı, depolama ve entegrasyon durumu" />
      <Tabs value={tab} onChange={setTab} className="mb-5" tabs={[{ value: 'saglik', label: 'Sağlık durumu' }, { value: 'yedekler', label: 'Yedekler' }, { value: 'geri-yukleme', label: 'Geri yükleme prosedürü' }]} />
      {tab === 'saglik' && <HealthTab />}
      {tab === 'yedekler' && <BackupsTab />}
      {tab === 'geri-yukleme' && <RestoreGuideTab />}
    </div>
  )
}

function StatusBadge({ status }: { status: HealthStatus }) {
  const icon = status === 'healthy' ? <CheckCircle2 className="size-3.5" /> : status === 'warning' ? <AlertTriangle className="size-3.5" /> : <XCircle className="size-3.5" />
  return (
    <Badge tone={healthTone[status]}>
      <span className="inline-flex items-center gap-1">{icon}{healthLabel[status]}</span>
    </Badge>
  )
}

function HealthTab() {
  const { data, isLoading, refetch, isFetching } = useQuery({ queryKey: ['system-health'], queryFn: () => api.get<SystemHealthData>('/system-health'), refetchInterval: 60_000 })

  if (isLoading || !data) return <Skeleton className="h-64 w-full" />

  return (
    <div className="flex flex-col gap-4">
      <div className="flex justify-end"><Button size="sm" icon={<RefreshCw className={isFetching ? 'size-3.5 animate-spin' : 'size-3.5'} />} onClick={() => refetch()}>Yenile</Button></div>

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <Stat label="API yanıt süresi" value={`${data.api.response_ms} ms`} tone={healthTone[data.api.status]} />
        <Stat label="Veritabanı boyutu" value={data.database.size_mb ? `${data.database.size_mb} MB` : '—'} sub={`${data.database.table_count ?? 0} tablo`} tone={healthTone[data.database.status]} />
        <Stat label="Kuyruk" value={data.queue.pending} sub={`${data.queue.failed} başarısız`} tone={healthTone[data.queue.status]} />
        <Stat label="Depolama" value={data.storage.free_gb !== null ? `${data.storage.free_gb} GB boş` : '—'} sub={data.storage.free_percent !== null ? `%${data.storage.free_percent} boş` : undefined} tone={healthTone[data.storage.status]} />
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Panel title="Zamanlayıcı">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-[13.5px] text-ink">Son çalışma: {data.scheduler.last_run_at ? relative(data.scheduler.last_run_at) : 'Hiç çalışmadı'}</p>
              {data.scheduler.last_run_at && <p className="text-[12px] text-ink-3">{dateTime(data.scheduler.last_run_at)}</p>}
            </div>
            <StatusBadge status={data.scheduler.status} />
          </div>
        </Panel>

        <Panel title="Sürümler">
          <div className="flex items-center justify-between text-[13.5px] text-ink-2">
            <span>PHP {data.versions.php}</span>
            <span>Laravel {data.versions.laravel}</span>
          </div>
        </Panel>

        <Panel title="İletişim entegrasyonları">
          <div className="flex flex-col gap-2">
            {Object.entries(data.integrations).map(([key, v]) => (
              <div key={key} className="flex items-center justify-between text-[13.5px]">
                <span className="text-ink-2">{integrationLabel[key] ?? key}{v.provider ? ` · ${v.provider}` : ''}</span>
                <StatusBadge status={v.status} />
              </div>
            ))}
          </div>
        </Panel>

        <Panel title="Parmak izi / kart köprüsü cihazları">
          {data.devices.devices.length === 0 ? <EmptyState icon={<ShieldQuestion />} title="Cihaz kaydı yok" /> : (
            <div className="flex flex-col gap-2">
              {data.devices.devices.map((d) => (
                <div key={d.id} className="flex items-center justify-between text-[13.5px]">
                  <span className="text-ink-2">{d.name}</span>
                  <div className="flex items-center gap-2">
                    <span className="text-[12px] text-ink-3">{d.last_seen_at ? relative(d.last_seen_at) : 'Hiç bağlanmadı'}</span>
                    <Badge tone={d.online ? 'success' : 'neutral'} dot>{d.online ? 'Çevrimiçi' : 'Çevrimdışı'}</Badge>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Panel>
      </div>

      {data.queue.failed > 0 && <FailedJobsPanel />}
    </div>
  )
}

function FailedJobsPanel() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['system-health', 'failed-jobs'], queryFn: () => api.get<{ data: FailedJob[] }>('/system-health/failed-jobs') })
  const retry = useMutation({ mutationFn: (uuid: string) => api.post(`/system-health/failed-jobs/${uuid}/retry`), onSuccess: () => { toast.success('İş yeniden kuyruğa alındı.'); qc.invalidateQueries({ queryKey: ['system-health'] }) }, onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Başarısız.') })
  const del = useMutation({ mutationFn: (uuid: string) => api.delete(`/system-health/failed-jobs/${uuid}`), onSuccess: () => { toast.success('İş kaydı silindi.'); qc.invalidateQueries({ queryKey: ['system-health'] }) }, onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Başarısız.') })

  return (
    <Panel title="Başarısız kuyruk işleri">
      {!data || data.data.length === 0 ? <EmptyState title="Başarısız iş yok" /> : (
        <div className="flex flex-col divide-y divide-line">
          {data.data.map((j) => (
            <div key={j.id} className="flex items-center justify-between gap-3 py-2.5">
              <div className="min-w-0">
                <p className="text-[13px] text-ink-2 truncate">{j.exception}</p>
                <p className="text-[12px] text-ink-3">{j.queue} · {dateTime(j.failed_at)}</p>
              </div>
              <div className="flex items-center gap-1 shrink-0">
                <Button size="xs" icon={<RefreshCw className="size-3.5" />} onClick={() => retry.mutate(j.uuid)}>Yeniden dene</Button>
                <Button size="xs" variant="danger-soft" icon={<Trash2 className="size-3.5" />} onClick={() => del.mutate(j.uuid)} />
              </div>
            </div>
          ))}
        </div>
      )}
    </Panel>
  )
}

function BackupsTab() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: ['backups'], queryFn: () => api.get<Paginated<BackupRun>>('/backups'), refetchInterval: 15_000 })

  const run = useMutation({
    mutationFn: () => api.post<{ message: string }>('/backups/run'),
    onSuccess: (r) => { toast.success(r.message); qc.invalidateQueries({ queryKey: ['backups'] }) },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Başlatılamadı.'),
  })

  return (
    <Panel title="Yedekler" actions={<Button size="sm" variant="primary" icon={<PlayCircle className="size-3.5" />} loading={run.isPending} onClick={() => run.mutate()}>Manuel yedek al</Button>}>
      {isLoading || !data ? <Skeleton className="h-40 w-full" /> : data.data.length === 0 ? <EmptyState title="Henüz yedek alınmadı" /> : (
        <div className="overflow-x-auto scroll-thin">
          <table className="tbl w-full text-[13px]">
            <thead>
              <tr className="text-left text-ink-3 border-b border-line">
                <th className="py-2 pr-3 font-medium text-left">Tarih</th><th className="py-2 pr-3 font-medium text-center">Tür</th><th className="py-2 pr-3 font-medium text-center">Durum</th>
                <th className="py-2 pr-3 font-medium text-center">Boyut</th><th className="py-2 pr-3 font-medium text-center">Süre</th><th className="fill py-2 pr-3 font-medium text-center">Sağlama (SHA-256)</th><th className="py-2 pr-3 text-center"></th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((b) => (
                <tr key={b.id} className="border-b border-line last:border-0">
                  <td className="py-2 pr-3 tabular text-left">{dateTime(b.started_at)}</td>
                  <td className="py-2 pr-3 text-center">{backupKindLabel[b.kind] ?? b.kind}</td>
                  <td className="py-2 pr-3 text-center"><Badge tone={b.status === 'success' ? 'success' : b.status === 'failed' ? 'danger' : 'warning'}>{b.status === 'success' ? 'Başarılı' : b.status === 'failed' ? 'Başarısız' : 'Çalışıyor'}</Badge></td>
                  <td className="py-2 pr-3 tabular text-center">{b.size ? `${(b.size / 1024 / 1024).toFixed(1)} MB` : '—'}</td>
                  <td className="py-2 pr-3 tabular text-center">{b.duration_seconds !== null ? `${b.duration_seconds} sn` : '—'}</td>
                  <td className="fill py-2 pr-3 font-mono text-[12px] text-ink-3 text-center">{b.checksum ? b.checksum.slice(0, 16) + '…' : b.error ? <span className="text-danger">{b.error.slice(0, 60)}</span> : '—'}</td>
                  <td className="py-2 pr-3 text-right">{b.status === 'success' && <Button size="xs" variant="ghost" icon={<Download className="size-3.5" />} onClick={() => api.download(`/backups/${b.id}/download`, undefined, `yedek-${b.id}.sql.gz`)} />}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  )
}

function RestoreGuideTab() {
  return (
    <Panel title="Geri yükleme prosedürü" description="Otomatik geri yükleme düğmesi bilinçli olarak yoktur — veri kaybı riskine karşı bu adımlar teknik ekip tarafından elle uygulanır.">
      <ol className="flex flex-col gap-4 text-[13.5px] text-ink-2">
        {[
          ['İndirin', 'Ayarlar › Sistem Sağlığı › Yedekler sekmesinden geri yüklenecek yedeği indirin (.sql.gz).'],
          ['Bakım moduna alın', 'Sunucuda: php artisan down komutuyla uygulamayı bakım moduna alın; kullanıcılar geçici olarak erişemez.'],
          ['Güncel durumu yedekleyin', 'Geri yüklemeden ÖNCE mevcut veritabanının da bir yedeğini alın (php artisan kurs:backup --kind=manual) — bir hata olursa geri dönebilmek için.'],
          ['Dosyayı açın', 'gunzip ile .sql.gz dosyasını açın: gunzip yedek-dosyasi.sql.gz'],
          ['Veritabanına yükleyin', 'mysql -u [kullanıcı] -p [veritabanı_adı] < yedek-dosyasi.sql komutuyla içe aktarın. Bu işlem MEVCUT verilerin üzerine yazar.'],
          ['Doğrulayın', 'Uygulamaya giriş yaparak öğrenci/öğretmen sayıları ve son işlemlerin beklenen tarihte olduğunu kontrol edin.'],
          ['Bakım modunu kapatın', 'php artisan up komutuyla uygulamayı tekrar erişime açın.'],
          ['Denetim kaydı bırakın', 'Geri yükleme işlemini, nedenini ve saatini bir not olarak kurum içinde paylaşın.'],
        ].map(([title, desc], i) => (
          <li key={i} className="flex gap-3">
            <span className="shrink-0 grid size-6 place-items-center rounded-full bg-primary-soft text-primary-ink text-[12px] font-semibold">{i + 1}</span>
            <div>
              <p className="font-medium text-ink">{title}</p>
              <p className="text-ink-3">{desc}</p>
            </div>
          </li>
        ))}
      </ol>
    </Panel>
  )
}

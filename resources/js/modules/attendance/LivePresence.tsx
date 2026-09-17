import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { DoorOpen, Fingerprint, LogIn, LogOut, Radio, Search, UserCheck, X } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { duration, time } from '@/lib/format'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Avatar, Badge, EmptyState, Skeleton, StatusDot } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'
import { Input, Segmented } from '@/components/ui/form'
import { Modal } from '@/components/ui/overlay'
import { useDebounced } from '@/hooks/useListState'
import type { InsideStudent, LiveFeedItem, UnmatchedEvent } from './types'

type LiveResponse = { inside_count: number; inside: InsideStudent[]; server_time: string }
type FeedResponse = { data: LiveFeedItem[]; last_id: number; server_time: string }
export type StudentHit = { id: number; full_name: string; student_no: string; photo_url: string | null }

/** Ortak öğrenci arama kutusu: manuel giriş/çıkış, okutma eşleme, izin girişi, kimlik eşleme. */
export function StudentSearch({ onPick, placeholder = 'Öğrenci ara' }: { onPick: (s: StudentHit) => void; placeholder?: string }) {
  const [q, setQ] = useState('')
  const debounced = useDebounced(q, 250)
  const { data, isFetching } = useQuery({
    queryKey: ['students', 'quick-search', debounced],
    queryFn: () => api.get<{ data: StudentHit[] }>('/students', { q: debounced, per_page: 8 }),
    enabled: debounced.length >= 2,
  })

  return (
    <div>
      <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} leading={<Search />} autoFocus />
      {debounced.length >= 2 && (
        <div className="mt-2 max-h-56 overflow-y-auto scroll-thin rounded-[var(--radius-md)] ring-1 ring-line">
          {isFetching ? (
            <div className="p-3"><Skeleton className="h-4 w-full" /></div>
          ) : data?.data.length ? (
            data.data.map((s) => (
              <button
                key={s.id}
                type="button"
                onClick={() => onPick(s)}
                className="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-surface-2 transition-colors"
              >
                <Avatar name={s.full_name} src={s.photo_url} size={28} />
                <span className="min-w-0">
                  <span className="block truncate text-[13px] font-medium">{s.full_name}</span>
                  <span className="block text-[12px] text-ink-3 tabular">Öğrenci no: {s.student_no}</span>
                </span>
              </button>
            ))
          ) : (
            <p className="px-3 py-3 text-[12.5px] text-ink-3">Sonuç yok.</p>
          )}
        </div>
      )}
    </div>
  )
}

export default function LivePresence() {
  const qc = useQueryClient()
  const [manualOpen, setManualOpen] = useState(false)
  const [manualStudent, setManualStudent] = useState<StudentHit | null>(null)
  const [manualType, setManualType] = useState<'ENTRY' | 'EXIT'>('ENTRY')
  const [matchEvent, setMatchEvent] = useState<UnmatchedEvent | null>(null)
  const afterIdRef = useRef(0)
  const knownIds = useRef<Set<number>>(new Set())
  const [flashIds, setFlashIds] = useState<Set<number>>(new Set())
  const firstLoad = useRef(true)

  const live = useQuery({ queryKey: ['attendance', 'live'], queryFn: () => api.get<LiveResponse>('/attendance/live'), refetchInterval: 5000 })
  const unmatched = useQuery({ queryKey: ['attendance', 'live-unmatched'], queryFn: () => api.get<{ data: UnmatchedEvent[] }>('/attendance/live/unmatched'), refetchInterval: 8000 })

  const feed = useQuery({
    queryKey: ['attendance', 'live-feed'],
    queryFn: () => api.get<FeedResponse>('/attendance/live/feed', { after_id: afterIdRef.current }),
    refetchInterval: 4000,
  })

  useEffect(() => {
    if (!feed.data) return
    afterIdRef.current = feed.data.last_id

    if (firstLoad.current) {
      feed.data.data.forEach((e) => knownIds.current.add(e.id))
      firstLoad.current = false
      return
    }

    const fresh = feed.data.data.filter((e) => !knownIds.current.has(e.id))
    if (fresh.length === 0) return

    fresh.forEach((e) => {
      knownIds.current.add(e.id)
      if (e.student) {
        toast.success(`${e.student.full_name} kuruma ${e.event_type === 'ENTRY' ? 'giriş' : 'çıkış'} yaptı`, { icon: e.event_type === 'ENTRY' ? '🚪' : '👋' })
      }
    })
    setFlashIds(new Set(fresh.map((e) => e.id)))
    const t = setTimeout(() => setFlashIds(new Set()), 2500)
    qc.invalidateQueries({ queryKey: ['attendance', 'live'] })
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [feed.data])

  const manualMutation = useMutation({
    mutationFn: () => api.post('/attendance/live/manual', { student_id: manualStudent!.id, event_type: manualType }),
    onSuccess: () => {
      toast.success('Kaydedildi.')
      setManualOpen(false)
      setManualStudent(null)
      qc.invalidateQueries({ queryKey: ['attendance', 'live'] })
      qc.invalidateQueries({ queryKey: ['attendance', 'live-feed'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Kaydedilemedi.'),
  })

  const matchMutation = useMutation({
    mutationFn: (studentId: number) => api.post(`/attendance/live/unmatched/${matchEvent!.id}/match`, { student_id: studentId }),
    onSuccess: (res: any) => {
      toast.success(res.message)
      setMatchEvent(null)
      qc.invalidateQueries({ queryKey: ['attendance', 'live-unmatched'] })
      qc.invalidateQueries({ queryKey: ['attendance', 'live'] })
    },
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Eşleştirilemedi.'),
  })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Canlı Giriş/Çıkış"
        description={
          <span className="inline-flex items-center gap-2">
            <StatusDot tone="success" pulse /> Şu an kurumda olanlar · 4-5 saniyede bir güncellenir
          </span>
        }
        actions={
          <Button variant="primary" icon={<UserCheck className="size-4" />} onClick={() => setManualOpen(true)}>
            Manuel giriş/çıkış
          </Button>
        }
      />

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <Stat label="Şu an içeride" icon={<Radio />} loading={live.isLoading} value={live.data?.inside_count ?? 0} tone="success" />
        <Stat label="Eşleşmeyen okutma" icon={<Fingerprint />} loading={unmatched.isLoading} value={unmatched.data?.data.length ?? 0} tone={unmatched.data?.data.length ? 'warning' : undefined} />
      </div>

      <div className="mt-5 grid grid-cols-1 xl:grid-cols-5 gap-4">
        <Panel title="Kurumda olanlar" description={`${live.data?.inside_count ?? 0} kişi`} className="xl:col-span-2" flush>
          <div className="max-h-[560px] overflow-y-auto scroll-thin">
            {live.isLoading ? (
              <div className="p-4"><Skeleton className="h-64" /></div>
            ) : !live.data?.inside.length ? (
              <EmptyState compact icon={<DoorOpen />} title="Şu an kurumda kimse yok" />
            ) : (
              <ul>
                {live.data.inside.map((s) => (
                  <li key={s.id} className="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">
                    <Avatar name={s.full_name} size={32} />
                    <div className="min-w-0 flex-1">
                      <Link to={`/ogrenciler/${s.id}`} className="block truncate text-[13.5px] font-medium hover:text-primary">{s.full_name}</Link>
                      <p className="truncate text-[12px] text-ink-3">{s.class_group ?? '—'} · giriş {time(s.first_entry_at)}</p>
                    </div>
                    <Badge tone="success">{duration(s.minutes_inside)}</Badge>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Panel>

        <Panel title="Son olaylar" description="Canlı akış" className="xl:col-span-3" flush>
          <div className="max-h-[560px] overflow-y-auto scroll-thin">
            {feed.isLoading ? (
              <div className="p-4"><Skeleton className="h-64" /></div>
            ) : !feed.data?.data.length ? (
              <EmptyState compact icon={<Radio />} title="Henüz olay yok" description="Bugüne ait bir giriş/çıkış olayı bulunmuyor." />
            ) : (
              <ul>
                {[...feed.data.data].reverse().map((e) => (
                  <li
                    key={e.id}
                    className={`flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-0 transition-colors duration-700 ${flashIds.has(e.id) ? 'bg-primary-soft/50' : ''}`}
                  >
                    <Avatar name={e.student?.full_name ?? '?'} size={30} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[13.5px] font-medium">{e.student?.full_name ?? 'Bilinmeyen'}</p>
                      <p className="text-[12px] text-ink-3">{e.device ?? e.source} · {time(e.occurred_at)}</p>
                    </div>
                    {e.event_type === 'ENTRY' ? (
                      <Badge tone="success" dot><LogIn className="size-3" /> Giriş</Badge>
                    ) : (
                      <Badge tone="neutral" dot><LogOut className="size-3" /> Çıkış</Badge>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Panel>
      </div>

      <div className="mt-4">
        <Panel
          title="Eşleşmeyen okutmalar"
          description="Cihazın tanımadığı parmak izi / kart okutmaları"
          flush
        >
          {unmatched.isLoading ? (
            <div className="p-4"><Skeleton className="h-24" /></div>
          ) : !unmatched.data?.data.length ? (
            <EmptyState compact icon={<Fingerprint />} title="Eşleşmeyen okutma yok" description="Tüm okutmalar bir öğrenciyle eşleşiyor." />
          ) : (
            <div className="overflow-x-auto scroll-thin">
              <table className="tbl w-full text-[13px]">
                <thead>
                  <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                    <th className="px-4 h-9 font-medium text-left">Zaman</th>
                    <th className="px-3 font-medium text-center">Cihaz</th>
                    <th className="fill px-3 font-medium text-center">Tanımlayıcı</th>
                    <th className="px-4 h-9 font-medium text-center">İşlem</th>
                  </tr>
                </thead>
                <tbody>
                  {unmatched.data.data.map((u) => (
                    <tr key={u.id} className="border-b border-line last:border-0">
                      <td className="px-4 py-2.5 tabular whitespace-nowrap text-left">{time(u.occurred_at)}</td>
                      <td className="px-3 py-2.5 text-center">{u.device?.name ?? '—'}</td>
                      <td className="fill px-3 py-2.5 font-mono text-[12px] text-ink-2 text-center">{u.raw_identifier}</td>
                      <td className="px-4 py-2.5 text-right">
                        <Button size="sm" variant="soft" onClick={() => setMatchEvent(u)}>Öğrenciye eşle</Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>

      <Modal
        open={manualOpen}
        onClose={() => { setManualOpen(false); setManualStudent(null) }}
        title="Manuel giriş/çıkış"
        description="Cihazı okutamayan bir öğrenci için elle kayıt oluşturun."
        footer={
          <>
            <Button variant="ghost" onClick={() => setManualOpen(false)}>Vazgeç</Button>
            <Button variant="primary" disabled={!manualStudent} loading={manualMutation.isPending} onClick={() => manualMutation.mutate()}>Kaydet</Button>
          </>
        }
      >
        {manualStudent ? (
          <div className="flex items-center gap-3 rounded-[var(--radius-md)] ring-1 ring-line px-3 py-2.5">
            <Avatar name={manualStudent.full_name} src={manualStudent.photo_url} size={32} />
            <div className="min-w-0 flex-1">
              <p className="truncate text-[13.5px] font-medium">{manualStudent.full_name}</p>
              <p className="text-[12px] text-ink-3 tabular">Öğrenci no: {manualStudent.student_no}</p>
            </div>
            <Button size="icon-sm" variant="ghost" onClick={() => setManualStudent(null)} aria-label="Değiştir"><X className="size-4" /></Button>
          </div>
        ) : (
          <StudentSearch onPick={setManualStudent} />
        )}
        <div className="mt-4">
          <Segmented
            value={manualType}
            onChange={setManualType}
            options={[
              { value: 'ENTRY', label: <span className="inline-flex items-center gap-1.5"><LogIn className="size-3.5" /> Giriş</span> },
              { value: 'EXIT', label: <span className="inline-flex items-center gap-1.5"><LogOut className="size-3.5" /> Çıkış</span> },
            ]}
          />
        </div>
      </Modal>

      <Modal
        open={matchEvent !== null}
        onClose={() => setMatchEvent(null)}
        title="Öğrenciye eşle"
        description={matchEvent ? `Tanımlayıcı: ${matchEvent.raw_identifier}` : undefined}
      >
        <StudentSearch onPick={(s) => matchMutation.mutate(s.id)} />
        {matchMutation.isPending && <p className="mt-2 text-[12.5px] text-ink-3">Eşleştiriliyor…</p>}
      </Modal>
    </div>
  )
}

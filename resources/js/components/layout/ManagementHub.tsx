import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { LayoutGrid, Plus, Search, X } from 'lucide-react'
import { useCan } from '@/app/auth'
import { commandActions, type CommandAction } from '@/app/commands'
import { Kbd } from '@/components/ui/feedback'

/**
 * Yönetim Paneli — tüm modüllerin komutlarını (commands.ts) tek yerde, kategorili ve aranabilir
 * gösterir. Sidebar'a sığmayan işlemlerin birincil keşif noktası. ⌘K paletiyle AYNI kaynağı kullanır.
 */

/** "Oluştur/Ekle" niteliği taşıyan komut kimlikleri — üstteki "Hızlı oluştur" şeridinde öne çıkar. */
const CREATE_IDS = new Set([
  'student-new', 'teacher-new', 'crm-new-lead', 'exam-new', 'schedule-new', 'class-group-new', 'subject-new',
  'finance-enroll', 'package-new', 'finance-collect', 'finance-expense', 'finance-income', 'finance-invoice-new',
  'homework-new', 'guidance-new-meeting', 'coaching-new-session', 'discipline-new', 'discipline-positive',
  'study-new', 'campaign-new', 'notification-send', 'whatsapp-send', 'classroom-design-new',
  'announcement-publish', 'placement-change', 'attendance-take', 'optical-upload', 'settings-backup',
])

/** Komut yolunun (to) ilk segmentine göre kategori. */
const SEG_GROUP: Record<string, string> = {
  '': 'general', pano: 'general', ozet: 'general', takvim: 'general', ajanda: 'general', hesabim: 'general', yenilikler: 'general', masaustu: 'general',
  ogrenciler: 'students',
  veliler: 'guardians',
  ogretmenler: 'teachers', personel: 'teachers',
  'on-kayit': 'crm', adaylar: 'crm', gorevlerim: 'crm',
  'ders-programi': 'academic', 'program-botu': 'academic', siniflar: 'academic', akademik: 'academic', etut: 'academic', odevler: 'academic', 'derslik-tasarimi': 'academic',
  kocluk: 'coaching',
  sinavlar: 'exams', deneme: 'exams',
  yoklama: 'attendance', pdks: 'attendance',
  rehberlik: 'guidance',
  disiplin: 'discipline',
  finans: 'finance',
  iletisim: 'communication', 'toplu-gonderim': 'communication', duyurular: 'communication', otomasyonlar: 'communication', kampanyalar: 'communication',
  raporlar: 'reports',
  ayarlar: 'settings', entegrasyonlar: 'settings', kullanicilar: 'settings', roller: 'settings', okullar: 'settings', esitleme: 'settings', cihazlar: 'settings',
}

const GROUP_META: Record<string, { label: string; order: number }> = {
  general: { label: 'Genel', order: 1 },
  students: { label: 'Öğrenciler', order: 2 },
  guardians: { label: 'Veliler', order: 3 },
  teachers: { label: 'Öğretmenler', order: 4 },
  crm: { label: 'Ön kayıt · CRM', order: 5 },
  academic: { label: 'Akademik · Ders programı', order: 6 },
  coaching: { label: 'Koçluk', order: 7 },
  exams: { label: 'Sınavlar', order: 8 },
  attendance: { label: 'Yoklama', order: 9 },
  guidance: { label: 'Rehberlik', order: 10 },
  discipline: { label: 'Disiplin', order: 11 },
  finance: { label: 'Finans · Paketler', order: 12 },
  communication: { label: 'İletişim', order: 13 },
  reports: { label: 'Raporlar', order: 14 },
  settings: { label: 'Ayarlar · Sistem', order: 15 },
  other: { label: 'Diğer', order: 16 },
}

function groupOf(to: string): string {
  const seg = to.replace(/^\//, '').split(/[/?#]/)[0] ?? ''
  return SEG_GROUP[seg] ?? 'other'
}

export function ManagementHub({ open, onClose }: { open: boolean; onClose: () => void }) {
  const can = useCan()
  const navigate = useNavigate()
  const [q, setQ] = useState('')

  useEffect(() => {
    if (!open) setQ('')
  }, [open])

  useEffect(() => {
    if (!open) return
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', esc)
    return () => window.removeEventListener('keydown', esc)
  }, [open, onClose])

  const allowed = useMemo(() => commandActions.filter((a) => can(a.permission)), [can])

  const ql = q.trim().toLocaleLowerCase('tr-TR')
  const actions = ql
    ? allowed.filter((a) => a.label.toLocaleLowerCase('tr-TR').includes(ql) || a.keywords?.some((k) => k.toLocaleLowerCase('tr-TR').includes(ql)))
    : allowed

  const creates = actions.filter((a) => CREATE_IDS.has(a.id))
  const groups = actions.reduce<Record<string, CommandAction[]>>((acc, a) => {
    ;(acc[groupOf(a.to)] ??= []).push(a)
    return acc
  }, {})
  const orderedKeys = Object.keys(groups).sort((x, y) => (GROUP_META[x]?.order ?? 99) - (GROUP_META[y]?.order ?? 99))

  if (!open) return null

  const go = (to: string) => {
    onClose()
    navigate(to)
  }

  const Tile = ({ a }: { a: CommandAction }) => (
    <button
      type="button"
      onClick={() => go(a.to)}
      className="group flex items-center gap-2.5 rounded-[var(--radius-md)] border border-line bg-surface px-3 py-2.5 text-left transition-colors hover:border-line-strong hover:bg-surface-2"
    >
      <span className="grid size-8 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2 group-hover:bg-primary-soft group-hover:text-primary-ink">
        <a.icon className="size-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-[13.5px] font-medium text-ink">{a.label}</span>
        {a.hint && <span className="block truncate text-[12px] text-ink-3">{a.hint}</span>}
      </span>
    </button>
  )

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-start justify-center px-3 pt-[8vh]">
      <div className="absolute inset-0 bg-black/30 backdrop-blur-[2px] animate-fade-in" onClick={onClose} />
      <div className="relative flex max-h-[84vh] w-full max-w-[900px] flex-col overflow-hidden rounded-[var(--radius-xl)] bg-surface ring-1 ring-line shadow-[var(--shadow-pop)] animate-slide-up">
        <div className="flex items-center gap-2.5 border-b border-line px-4 py-3">
          <span className="grid size-8 shrink-0 place-items-center rounded-[var(--radius-sm)] bg-primary-soft text-primary-ink"><LayoutGrid className="size-[18px]" /></span>
          <div className="min-w-0 flex-1">
            <p className="text-[15px] font-semibold leading-tight text-ink">Yönetim Paneli</p>
            <p className="truncate text-[12px] text-ink-3">Tüm işlemler tek yerde — ekle, yönet, aç</p>
          </div>
          <button type="button" onClick={onClose} className="grid size-8 place-items-center rounded-[var(--radius-sm)] text-ink-3 hover:bg-surface-2 hover:text-ink" aria-label="Kapat"><X className="size-4" /></button>
        </div>

        <div className="flex items-center gap-2.5 border-b border-line px-4">
          <Search className="size-[18px] text-ink-3" />
          <input
            autoFocus
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="İşlem ara: sınıf ekle, paket, tahsilat, bildirim…"
            className="h-12 flex-1 bg-transparent text-[14.5px] outline-none placeholder:text-ink-3"
          />
          <Kbd>Esc</Kbd>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto scroll-thin p-4">
          {actions.length === 0 ? (
            <p className="px-2 py-10 text-center text-[13.5px] text-ink-3">“{q}” için işlem bulunamadı.</p>
          ) : (
            <div className="flex flex-col gap-5">
              {creates.length > 0 && (
                <section>
                  <p className="mb-2 flex items-center gap-1.5 text-[12px] font-semibold uppercase tracking-[0.04em] text-ink-3"><Plus className="size-3.5" /> Hızlı oluştur</p>
                  <div className="flex flex-wrap gap-2">
                    {creates.map((a) => (
                      <button
                        key={a.id}
                        type="button"
                        onClick={() => go(a.to)}
                        className="inline-flex items-center gap-2 rounded-full bg-primary-soft px-3 py-1.5 text-[13px] font-medium text-primary-ink transition-colors hover:brightness-95"
                      >
                        <a.icon className="size-4" /> {a.label}
                      </button>
                    ))}
                  </div>
                </section>
              )}

              {orderedKeys.map((key) => (
                <section key={key}>
                  <p className="mb-2 text-[12px] font-semibold uppercase tracking-[0.04em] text-ink-3">{GROUP_META[key]?.label ?? key}</p>
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {groups[key]!.map((a) => <Tile key={a.id} a={a} />)}
                  </div>
                </section>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body,
  )
}

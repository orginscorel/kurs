import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ArrowRight } from 'lucide-react'
import { api, ApiError } from '@/lib/api'
import { num } from '@/lib/format'
import { Button } from '@/components/ui/Button'
import { Checkbox, Field, Segmented, Switch } from '@/components/ui/form'
import { Alert, Badge, EmptyState } from '@/components/ui/feedback'
import { Drawer } from '@/components/ui/overlay'
import { levelLabel, sectionName, type PreviewData, type PreviewLevel, type RunRow } from './types'

type Mode = 'unplaced' | 'redistribute'

/** Otomatik yerleştirme: ayarla → önizle (değişenler + denge puanı) → onayla ve uygula. */
export function AutoPlaceDrawer({
  termId,
  levels,
  defaultLevel,
  siblingsDefault,
  onClose,
}: {
  termId: number
  levels: number[]
  defaultLevel: number
  siblingsDefault: boolean
  onClose: () => void
}) {
  const qc = useQueryClient()
  const [selected, setSelected] = useState<number[]>([defaultLevel])
  const [mode, setMode] = useState<Mode>('unplaced')
  const [siblings, setSiblings] = useState(siblingsDefault)
  const [preview, setPreview] = useState<PreviewData | null>(null)

  const body = { term_id: termId, levels: selected, mode, siblings_apart: siblings }
  const reset = () => setPreview(null)

  const previewMut = useMutation({
    mutationFn: () => api.post<PreviewData>('/placement/preview', body),
    onSuccess: setPreview,
    onError: (e) => toast.error(e instanceof ApiError ? e.firstError() : 'Önizleme alınamadı.'),
  })
  const applyMut = useMutation({
    mutationFn: () => api.post<{ message: string; run: RunRow }>('/placement/apply', { ...body, hash: preview?.hash }),
    onSuccess: (r) => {
      toast.success(r.message)
      qc.invalidateQueries({ queryKey: ['placement'] })
      qc.invalidateQueries({ queryKey: ['class-groups'] })
      onClose()
    },
    onError: (e) => {
      toast.error(e instanceof ApiError ? e.firstError() : 'Yerleştirme uygulanamadı.')
      if (e instanceof ApiError && e.code === 'placement_stale') previewMut.mutate()
    },
  })

  const total = preview ? preview.totals.moved + preview.totals.placed + preview.totals.waitlisted : 0

  return (
    <Drawer
      open
      onClose={onClose}
      width={640}
      title="Otomatik sınıf yerleştir"
      description="Kapasite, şube sayı farkı, cinsiyet, deneme netleri, risk ve kardeş dengesi gözetilir. Önce önizleyin."
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Kapat</Button>
          {preview ? (
            <Button variant="primary" disabled={total === 0} loading={applyMut.isPending} onClick={() => applyMut.mutate()}>
              {total === 0 ? 'Değişiklik yok' : `Onayla ve uygula (${total})`}
            </Button>
          ) : (
            <Button variant="primary" disabled={selected.length === 0} loading={previewMut.isPending} onClick={() => previewMut.mutate()}>Önizle</Button>
          )}
        </>
      }
    >
      <div className="flex flex-col gap-5">
        <Field label="Sınıf seviyeleri">
          <div className="flex flex-wrap gap-4">
            {levels.map((l) => (
              <Checkbox
                key={l}
                label={levelLabel(l)}
                checked={selected.includes(l)}
                onChange={(on) => { setSelected((s) => (on ? [...s, l].sort((a, b) => a - b) : s.filter((x) => x !== l))); reset() }}
              />
            ))}
          </div>
        </Field>
        <Field label="Kapsam" hint={mode === 'unplaced' ? 'Şubedeki öğrenciler yerinde kalır; yalnız şubesi olmayanlar boş yerlere dağıtılır.' : 'Sabitlenmemiş tüm öğrenciler dengeli dağıtılır; gereksiz taşıma yapılmaz.'}>
          <Segmented<Mode>
            value={mode}
            onChange={(v) => { setMode(v); reset() }}
            options={[{ value: 'unplaced', label: 'Yalnız şubesizleri yerleştir' }, { value: 'redistribute', label: 'Seviyeyi yeniden dağıt' }]}
          />
        </Field>
        <Switch checked={siblings} onChange={(v) => { setSiblings(v); reset() }} label="Kardeşleri farklı şubelere ayır (tercihen)" />

        {preview && (
          <div className="flex flex-col gap-4 border-t border-line pt-4">
            <p className="text-[13px] text-ink-2 tabular">
              <strong className="text-ink">{preview.totals.moved}</strong> öğrenci şube değiştirecek · <strong className="text-ink">{preview.totals.placed}</strong> öğrenci yerleşecek · <strong className="text-ink">{preview.totals.waitlisted}</strong> öğrenci bekleme listesine düşecek
            </p>
            {preview.levels.map((l) => <LevelPreview key={l.level} l={l} />)}
          </div>
        )}
      </div>
    </Drawer>
  )
}

function LevelPreview({ l }: { l: PreviewLevel }) {
  if (l.skipped) return <Alert tone="info" title={`${levelLabel(l.level)} atlandı`}>{l.warnings.join(' ')}</Alert>
  const changes = l.changes ?? []
  return (
    <section className="rounded-[var(--radius-lg)] ring-1 ring-line">
      <header className="flex flex-wrap items-center justify-between gap-2 px-4 pt-3 pb-2">
        <h3 className="text-[14px] font-semibold">{levelLabel(l.level)} <span className="font-normal text-ink-3">· {l.candidates} öğrenci</span></h3>
        <span className="flex items-center gap-1.5 text-[12.5px] text-ink-2 tabular">
          Denge puanı {l.score_before ?? '—'} <ArrowRight className="size-3.5" /> <strong className="text-ink">{l.score_after}</strong><span className="text-ink-3">/100</span>
        </span>
      </header>
      {l.warnings.length > 0 && <div className="px-4 pb-2"><Alert tone="warning">{l.warnings.join(' ')}</Alert></div>}
      {(l.waitlisted ?? 0) > 0 && <div className="px-4 pb-2"><Alert tone="warning">Seviyede yer yetmiyor: {l.waitlisted} öğrenci bekleme listesine alınacak (yeni şube açılmaz).</Alert></div>}
      <div className="overflow-x-auto scroll-thin">
        <table className="tbl w-full text-[12.5px] tabular">
          <thead>
            <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
              <th className="h-8 px-4 font-medium text-left">Şube</th>
              <th className="px-2 font-medium text-center">Mevcut</th>
              <th className="px-2 font-medium text-center">Kız / Erkek</th>
              <th className="px-2 font-medium text-center">Ort. net</th>
              <th className="px-2 font-medium text-center">Yüksek risk</th>
            </tr>
          </thead>
          <tbody>
            {(l.after ?? []).map((a, i) => {
              const b = l.before?.[i]
              return (
                <tr key={a.section} className="border-b border-line last:border-0">
                  <td className="px-4 py-1.5 font-medium text-left">{sectionName(l.level, a.section)}</td>
                  <td className="px-2 text-center">{b?.size ?? 0} → <strong>{a.size}</strong>/{a.capacity}</td>
                  <td className="px-2 text-center">{b ? `${b.female}/${b.male}` : '—'} → <strong>{a.female}/{a.male}</strong></td>
                  <td className="px-2 text-center">{b?.avg_score === null || b === undefined ? '—' : num(b.avg_score, 1)} → <strong>{a.avg_score === null ? '—' : num(a.avg_score, 1)}</strong></td>
                  <td className="px-2 text-center">{b?.high_risk ?? 0} → <strong>{a.high_risk}</strong></td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      {changes.length === 0 ? (
        <EmptyState compact title="Değişiklik yok" description="Bu seviye zaten kurallara uygun." />
      ) : (
        <ul className="divide-y divide-line border-t border-line">
          {changes.map((c) => (
            <li key={c.student_id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-[13px]">
              <span className="min-w-0 flex-1 truncate font-medium">{c.full_name}</span>
              <span className="text-[12px] text-ink-3 tabular">{c.avg_net === null ? 'net yok' : `${num(c.avg_net, 1)} net`}</span>
              <span className="flex items-center gap-1.5 text-[12.5px]">
                <span className="text-ink-3">{c.from ?? 'Şubesiz'}</span>
                <ArrowRight className="size-3.5 text-ink-3" />
                {c.to_waitlist ? <Badge tone="warning">Bekleme listesi</Badge> : <strong>{c.to}</strong>}
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

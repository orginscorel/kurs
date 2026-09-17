import { FileText, Wallet } from 'lucide-react'
import { toast } from 'sonner'
import { api } from '@/lib/api'
import { Button } from '@/components/ui/Button'
import { date, dateTime, money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { Alert, Badge, EmptyState, ProgressBar, Skeleton } from '@/components/ui/feedback'
import { Panel } from '@/components/ui/layout'
import { installmentTone, usePortal, usePortalQuery, type FinanceTotals, useSelectedStudentId, useVoice } from '../api'
import { MiniStat, PortalTitle } from '../ui'

type Data = {
  totals: FinanceTotals
  enrollments: { id: number; enrollment_no: string; program: string; term: string | null; status: string; enrolled_on: string; list_price: string; discount: string; net_price: string; is_my_responsibility?: boolean }[]
  installments: { id: number; enrollment_id: number; sequence: number; due_date: string; amount: string; paid_amount: string; remaining: string; status: string; status_label: string }[]
  payments: { id: number; receipt_no: string; amount: string; method: string; method_label: string; paid_at: string; is_voided: boolean }[]
  household: { student_id: number; full_name: string; total: string; paid: string; remaining: string; overdue: string; overdue_count: number }[] | null
}

const enrollmentLabel: Record<string, string> = { active: 'Aktif', frozen: 'Donduruldu', withdrawn: 'Ayrıldı', completed: 'Tamamlandı', pending: 'Bekliyor' }

export default function PortalFinance() {
  const { data, isLoading } = usePortal<Data>('finance', '/portal/finance')
  const v = useVoice()
  const current = useSelectedStudentId()
  const pdfQuery = usePortalQuery()
  if (isLoading || !data) return <div className="flex flex-col gap-4"><Skeleton className="h-24" /><Skeleton className="h-64" /></div>
  const t = data.totals
  const paidPct = Number(t.total) ? (Number(t.paid) / Number(t.total)) * 100 : 0

  return (
    <div className="animate-fade-in flex flex-col gap-4">
      <PortalTitle
        title={v('Ödemelerim', 'Ödemeler')}
        description="Kayıt ücreti, taksit planı ve yapılan ödemeler (yalnız görüntüleme)"
        actions={
          <Button size="sm" icon={<FileText className="size-4" />} onClick={() => api.download('/portal/finance/statement.pdf', pdfQuery, 'cari-ekstre.pdf').catch(() => toast.error('Ekstre hazırlanamadı.'))}>
            Hesap ekstresi (PDF)
          </Button>
        }
      />

      {Number(t.overdue) > 0 && <Alert tone="danger">{v(`${t.overdue_count} taksitin gecikmiş`, `${t.overdue_count} taksit gecikmiş`)} · {money(t.overdue)}. {v('Ödeme için kurum muhasebesiyle görüşebilirsin.', 'Ödeme için kurum muhasebesiyle görüşebilirsiniz.')}</Alert>}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <MiniStat label="Toplam tutar" value={money(t.total, { short: true })} />
        <MiniStat label="Ödenen tutar" value={money(t.paid, { short: true })} tone="success" />
        <MiniStat label="Kalan borç" value={money(t.remaining, { short: true })} tone={Number(t.overdue) > 0 ? 'danger' : undefined} />
        <MiniStat label="Sıradaki taksit" value={t.next_installment ? money(t.next_installment.remaining, { short: true }) : '—'} sub={t.next_installment ? `Vade ${date(t.next_installment.due_date)}` : 'Bekleyen taksit yok'} />
      </div>
      {Number(t.total) > 0 && (
        <div>
          <ProgressBar value={paidPct} tone="success" />
          <p className="mt-1 text-[12.5px] text-ink-3 tabular">%{Math.round(paidPct)} ödendi</p>
        </div>
      )}

      {data.household && data.household.length > 1 && (
        <Panel title="Tüm öğrencilerim" description="Hesabınıza bağlı öğrencilerin ödeme özeti" flush>
          <ul>
            {data.household.map((h) => (
              <li key={h.student_id} className={cn('flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-line px-4 py-2.5 text-[14px]', h.student_id === current && 'bg-primary-soft/50')}>
                <span className="min-w-0 break-words font-medium">{h.full_name}</span>
                <span className="flex flex-wrap items-center gap-x-3 tabular text-ink-2">
                  <span>Kalan borç {money(h.remaining)}</span>
                  {Number(h.overdue) > 0 && <Badge tone="danger">{h.overdue_count} gecikmiş · {money(h.overdue, { short: true })}</Badge>}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-5">
        <Panel className="lg:col-span-3" title="Taksit planı" flush>
          {data.installments.length === 0 ? (
            <EmptyState compact icon={<Wallet />} title="Taksit planı yok" />
          ) : (
            <ul>
              {data.installments.map((i) => (
                <li key={i.id} className={cn('flex items-center gap-3 border-t border-line px-4 py-2.5', i.status === 'paid' && 'opacity-75')}>
                  <span className="grid size-8 shrink-0 place-items-center rounded-full bg-surface-2 text-[12.5px] font-semibold tabular" title={`${i.sequence}. taksit`} aria-label={`${i.sequence}. taksit`}>{i.sequence}</span>
                  <div className="min-w-0 flex-1">
                    <p className="text-[14.5px] font-medium tabular">{money(i.amount)}</p>
                    <p className="text-[12.5px] text-ink-3">{i.sequence}. taksit · Vade {date(i.due_date)}{Number(i.paid_amount) > 0 && i.status !== 'paid' ? ` · ${money(i.paid_amount)} ödendi, ${money(i.remaining)} kaldı` : ''}</p>
                  </div>
                  <Badge tone={installmentTone[i.status] ?? 'neutral'} dot>{i.status_label}</Badge>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
          <Panel title="Yapılan ödemeler" flush>
            {data.payments.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Henüz ödeme yok.</p>
            ) : (
              <ul>
                {data.payments.map((p) => (
                  <li key={p.id} className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5">
                    <div className="min-w-0">
                      <p className={cn('text-[14.5px] font-medium tabular', p.is_voided && 'line-through text-ink-3')}>{money(p.amount)}</p>
                      <p className="break-words text-[12.5px] text-ink-3">{dateTime(p.paid_at)} · {p.method_label} · Makbuz no {p.receipt_no}</p>
                    </div>
                    {p.is_voided && <Badge>İptal</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
          <Panel title={v('Kayıtlarım', 'Kayıtlar')} flush>
            {data.enrollments.length === 0 ? (
              <p className="px-4 pb-4 text-[14px] text-ink-3">Kayıt yok.</p>
            ) : (
              <ul>
                {data.enrollments.map((e) => (
                  <li key={e.id} className="border-t border-line px-4 py-2.5 text-[14px]">
                    <div className="flex items-center justify-between gap-2">
                      <p className="min-w-0 break-words font-medium">{e.program}</p>
                      <Badge tone={e.status === 'active' ? 'success' : 'neutral'}>{enrollmentLabel[e.status] ?? e.status}</Badge>
                    </div>
                    {e.is_my_responsibility && <p className="mt-0.5 text-[12.5px] font-medium text-primary">Ödeme sorumlusu: siz</p>}
                    <p className="text-[12.5px] text-ink-3">{[e.term, `Kayıt tarihi ${date(e.enrolled_on)}`].filter(Boolean).join(' · ')} · Ücret {money(e.net_price)}{Number(e.discount) > 0 ? ` (indirim ${money(e.discount)})` : ''}</p>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>
      </div>
    </div>
  )
}

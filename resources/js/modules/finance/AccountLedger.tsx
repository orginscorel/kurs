import { Link, useParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { CheckCircle2, Printer, ScrollText, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { api, ApiError, type Paginated } from '@/lib/api'
import { Button, ButtonLink } from '@/components/ui/Button'
import { dateTime, money } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Badge, EmptyState, Skeleton } from '@/components/ui/feedback'
import { Input, Segmented } from '@/components/ui/form'
import { openPdf, SOURCE_LABELS } from './shared'

type Tx = { id: number; amount: string; balance_after: string; source_type: string; source_id: number; description: string; occurred_at: string; created_at: string; user: string | null; student_id: number | null; receipt_no: string | null }
type Resp = Paginated<Tx> & {
  meta: {
    account: { id: number; name: string; kind: string; kind_label: string; balance: string; is_active: boolean; bank_name: string | null; iban: string | null }
    summary: { opening: string; inflow: string; outflow: string; closing: string }
    ledger_balance: string
    balance_consistent: boolean
  }
}

export default function AccountLedger() {
  const { id } = useParams()
  const list = useListState()
  const { data, isLoading, isFetching, error } = useQuery({
    queryKey: ['finance', 'ledger', id, list.query],
    queryFn: () => api.get<Resp>(`/finance/accounts/${id}/ledger`, list.query),
    placeholderData: keepPreviousData,
  })
  const m = data?.meta

  const columns: Column<Tx>[] = [
    { key: 'at', header: 'Hareket tarihi', cell: (t) => <span className="tabular whitespace-nowrap">{dateTime(t.occurred_at)}</span> },
    {
      key: 'desc',
      header: 'Açıklama',
      cell: (t) => (
        <div className="min-w-0">
          <p className="text-ink">{t.description}</p>
          <p className="text-[12px] text-ink-3">
            <Badge className="mr-1.5">{SOURCE_LABELS[t.source_type] ?? t.source_type}</Badge>
            {t.source_type === 'payment' && t.receipt_no && <Link to={`/finans/tahsilatlar?makbuz=${encodeURIComponent(t.receipt_no)}`} className="hover:underline">{t.receipt_no}</Link>}
            {t.source_type === 'finance_entry' && <Link to={`/finans/gelir-gider?detay=${t.source_id}`} className="hover:underline">Kaydı aç</Link>}
            {t.user ? ` · ${t.user}` : ''}
          </p>
        </div>
      ),
    },
    { key: 'amount', header: 'Tutar', align: 'right', cell: (t) => <span className={cn('font-semibold whitespace-nowrap', Number(t.amount) < 0 ? 'text-danger' : 'text-success')}>{Number(t.amount) < 0 ? '−' : '+'}{money(Math.abs(Number(t.amount)))}</span> },
    { key: 'balance', header: 'Hareket sonrası bakiye', align: 'right', cell: (t) => <span className="text-ink-2 whitespace-nowrap">{money(t.balance_after)}</span> },
    {
      key: 'actions',
      header: '',
      cell: (t) =>
        ['payment', 'refund', 'finance_entry', 'account_transfer'].includes(t.source_type) ? (
          <Button size="icon-sm" variant="ghost" aria-label="Dekont" title="İşlem dekontu" onClick={() => openPdf(`/finance/documents/vouchers/${t.source_type}/${t.source_id}.pdf`).catch((e) => toast.error(e.message))}>
            <Printer className="size-3.5" />
          </Button>
        ) : null,
    },
  ]

  if (error && !data) {
    return (
      <div className="animate-fade-in">
        <PageHeader title="Hesap hareketleri" breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Kasa ve banka', to: '/finans/hesaplar' }, { label: 'Bulunamadı' }]} />
        <EmptyState title="Hesap bulunamadı" description={error instanceof ApiError && error.status !== 404 ? error.message : 'Kayıt silinmiş ya da adres hatalı olabilir.'} action={<ButtonLink to="/finans/hesaplar">Hesaplara dön</ButtonLink>} />
      </div>
    )
  }

  return (
    <div className="animate-fade-in">
      <PageHeader
        title={m?.account.name ?? 'Hesap hareketleri'}
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Kasa ve banka', to: '/finans/hesaplar' }, { label: m?.account.name ?? '…' }]}
        description={m ? `${m.account.kind_label}${m.account.bank_name ? ` · ${m.account.bank_name}` : ''}${m.account.iban ? ` · ${m.account.iban}` : ''}` : undefined}
        actions={
          m && (
            m.balance_consistent ? (
              <Badge tone="success"><CheckCircle2 className="size-3" /> Bakiye defterle tutarlı</Badge>
            ) : (
              <Badge tone="danger"><TriangleAlert className="size-3" /> Bakiye farkı: defter {money(m.ledger_balance)}</Badge>
            )
          )
        }
      />

      <div className="mb-3 grid grid-cols-2 lg:grid-cols-5 gap-3">
        {[
          ['Güncel bakiye', m?.account.balance, ''],
          ['Dönem başı', m?.summary.opening, ''],
          ['Giriş', m?.summary.inflow, 'text-success'],
          ['Çıkış', m?.summary.outflow, 'text-danger'],
          ['Dönem sonu', m?.summary.closing, ''],
        ].map(([label, value, tone]) => (
          <div key={label} className="rounded-[var(--radius-lg)] bg-surface px-4 py-3 ring-1 ring-line">
            <p className="text-[12px] text-ink-3">{label}</p>
            {value === undefined ? <Skeleton className="mt-1 h-6 w-24" /> : <p className={cn('text-[18px] font-semibold tabular', tone)}>{money(value)}</p>}
          </div>
        ))}
      </div>

      <DataTable
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        onPage={(page) => list.update({ page })}
        toolbar={
          <div className="flex flex-wrap items-center gap-2">
            <Input type="date" value={list.filters.from ?? ''} onChange={(e) => list.update({ filters: { from: e.target.value } })} className="w-40" aria-label="Başlangıç" />
            <Input type="date" value={list.filters.to ?? ''} onChange={(e) => list.update({ filters: { to: e.target.value } })} className="w-40" aria-label="Bitiş" />
            <Segmented size="sm" value={list.filters.direction ?? 'all'} onChange={(v) => list.update({ filters: { direction: v === 'all' ? null : v } })} options={[{ value: 'all', label: 'Tümü' }, { value: 'in', label: 'Giriş' }, { value: 'out', label: 'Çıkış' }]} />
          </div>
        }
        empty={<EmptyState icon={<ScrollText />} title="Bu aralıkta hareket yok" />}
      />
    </div>
  )
}

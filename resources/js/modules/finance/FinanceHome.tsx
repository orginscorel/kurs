import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import {
  AlarmClock, ArrowRight, BookOpen, CalendarCheck, CalendarClock, CircleDollarSign, FilePlus2, FileText, HandCoins, Landmark, Lock, Plus, ReceiptText,
  Scale, TrendingDown, TrendingUp, Undo2, Users, Wallet,
} from 'lucide-react'
import { api } from '@/lib/api'
import { compactMoney, date, dateTime, money, num, percent, time, todayISO } from '@/lib/format'
import { cn } from '@/lib/cn'
import { useCan } from '@/app/auth'
import { PageHeader, Panel, Stat } from '@/components/ui/layout'
import { Alert, Badge, EmptyState, Skeleton, StatusDot } from '@/components/ui/feedback'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Segmented } from '@/components/ui/form'
import { axisProps, ChartTooltip, gridProps, Legend, monthLabel, series } from '@/components/charts/ChartKit'
import { ACCOUNT_KINDS, METHODS } from './shared'
import type { Cockpit } from './ledger'

type Row = { id: number; student_id: number; enrollment_id: number; sequence: number; due_date: string; amount: string; paid_amount: string; remaining: string; status: string; student: string; student_no: string; enrollment_no: string }
type Overview = {
  generated_at: string
  kpis: {
    collected_today: string; collected_month: string; receivable: string; overdue_amount: string; overdue_count: number; overdue_students: number
    due_today: string; due_next_7: string; due_next_30: string; other_income_month: string; income_month: string; expense_month: string; net_month: string
    collection_rate_month: number | null
  }
  chart: { period: string; label: string; collections: string; other_income: string; income: string; expense: string; net: string; collection_rate: number | null }[]
  upcoming: Row[]
  overdue: Row[]
  recent_payments: { id: number; receipt_no: string; amount: string; method: string; paid_at: string; voided_at: string | null; student_id: number; student: string; account: string | null }[]
  accounts: { id: number; kind: string; name: string; balance: string }[]
  low_stock: number
}

export default function FinanceHome() {
  const can = useCan()
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({ queryKey: ['finance', 'overview'], queryFn: () => api.get<Overview>('/finance/overview'), refetchInterval: 60_000 })
  const k = data?.kpis
  const cockpit = useQuery({ queryKey: ['finance', 'cockpit'], queryFn: () => api.get<{ data: Cockpit }>('/finance/cockpit').then((r) => r.data), refetchInterval: 120_000 })
  const c = cockpit.data
  const cashTotal = (data?.accounts ?? []).reduce((sum, a) => sum + Math.round(Number(a.balance) * 100), 0) / 100

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Finans Merkezi"
        description={
          <span className="inline-flex items-center gap-2">
            Bugün yapılacaklar, alacaklar ve nakit bir bakışta
            {data && (
              <span className="inline-flex items-center gap-1.5 text-ink-3">
                · <StatusDot tone="success" pulse /> {time(data.generated_at)}
              </span>
            )}
          </span>
        }
        actions={
          <>
            {can('expenses.manage') && (
              <ButtonLink to="/finans/gelir-gider?yeni=expense" icon={<TrendingDown className="size-4" />} className="hidden sm:inline-flex">
                Gider ekle
              </ButtonLink>
            )}
            {can('finance.invoice') && (
              <ButtonLink to="/finans/faturalar/yeni" variant="info" icon={<FilePlus2 className="size-4" />}>
                Fatura
              </ButtonLink>
            )}
            {can('payments.create') && (
              <ButtonLink to="/finans/tahsilat/veli" icon={<Users className="size-4" />}>
                Veli toplu
              </ButtonLink>
            )}
            {can('payments.create') && (
              <ButtonLink to="/finans/tahsilat" variant="primary" icon={<HandCoins className="size-4" />}>
                Tahsilat al
              </ButtonLink>
            )}
          </>
        }
      />

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <Stat label="Bugünkü tahsilat" icon={<CircleDollarSign />} tone="success" loading={isLoading} value={money(k?.collected_today, { short: true })} sub="makbuz listesi" to={`/finans/tahsilatlar?from=${todayISO()}&to=${todayISO()}`} />
        <Stat label="7 gün içinde vadesi gelen" icon={<CalendarClock />} loading={isLoading} value={compactMoney(k?.due_next_7)} sub={`Bugün vadeli: ${compactMoney(k?.due_today)}`} to="/finans/alacaklar?status=pending" />
        <Stat
          label="Geciken"
          icon={<AlarmClock />}
          loading={isLoading}
          tone={Number(k?.overdue_amount ?? 0) > 0 ? 'danger' : undefined}
          value={compactMoney(k?.overdue_amount)}
          sub={`${num(k?.overdue_students)} öğrenci · takip`}
          to="/finans/takip"
        />
        <Stat label="Kasa ve banka" icon={<Landmark />} loading={isLoading} tone={cashTotal < 0 ? 'danger' : undefined} value={compactMoney(cashTotal)} sub={`${data?.accounts.length ?? 0} aktif hesap`} to="/finans/hesaplar" />
        <Stat
          label="Faturalanmamış"
          icon={<FileText />}
          loading={cockpit.isLoading}
          tone={Number(c?.unbilled.amount ?? 0) > 0 ? 'warning' : undefined}
          value={compactMoney(c?.unbilled.amount)}
          sub={`${num(c?.unbilled.count)} makbuz`}
          to="/finans/faturalar?sekme=faturalanmamis"
        />
        <Stat
          label="Mutabakat bekleyen"
          icon={<Scale />}
          loading={cockpit.isLoading}
          tone={(c?.pos_pending.late ?? 0) > 0 ? 'warning' : undefined}
          value={compactMoney(c?.pos_pending.net)}
          sub={`${num(c?.pos_pending.count)} POS · ${num(c?.bank_unmatched)} banka hareketi`}
          to="/finans/mutabakat"
        />
      </div>

      {c && <TodoStrip c={c} />}

      <div className="mt-3 grid grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat label="Bu ay tahsilat" icon={<Wallet />} loading={isLoading} value={compactMoney(k?.collected_month)} sub={k?.collection_rate_month != null ? `tahsilat oranı ${percent(k.collection_rate_month, 1)}` : 'bu ay vadeli taksit yok'} to="/raporlar/finans-analiz?rapor=collection-performance" />
        <Stat label="Bu ay gelir" icon={<TrendingUp />} loading={isLoading} value={compactMoney(k?.income_month)} sub={`tahsilat dışı ${compactMoney(k?.other_income_month)}`} to="/raporlar/finans-analiz?rapor=income-statement" />
        <Stat label="Bu ay gider" icon={<TrendingDown />} loading={isLoading} value={compactMoney(k?.expense_month)} sub="iptaller hariç" to="/finans/gelir-gider?direction=expense" />
        <Stat label="Bu ay net" icon={<Landmark />} loading={isLoading} tone={Number(k?.net_month ?? 0) < 0 ? 'danger' : 'success'} value={compactMoney(k?.net_month)} sub={`toplam alacak ${compactMoney(k?.receivable)}`} to="/raporlar/finans-analiz?rapor=cash-flow" />
      </div>

      <div className="mt-4 grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div className="xl:col-span-2 flex flex-col gap-4">
          <FlowChart data={data} loading={isLoading} />
          <UpcomingPanel data={data} loading={isLoading} onCollect={(r) => navigate(`/finans/tahsilat?ogrenci=${r.student_id}&taksit=${r.id}`)} canCollect={can('payments.create')} />
        </div>
        <div className="flex flex-col gap-4">
          <AccountsPanel data={data} loading={isLoading} />
          <OverduePanel data={data} loading={isLoading} canCollect={can('payments.create')} />
          <RecentPayments data={data} loading={isLoading} />
          {!!data?.low_stock && (
            <Alert tone="warning" title={`${data.low_stock} üründe stok kritik seviyede`} action={<ButtonLink size="sm" to="/finans/envanter?low_stock=1" icon={<BookOpen className="size-3.5" />}>Envanter</ButtonLink>}>
              Minimum stok seviyesinin altına düşen kitap ve materyaller var.
            </Alert>
          )}
        </div>
      </div>
    </div>
  )
}

function FlowChart({ data, loading }: { data?: Overview; loading: boolean }) {
  const [mode, setMode] = useState<'flow' | 'net'>('flow')
  const allRows = (data?.chart ?? []).map((r) => ({ month: r.period, income: Number(r.income), expense: Number(r.expense), net: Number(r.net), collections: Number(r.collections) }))
  const empty = allRows.every((r) => r.income === 0 && r.expense === 0)
  // Verisi olmayan baştaki aylar grafiği ezmesin: ilk hareketli aydan bir önceki aydan başla
  const firstActive = allRows.findIndex((r) => r.income > 0 || r.expense > 0)
  const rows = firstActive > 0 ? allRows.slice(firstActive - 1) : allRows
  return (
    <Panel
      title="Gelir ve gider"
      description="Son 12 ay · öğrenci tahsilatı + diğer gelirler"
      actions={<Segmented size="sm" value={mode} onChange={setMode} options={[{ value: 'flow', label: 'Gelir / Gider' }, { value: 'net', label: 'Net' }]} />}
    >
      {loading ? (
        <Skeleton className="h-[260px]" />
      ) : empty ? (
        <EmptyState compact icon={<TrendingUp />} title="Henüz gelir ya da gider yok" description="Tahsilat ve gider kayıtları burada aylık olarak görünür." />
      ) : (
        <>
          <Legend className="mb-2" items={mode === 'flow' ? [{ label: 'Gelir', color: series[0]! }, { label: 'Gider', color: series[1]! }] : [{ label: 'Net', color: series[0]! }]} />
          <div className="h-[250px]">
            <ResponsiveContainer>
              {mode === 'flow' ? (
                <BarChart data={rows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }} barGap={2} barCategoryGap="28%">
                  <CartesianGrid {...gridProps} />
                  <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} dy={6} />
                  <YAxis tickFormatter={(v) => compactMoney(v).replace(' ₺', '')} width={52} {...axisProps} />
                  <Tooltip cursor={{ fill: 'var(--surface-2)', radius: 6 }} content={<ChartTooltip formatLabel={monthLabel} formatValue={(v) => money(v, { short: true })} />} />
                  <Bar dataKey="income" name="Gelir" fill={series[0]} radius={[4, 4, 0, 0]} maxBarSize={28} />
                  <Bar dataKey="expense" name="Gider" fill={series[1]} radius={[4, 4, 0, 0]} maxBarSize={28} />
                </BarChart>
              ) : (
                <BarChart data={rows} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <CartesianGrid {...gridProps} />
                  <XAxis dataKey="month" tickFormatter={monthLabel} {...axisProps} />
                  <YAxis tickFormatter={(v) => compactMoney(v).replace(' ₺', '')} width={48} {...axisProps} />
                  <Tooltip cursor={{ fill: 'var(--surface-2)' }} content={<ChartTooltip formatLabel={monthLabel} formatValue={(v) => money(v, { short: true })} />} />
                  <Bar dataKey="net" name="Net" fill={series[0]} radius={[4, 4, 0, 0]} maxBarSize={26} />
                </BarChart>
              )}
            </ResponsiveContainer>
          </div>
        </>
      )}
    </Panel>
  )
}

function UpcomingPanel({ data, loading, onCollect, canCollect }: { data?: Overview; loading: boolean; onCollect: (r: Row) => void; canCollect: boolean }) {
  const [range, setRange] = useState<'7' | '30'>('7')
  const today = new Date()
  const limit = new Date(today.getFullYear(), today.getMonth(), today.getDate() + Number(range))
  const rows = (data?.upcoming ?? []).filter((r) => new Date(r.due_date) <= limit)
  const total = rows.reduce((s, r) => s + Math.round(Number(r.remaining) * 100), 0)
  return (
    <Panel
      title="Yaklaşan tahsilatlar"
      description={loading ? undefined : `${rows.length} taksit · ${money(total / 100, { short: true })}`}
      actions={
        <>
          <Segmented size="sm" value={range} onChange={setRange} options={[{ value: '7', label: '7 gün' }, { value: '30', label: '30 gün' }]} />
          <Link to="/finans/alacaklar?status=pending" className="hidden sm:inline-flex items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Tümü <ArrowRight className="size-3.5" /></Link>
        </>
      }
      flush
    >
      {loading ? (
        <div className="px-4 pb-4"><Skeleton className="h-48" /></div>
      ) : rows.length === 0 ? (
        <EmptyState compact icon={<CalendarClock />} title={`Önümüzdeki ${range} günde vadesi gelen taksit yok`} />
      ) : (
        <div className="overflow-x-auto scroll-thin">
          <table className="tbl w-full text-[13px]">
            <thead>
              <tr className="border-y border-line bg-surface-2/60 text-[12px] text-ink-3">
                <th className="h-9 px-4 font-medium text-left">Vade tarihi</th>
                <th className="fill px-3 font-medium text-center">Öğrenci</th>
                <th className="px-3 font-medium hidden md:table-cell text-center">Kayıt no</th>
                <th className="px-3 font-medium text-center">Kalan borç</th>
                <th className="px-4 text-center" />
              </tr>
            </thead>
            <tbody>
              {rows.slice(0, 12).map((r) => (
                <tr key={r.id} className="border-b border-line last:border-0 hover:bg-surface-2/60">
                  <td className="px-4 py-2.5 tabular whitespace-nowrap text-left">{date(r.due_date)}</td>
                  <td className="fill px-3 py-2.5 text-center">
                    <Link to={`/ogrenciler/${r.student_id}`} className="font-medium hover:underline">{r.student}</Link>
                    <span className="block text-[12px] text-ink-3">{r.sequence}. taksit</span>
                  </td>
                  <td className="px-3 py-2.5 text-ink-3 hidden md:table-cell tabular text-center">{r.enrollment_no}</td>
                  <td className="px-3 py-2.5 tabular font-medium text-center">{money(r.remaining)}</td>
                  <td className="px-4 py-2.5 text-right">
                    {canCollect && (
                      <Button size="xs" variant="soft" onClick={() => onCollect(r)}>
                        Tahsil et
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  )
}

function OverduePanel({ data, loading, canCollect }: { data?: Overview; loading: boolean; canCollect: boolean }) {
  return (
    <Panel title="Gecikmiş ödemeler" actions={<Link to="/finans/alacaklar?status=overdue" className="inline-flex items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Tümü <ArrowRight className="size-3.5" /></Link>}>
      {loading ? (
        <Skeleton className="h-40" />
      ) : !data?.overdue.length ? (
        <p className="text-[13px] text-ink-3">Gecikmiş taksit yok.</p>
      ) : (
        <ul className="flex flex-col divide-y divide-line">
          {data.overdue.slice(0, 8).map((r) => {
            const days = Math.max(0, Math.round((Date.now() - new Date(r.due_date).getTime()) / 86_400_000))
            return (
              <li key={r.id} className="flex items-center gap-3 py-2">
                <div className="min-w-0 flex-1">
                  <Link to={`/ogrenciler/${r.student_id}`} className="block truncate text-[13px] font-medium hover:underline">{r.student}</Link>
                  <span className="text-[12px] text-ink-3">Vade: {date(r.due_date)} · <span className="text-danger">{days} gün gecikti</span></span>
                </div>
                <span className="text-[13px] font-medium tabular">{money(r.remaining, { short: true })}</span>
                {canCollect && (
                  <ButtonLink size="xs" variant="ghost" to={`/finans/tahsilat?ogrenci=${r.student_id}&taksit=${r.id}`} aria-label="Tahsil et">
                    <HandCoins className="size-3.5" />
                  </ButtonLink>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}

function AccountsPanel({ data, loading }: { data?: Overview; loading: boolean }) {
  const total = (data?.accounts ?? []).reduce((s, a) => s + Math.round(Number(a.balance) * 100), 0)
  return (
    <Panel title="Kasa ve banka" description={loading ? undefined : `Toplam ${money(total / 100, { short: true })}`} actions={<Link to="/finans/hesaplar" className="inline-flex items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Hesaplar <ArrowRight className="size-3.5" /></Link>}>
      {loading ? (
        <Skeleton className="h-24" />
      ) : !data?.accounts.length ? (
        <p className="text-[13px] text-ink-3">Aktif hesap yok.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {data.accounts.map((a) => (
            <li key={a.id}>
              <Link to={`/finans/hesaplar/${a.id}`} className="flex items-center gap-3 rounded-[var(--radius-sm)] px-1 py-1 hover:bg-surface-2">
                <span className="grid size-8 place-items-center rounded-[var(--radius-sm)] bg-surface-2 text-ink-2 ring-1 ring-line [&_svg]:size-4">{a.kind === 'bank' ? <Landmark /> : a.kind === 'pos' ? <ReceiptText /> : <Wallet />}</span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[13px] font-medium">{a.name}</span>
                  <span className="block text-[12px] text-ink-3">{ACCOUNT_KINDS[a.kind]}</span>
                </span>
                <span className={cn('text-[13.5px] font-semibold tabular', Number(a.balance) < 0 && 'text-danger')}>{money(a.balance, { short: true })}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

function RecentPayments({ data, loading }: { data?: Overview; loading: boolean }) {
  return (
    <Panel title="Son tahsilatlar" actions={<Link to="/finans/tahsilatlar" className="inline-flex items-center gap-1 text-[12.5px] text-ink-3 hover:text-ink">Tümü <ArrowRight className="size-3.5" /></Link>}>
      {loading ? (
        <Skeleton className="h-40" />
      ) : !data?.recent_payments.length ? (
        <EmptyState compact icon={<ReceiptText />} title="Henüz tahsilat yok" action={<ButtonLink size="sm" to="/finans/tahsilat" icon={<Plus className="size-3.5" />}>Tahsilat al</ButtonLink>} />
      ) : (
        <ul className="flex flex-col divide-y divide-line">
          {data.recent_payments.map((p) => (
            <li key={p.id} className={cn('flex items-center gap-3 py-2', p.voided_at && 'opacity-60')}>
              <div className="min-w-0 flex-1">
                <Link to={`/finans/tahsilatlar?makbuz=${encodeURIComponent(p.receipt_no)}`} className="block truncate text-[13px] font-medium hover:underline">{p.student}</Link>
                <span className="text-[12px] text-ink-3">{dateTime(p.paid_at)} · {METHODS[p.method] ?? p.method}</span>
              </div>
              {p.voided_at ? <Badge tone="danger">İptal</Badge> : <span className="text-[13px] font-medium tabular">{money(p.amount, { short: true })}</span>}
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}

/** Bugün yapılacaklar: yalnız bekleyen iş varsa görünen kısa bağlantılar. */
function TodoStrip({ c }: { c: Cockpit }) {
  const can = useCan()
  const items = [
    c.promises.today > 0 && { to: '/finans/takip?promise=due', icon: <CalendarCheck />, label: `Bugün ${c.promises.today} ödeme sözü`, sub: money(c.promises.today_amount, { short: true }), tone: 'text-info' },
    c.promises.late > 0 && { to: '/finans/takip?promise=due', icon: <AlarmClock />, label: `${c.promises.late} söz tarihi geçti`, sub: 'aranmalı', tone: 'text-danger' },
    c.draft_invoices.count > 0 && can('finance.invoice') && { to: '/finans/faturalar?status=draft', icon: <FileText />, label: `${c.draft_invoices.count} fatura taslağı`, sub: money(c.draft_invoices.amount, { short: true }), tone: 'text-warning' },
    c.overdue_invoices.count > 0 && { to: '/finans/faturalar?status=issued', icon: <FileText />, label: `${c.overdue_invoices.count} tahsil edilmemiş fatura`, sub: money(c.overdue_invoices.amount, { short: true }), tone: 'text-danger' },
    c.pos_pending.late > 0 && { to: '/finans/mutabakat', icon: <Scale />, label: `${c.pos_pending.late} POS yatışı valörü geçti`, sub: money(c.pos_pending.late_amount, { short: true }), tone: 'text-warning' },
    c.refunds_month.count > 0 && { to: '/finans/iadeler', icon: <Undo2 />, label: `Bu ay ${c.refunds_month.count} iade`, sub: money(c.refunds_month.amount, { short: true }), tone: 'text-ink-2' },
    c.suggest_close && can('finance.period_close') && { to: '/finans/muhasebe?sekme=donem', icon: <Lock />, label: 'Geçen ay kapatılmadı', sub: 'dönem kilidi', tone: 'text-primary' },
  ].filter(Boolean) as { to: string; icon: React.ReactNode; label: string; sub: string; tone: string }[]
  if (!items.length) return null
  return (
    <div className="mt-3 flex gap-2 overflow-x-auto scroll-thin pb-1">
      {items.map((i) => (
        <Link key={i.label} to={i.to} className="group flex shrink-0 items-center gap-2.5 rounded-[var(--radius-md)] bg-surface px-3 py-2 ring-1 ring-line hover:ring-line-strong">
          <span className={cn('[&_svg]:size-4', i.tone)}>{i.icon}</span>
          <span className="text-[13px] font-medium text-ink">{i.label}</span>
          <span className="text-[12px] text-ink-3 tabular">{i.sub}</span>
          <ArrowRight className="size-3.5 text-ink-3 transition-transform group-hover:translate-x-0.5" />
        </Link>
      ))}
    </div>
  )
}

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { money, num } from '@/lib/format'
import { Alert } from '@/components/ui/feedback'
import { Button } from '@/components/ui/Button'

type OverdueAlertData = {
  date: string
  installments: { count: number; amount: string; students: number }
  invoices: { count: number; amount: string }
  due_today: { count: number; amount: string }
}

const dismissKey = 'ebe-dismiss:dashboard-overdue'
function readDismissed(day: string) {
  try {
    return localStorage.getItem(dismissKey) === day
  } catch {
    return false
  }
}

/**
 * Günlük gecikme uyarısı: vadesi geçmiş taksit + tahsil edilmemiş fatura, bugün vadesi dolanlar.
 * "Bugün gizle" ertesi gün sıfırlanır. Kontrol Merkezi'nde sekmelerin üstünde, her sekmede görünür.
 * Ayrı dosyada durur ki grafik kütüphanesini (Kurum özeti parçası) ilk açılışta yüklemesin.
 */
export function OverdueBanner() {
  const { data } = useQuery({
    queryKey: ['finance', 'overdue-alert'],
    queryFn: () => api.get<{ data: OverdueAlertData }>('/finance/overdue-alert').then((r) => r.data),
    refetchInterval: 300_000,
  })
  const [hidden, setHidden] = useState(false)
  if (!data || hidden || readDismissed(data.date)) return null
  const { installments: inst, invoices: inv, due_today: today } = data
  if (inst.count === 0 && inv.count === 0 && today.count === 0) return null
  const hide = () => {
    try {
      localStorage.setItem(dismissKey, data.date)
    } catch {
      /* yok say */
    }
    setHidden(true)
  }
  const parts = [
    inst.count > 0 && `${num(inst.count)} taksit · ${money(inst.amount)} (${num(inst.students)} öğrenci)`,
    inv.count > 0 && `${num(inv.count)} fatura · ${money(inv.amount)}`,
  ].filter(Boolean)
  return (
    <Alert
      tone={inst.count > 0 || inv.count > 0 ? 'danger' : 'warning'}
      title={parts.length ? `Vadesi geçmiş ödemeler: ${parts.join(' + ')}` : `Bugün vadesi dolan ${num(today.count)} taksit`}
      action={
        <>
          {inst.count > 0 && <Link to="/finans/takip" className="inline-flex h-10 items-center text-[13px] font-medium text-ink underline-offset-2 hover:underline">Gecikme takibi</Link>}
          {inv.count > 0 && <Link to="/finans/faturalar?status=issued" className="inline-flex h-10 items-center text-[13px] font-medium text-ink underline-offset-2 hover:underline">Faturalar</Link>}
          <Button size="sm" variant="ghost" onClick={hide}>Bugün gizle</Button>
        </>
      }
    >
      {today.count > 0 ? (
        <Link to="/finans/alacaklar?status=pending" className="hover:underline">Bugün vadesi dolan: {num(today.count)} taksit · {money(today.amount)}</Link>
      ) : (
        'Bugün vadesi dolan taksit yok.'
      )}
    </Alert>
  )
}

export default OverdueBanner

import { useSearchParams } from 'react-router-dom'
import { ReceiptText } from 'lucide-react'
import { PageHeader, Tabs } from '@/components/ui/layout'
import { ButtonLink } from '@/components/ui/Button'
import CollectPayment from './CollectPayment'
import GuardianCollect from './GuardianCollect'

type Mode = 'ogrenci' | 'veli'

/**
 * Tahsilat al: tek öğrenci ve veli toplu (kardeşler) tahsilatı tek sayfada iki sekme.
 * Sekme URL'de tutulur (?kip=veli) — eski /finans/tahsilat/veli adresi buraya yönlenir,
 * ?veli=<id> gibi derin bağlantı parametreleri korunur.
 */
export default function CollectPage() {
  const [params, setParams] = useSearchParams()
  const mode: Mode = params.get('kip') === 'veli' ? 'veli' : 'ogrenci'

  const change = (v: Mode) =>
    setParams((p) => {
      if (v === 'veli') p.set('kip', 'veli')
      else p.delete('kip')
      // Diğer sekmenin seçim parametreleri taşınmasın
      p.delete(v === 'veli' ? 'ogrenci' : 'veli')
      p.delete('taksit')
      return p
    }, { replace: true })

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Tahsilat al"
        breadcrumbs={[{ label: 'Finans', to: '/finans' }, { label: 'Tahsilat al' }]}
        actions={<ButtonLink to="/finans/tahsilatlar" icon={<ReceiptText className="size-4" />}>Tahsilatlar</ButtonLink>}
      />

      <Tabs
        className="mb-4"
        value={mode}
        onChange={change}
        tabs={[
          { value: 'ogrenci', label: 'Tek öğrenci' },
          { value: 'veli', label: 'Veli toplu (kardeşler)' },
        ]}
      />

      {mode === 'veli' ? <GuardianCollect /> : <CollectPayment />}
    </div>
  )
}

import { useId } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { Input } from '@/components/ui/form'

type Opt = { id: number; name: string; district: string | null }

/** Okul alanı: kurumun okul listesinden öneri (Ayarlar › Okullar); listede olmayan okul da yazılabilir. */
export function SchoolInput({ value, onChange, disabled }: { value: string; onChange: (v: string) => void; disabled?: boolean }) {
  const id = useId()
  const schools = useQuery({
    queryKey: ['schools', 'options'],
    queryFn: () => api.get<{ data: Opt[] }>('/schools/options').catch(() => ({ data: [] as Opt[] })),
    staleTime: 10 * 60_000,
  })
  return (
    <>
      <Input value={value} onChange={(e) => onChange(e.target.value)} list={id} disabled={disabled} placeholder="Okul adını yazın ya da listeden seçin" autoComplete="off" />
      <datalist id={id}>
        {(schools.data?.data ?? []).map((s) => <option key={s.id} value={s.name}>{s.district ?? ''}</option>)}
      </datalist>
    </>
  )
}

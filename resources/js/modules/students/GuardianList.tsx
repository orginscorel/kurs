import { useEffect, useMemo, useState } from 'react'
import { MailText, PhoneText } from '@/components/ui/contact'
import { useNavigate } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Combine, Contact, Search, UserPlus } from 'lucide-react'
import { ButtonLink } from '@/components/ui/Button'
import { api, type ListMeta } from '@/lib/api'
import { relative } from '@/lib/format'
import { useCan } from '@/app/auth'
import { useDebounced, useListState } from '@/hooks/useListState'
import { PageHeader } from '@/components/ui/layout'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Avatar, Badge, EmptyState } from '@/components/ui/feedback'
import { Input, Switch } from '@/components/ui/form'
import { relationshipOptions } from './types'

type GuardianRow = {
  id: number
  name: string
  phone: string | null
  email: string | null
  occupation: string | null
  students: { id: number; full_name: string; status: string; relationship: string | null; is_primary: boolean }[]
  students_count: number
  overdue_balance: string | null
  open_balance: string | null
  /** Veli portalı hesabı; hesap yoksa null */
  portal: { is_active: boolean; last_login_at: string | null } | null
  /** Veliye giden son mesajın zamanı (SMS / WhatsApp / e-posta) */
  last_contact_at: string | null
  /** Aynı telefon/WhatsApp numarasıyla kayıtlı diğer veliler (olası mükerrer) */
  duplicate_ids?: number[]
}
type GuardianListResponse = { data: GuardianRow[]; meta: ListMeta & { duplicate_groups?: number; duplicate_guardians?: number } }

const relationshipLabel = (r: string | null) => (r === 'parent' ? 'Ebeveyn' : relationshipOptions.find((o) => o.value === r)?.label ?? null)

export default function GuardianList() {
  const can = useCan()
  const navigate = useNavigate()
  const list = useListState({ sort: 'name' })
  const [search, setSearch] = useState(list.q)
  const debounced = useDebounced(search, 300)

  useEffect(() => {
    if (debounced !== list.q) list.update({ q: debounced })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced])

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['guardians', list.query],
    queryFn: () => api.get<GuardianListResponse>('/guardians', list.query),
    placeholderData: keepPreviousData,
  })

  const columns = useMemo<Column<GuardianRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Veli',
        sortKey: 'name',
        cell: (g) => (
          <div className="flex items-center gap-3">
            <Avatar name={g.name} size={32} />
            <div className="min-w-0">
              <p className="font-medium truncate">{g.name}</p>
              <p className="flex items-center gap-1.5 text-[12px] text-ink-3 truncate">
                {(g.duplicate_ids?.length ?? 0) > 0 && <Badge tone="warning">Olası mükerrer</Badge>}
                {g.occupation && <span className="truncate">Meslek: {g.occupation}</span>}
              </p>
            </div>
          </div>
        ),
      },
      {
        key: 'relationship',
        header: 'Öğrenciye yakınlığı',
        hideable: true,
        // Aynı velinin birden çok çocuğu farklı yakınlıkta olabilir; benzersiz etiketler virgülle
        cell: (g) => {
          const labels = [...new Set(g.students.map((s) => relationshipLabel(s.relationship)).filter(Boolean))]
          return labels.length ? <span className="text-ink-2 whitespace-nowrap">{labels.join(', ')}</span> : <span className="text-ink-3">—</span>
        },
      },
      { key: 'phone', header: 'Cep telefonu', cell: (g) => <PhoneText value={g.phone} whatsapp /> },
      {
        key: 'students',
        header: 'Öğrenciler',
        cell: (g) => (
          <div className="flex flex-wrap gap-1 max-w-[260px]">
            {g.students.map((s) => (
              <Badge key={s.id} tone={s.status === 'active' ? 'primary' : 'neutral'}>{s.full_name}</Badge>
            ))}
            {g.students.length === 0 && <span className="text-ink-3">—</span>}
          </div>
        ),
      },
      {
        key: 'portal',
        header: 'Portal hesabı',
        hideable: true,
        cell: (g) =>
          g.portal ? (
            <div>
              <Badge tone={g.portal.is_active ? 'success' : 'warning'} dot>{g.portal.is_active ? 'Aktif' : 'Pasif'}</Badge>
              <p className="mt-0.5 text-[12px] text-ink-3 whitespace-nowrap">{g.portal.last_login_at ? `Son giriş: ${relative(g.portal.last_login_at)}` : 'Hiç giriş yapmadı'}</p>
            </div>
          ) : (
            <Badge tone="neutral">Hesap yok</Badge>
          ),
      },
      {
        key: 'contact',
        header: 'Son iletişim (mesaj/arama)',
        hideable: true,
        defaultHidden: true,
        cell: (g) => (g.last_contact_at ? <span className="text-ink-2 whitespace-nowrap">{relative(g.last_contact_at)}</span> : <span className="text-ink-3">—</span>),
      },
      { key: 'email', header: 'E-posta', hideable: true, defaultHidden: true, cell: (g) => <MailText value={g.email} /> },
      
    ],
    [can],
  )

  return (
    <div className="animate-fade-in">
      <PageHeader
        title="Veliler"
        description={data ? `${data.meta.total.toLocaleString('tr-TR')} veli` : 'Veli kayıtları'}
        actions={
          can('guardians.manage') && (data?.meta.duplicate_groups ?? 0) > 0 ? (
            <ButtonLink to="/veliler/birlestir" variant="warning" icon={<Combine className="size-4" />}>
              Mükerrer veliler ({data!.meta.duplicate_groups})
            </ButtonLink>
          ) : undefined
        }
      />
      <DataTable
        storageKey="guardians"
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isLoading || isFetching}
        meta={data?.meta}
        sort={list.sort}
        onSort={(sort) => list.update({ sort })}
        onPage={(page) => list.update({ page })}
        onRowClick={(r) => navigate(`/veliler/${r.id}`)}
        toolbar={
          <>
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Veli adı, telefon ya da öğrenci adı" leading={<Search />} className="w-full sm:w-[320px]" />
            {((data?.meta.duplicate_guardians ?? 0) > 0 || list.filters.duplicates === '1') && (
              <Switch checked={list.filters.duplicates === '1'} onChange={(v) => list.update({ filters: { duplicates: v ? '1' : null } })} label={`Olası mükerrerler${data?.meta.duplicate_guardians ? ` (${data.meta.duplicate_guardians})` : ''}`} />
            )}
          </>
        }
        empty={<EmptyState icon={<Contact />} title={list.q ? 'Aramanıza uyan veli yok' : 'Henüz veli yok'} description="Veliler öğrenci kaydı sırasında oluşturulur; önce öğrenciyi ekleyin." action={can('students.create') && !list.q ? <ButtonLink variant="primary" icon={<UserPlus className="size-4" />} to="/ogrenciler?yeni=1">Yeni öğrenci</ButtonLink> : undefined} />}
      />
    </div>
  )
}

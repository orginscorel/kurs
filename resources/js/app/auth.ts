import { create } from 'zustand'
import { api } from '@/lib/api'

export type Me = {
  user: {
    id: number
    name: string
    username: string
    email: string | null
    phone: string | null
    user_type: 'staff' | 'teacher' | 'student' | 'guardian'
    avatar_url: string | null
    must_change_password: boolean
    teacher_id: number | null
    student_id: number | null
    guardian_id: number | null
  }
  branch: { id: number; name: string; code: string } | null
  /** Oturumun kabuğu: öğrenci/veli portalı, yalnız öğretmen portalı ya da yönetim (null) */
  portal?: 'student' | 'guardian' | 'teacher' | null
  roles: string[]
  is_super_admin: boolean
  permissions: string[]
  institution: { name: string; short_name: string; logo_url: string | null; onboarding_completed: boolean }
  /** "Öğrenci/Veli/Öğretmen olarak giriş yap" önizlemesi (asıl personel sunucu oturumunda) */
  impersonation?: {
    kind?: 'student' | 'guardian' | 'teacher'
    impersonator_name: string
    target_name?: string
    student_name: string
    target_id?: number
    started_at: string
  } | null
}

type AuthState = {
  me: Me | null
  status: 'loading' | 'authenticated' | 'guest'
  permissionSet: Set<string>
  load: () => Promise<void>
  setMe: (me: Me | null) => void
  logout: () => Promise<void>
}

export const useAuth = create<AuthState>((set) => ({
  me: null,
  status: 'loading',
  permissionSet: new Set(),
  load: async () => {
    try {
      const me = await api.get<Me>('/auth/me')
      set({ me, status: 'authenticated', permissionSet: new Set(me.permissions) })
    } catch {
      set({ me: null, status: 'guest', permissionSet: new Set() })
    }
  },
  setMe: (me) => set({ me, status: me ? 'authenticated' : 'guest', permissionSet: new Set(me?.permissions ?? []) }),
  logout: async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      set({ me: null, status: 'guest', permissionSet: new Set() })
    }
  },
}))

/** Yetki denetimi: tek anahtar ya da listeden herhangi biri */
export function useCan() {
  const perms = useAuth((s) => s.permissionSet)
  const isSuper = useAuth((s) => s.me?.is_super_admin ?? false)
  return (permission: string | string[] | undefined) => {
    if (!permission) return true
    if (isSuper) return true
    return Array.isArray(permission) ? permission.some((p) => perms.has(p)) : perms.has(permission)
  }
}

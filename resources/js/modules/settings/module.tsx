import { Activity, Building2, ClipboardList, School, Settings, ShieldCheck, Users } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const SettingsHub = lazyPage(() => import('./SettingsHub'))
const InstitutionSettings = lazyPage(() => import('./InstitutionSettings'))
const SchoolSettings = lazyPage(() => import('./SchoolSettings'))
const UserList = lazyPage(() => import('./UserList'))
const RolesMatrix = lazyPage(() => import('./RolesMatrix'))
const AuditLogList = lazyPage(() => import('./AuditLogList'))
const SystemHealth = lazyPage(() => import('./SystemHealth'))
const OnboardingWizard = lazyPage(() => import('./OnboardingWizard'))

export default {
  id: 'settings',
  routes: [
    { path: 'ayarlar', element: page(<SettingsHub />) },
    { path: 'ayarlar/kurum', element: page(<InstitutionSettings />) },
    { path: 'ayarlar/okullar', element: page(<SchoolSettings />) },
    { path: 'ayarlar/kullanicilar', element: page(<UserList />) },
    { path: 'ayarlar/roller', element: page(<RolesMatrix />) },
    { path: 'ayarlar/denetim-kayitlari', element: page(<AuditLogList />) },
    { path: 'ayarlar/sistem-sagligi', element: page(<SystemHealth />) },
    { path: 'kurulum', element: page(<OnboardingWizard />) },
  ],
  nav: [
    { section: 'settings', label: 'Tüm ayarlar', to: '/ayarlar', icon: Settings, order: 0 },
    { section: 'settings', label: 'Kurum', to: '/ayarlar/kurum', icon: Building2, permission: 'settings.manage', order: 1 },
    { section: 'settings', label: 'Okullar', to: '/ayarlar/okullar', icon: School, permission: 'settings.manage', order: 2 },
    { section: 'settings', label: 'Kullanıcılar', to: '/ayarlar/kullanicilar', icon: Users, permission: 'users.manage', order: 2 },
    { section: 'settings', label: 'Roller ve yetkiler', to: '/ayarlar/roller', icon: ShieldCheck, permission: 'users.manage', order: 3 },
    { section: 'settings', label: 'Denetim kayıtları', to: '/ayarlar/denetim-kayitlari', icon: ClipboardList, permission: 'audit.view', order: 4 },
    { section: 'settings', label: 'Sistem sağlığı', to: '/ayarlar/sistem-sagligi', icon: Activity, permission: 'system.health', order: 5 },
  ],
  commands: [
    { id: 'settings-hub', label: 'Ayarlar (tüm ayarlar)', to: '/ayarlar', icon: Settings, keywords: ['ayar', 'ayarlar', 'yapılandırma', 'settings'] },
    { id: 'settings-institution', label: 'Kurum ayarları', to: '/ayarlar/kurum', icon: Building2, permission: 'settings.manage', keywords: ['kurum', 'logo', 'ayar'] },
    { id: 'settings-portal', label: 'Portal ayarları (veli talepleri, gecikme uyarısı)', to: '/ayarlar/kurum?sekme=portal', icon: Building2, permission: 'settings.manage', keywords: ['portal', 'veli', 'talep', 'öğretmen', 'disiplin'] },
    { id: 'settings-schools', label: 'Okul listesi', to: '/ayarlar/okullar', icon: School, permission: 'settings.manage', keywords: ['okul', 'lise', 'bölge'] },
    { id: 'settings-users', label: 'Kullanıcılar', to: '/ayarlar/kullanicilar', icon: Users, permission: 'users.manage', keywords: ['kullanıcı', 'hesap'] },
    { id: 'settings-roles', label: 'Roller', to: '/ayarlar/roller', icon: ShieldCheck, permission: 'users.manage', keywords: ['rol', 'yetki'] },
    { id: 'settings-audit', label: 'Denetim kayıtları', to: '/ayarlar/denetim-kayitlari', icon: ClipboardList, permission: 'audit.view', keywords: ['log', 'kayıt', 'denetim'] },
    { id: 'settings-health', label: 'Sistem sağlığı', to: '/ayarlar/sistem-sagligi', icon: Activity, permission: 'system.health', keywords: ['sağlık', 'yedek', 'kuyruk'] },
    { id: 'settings-backup', label: 'Yedek al', to: '/ayarlar/sistem-sagligi?tab=yedekler', icon: Activity, permission: 'system.health', keywords: ['yedek', 'backup'] },
  ],
} satisfies ModuleDef

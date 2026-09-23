import { Bell, BellRing, FileText, MessageCircle, Megaphone, Send, ShieldCheck, Zap } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const MessageList = lazyPage(() => import('./MessageList'))
const AnnouncementList = lazyPage(() => import('./AnnouncementList'))
const NotificationList = lazyPage(() => import('./NotificationList'))
const TemplateList = lazyPage(() => import('./TemplateList'))
const AutomationList = lazyPage(() => import('./AutomationList'))
const CampaignList = lazyPage(() => import('./campaigns/CampaignList'))
const CampaignComposer = lazyPage(() => import('./campaigns/CampaignComposer'))
const CampaignDetail = lazyPage(() => import('./campaigns/CampaignDetail'))
const ConsentList = lazyPage(() => import('./campaigns/ConsentList'))
const NotificationCenter = lazyPage(() => import('./notifications/NotificationCenter'))
const NotificationWizard = lazyPage(() => import('./notifications/NotificationWizard'))
const NotificationBatchDetail = lazyPage(() => import('./notifications/BatchDetail'))

export default {
  id: 'communication',
  routes: [
    { path: 'iletisim/whatsapp', element: page(<MessageList />) },
    { path: 'iletisim/duyurular', element: page(<AnnouncementList />) },
    { path: 'iletisim/bildirimler', element: page(<NotificationList />) },
    { path: 'iletisim/sablonlar', element: page(<TemplateList />) },
    { path: 'iletisim/otomasyonlar', element: page(<AutomationList />) },
    { path: 'iletisim/toplu-gonderim', element: page(<CampaignList />) },
    { path: 'iletisim/toplu-gonderim/yeni', element: page(<CampaignComposer />) },
    { path: 'iletisim/toplu-gonderim/:id', element: page(<CampaignDetail />) },
    { path: 'iletisim/toplu-gonderim/:id/duzenle', element: page(<CampaignComposer />) },
    { path: 'iletisim/ileti-izinleri', element: page(<ConsentList />) },
    { path: 'iletisim/bildirim-merkezi', element: page(<NotificationCenter />) },
    { path: 'iletisim/bildirim-merkezi/gonder', element: page(<NotificationWizard />) },
    { path: 'iletisim/bildirim-merkezi/gonderim/:id', element: page(<NotificationBatchDetail />) },
  ],
  nav: [
    { section: 'communication', label: 'WhatsApp', to: '/iletisim/whatsapp', icon: MessageCircle, permission: 'messages.view', order: 1 },
    { section: 'communication', label: 'Toplu gönderim', to: '/iletisim/toplu-gonderim', icon: Send, permission: ['messages.campaign', 'messages.campaign_send'], order: 1.5 },
    { section: 'communication', label: 'Bildirim Merkezi', to: '/iletisim/bildirim-merkezi', icon: BellRing, permission: 'messages.view', order: 1.7 },
    { section: 'communication', label: 'Duyurular', to: '/iletisim/duyurular', icon: Megaphone, permission: 'announcements.manage', order: 2 },
    { section: 'communication', label: 'İleti izinleri', to: '/iletisim/ileti-izinleri', icon: ShieldCheck, permission: 'messages.consents', order: 2.5 },
    { section: 'communication', label: 'Bildirimler', to: '/iletisim/bildirimler', icon: Bell, order: 3 },
    { section: 'communication', label: 'Mesaj Şablonları', to: '/iletisim/sablonlar', icon: FileText, permission: 'templates.manage', order: 4 },
    { section: 'communication', label: 'Otomasyonlar', to: '/iletisim/otomasyonlar', icon: Zap, permission: 'automations.manage', order: 5 },
  ],
  commands: [
    { id: 'whatsapp-send', label: 'WhatsApp gönder', to: '/iletisim/whatsapp?yeni=1', icon: MessageCircle, permission: 'messages.send', keywords: ['mesaj', 'whatsapp', 'gönder'] },
    { id: 'announcement-publish', label: 'Duyuru yayımla', to: '/iletisim/duyurular?yeni=1', icon: Megaphone, permission: 'announcements.manage', keywords: ['duyuru', 'yayımla'] },
    { id: 'campaign-new', label: 'Toplu SMS / e-posta gönder', to: '/iletisim/toplu-gonderim/yeni', icon: Send, permission: 'messages.campaign', keywords: ['toplu', 'sms', 'e-posta', 'mail', 'kampanya', 'duyuru', 'reklam'] },
    { id: 'notification-send', label: 'Bildirim gönder (olay)', to: '/iletisim/bildirim-merkezi/gonder', icon: BellRing, permission: 'messages.send', keywords: ['bildirim', 'whatsapp', 'program', 'rehberlik', 'koçluk', 'veli', 'öğrenci'] },
    { id: 'message-consents', label: 'İleti izinleri (İYS)', to: '/iletisim/ileti-izinleri', icon: ShieldCheck, permission: 'messages.consents', keywords: ['izin', 'iys', 'onay', 'ret', 'abonelik'] },
    { id: 'automations', label: 'Otomasyonlar', to: '/iletisim/otomasyonlar', icon: Zap, permission: 'automations.manage', keywords: ['otomasyon', 'kural'] },
  ],
} satisfies ModuleDef

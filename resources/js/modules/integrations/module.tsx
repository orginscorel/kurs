import { MessagesSquare, Plug, Webhook as WebhookIcon } from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const IntegrationList = lazyPage(() => import('./IntegrationList'))
const WebhookList = lazyPage(() => import('./WebhookList'))
const MessagingChannels = lazyPage(() => import('./MessagingChannels'))

export default {
  id: 'integrations',
  routes: [
    { path: 'ayarlar/entegrasyonlar', element: page(<IntegrationList />) },
    { path: 'ayarlar/webhooklar', element: page(<WebhookList />) },
    { path: 'ayarlar/mesaj-kanallari', element: page(<MessagingChannels />) },
  ],
  nav: [
    { section: 'settings', label: 'Mesaj kanalları', to: '/ayarlar/mesaj-kanallari', icon: MessagesSquare, permission: ['integrations.manage', 'integrations.sms', 'integrations.email'], order: 9 },
    { section: 'settings', label: 'Entegrasyonlar', to: '/ayarlar/entegrasyonlar', icon: Plug, permission: 'integrations.manage', order: 10 },
    { section: 'settings', label: 'Webhooklar', to: '/ayarlar/webhooklar', icon: WebhookIcon, permission: 'integrations.manage', order: 11 },
  ],
  commands: [
    { id: 'messaging-channels', label: 'Mesaj kanalları (SMS / e-posta)', to: '/ayarlar/mesaj-kanallari', icon: MessagesSquare, permission: 'integrations.sms', keywords: ['sms', 'smtp', 'netgsm', 'mutlucell', 'vatansms', 'e-posta'] },
    { id: 'integrations', label: 'Entegrasyonlar', to: '/ayarlar/entegrasyonlar', icon: Plug, permission: 'integrations.manage', keywords: ['whatsapp', 'sms', 'entegrasyon', 'bağlantı'] },
  ],
} satisfies ModuleDef

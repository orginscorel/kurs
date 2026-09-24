import {
  AlarmClock, BookText, FileSignature, CircleDollarSign, FilePlus2, FileSpreadsheet, FileText, HandCoins, Inbox, Landmark, LayoutGrid, Lock, Package, PhoneCall,
  ReceiptText, Scale, Settings2, TrendingDown, Undo2, UserPlus, Users, Wallet,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'
import { FinanceNav } from './FinanceNav'

const FinanceHome = lazyPage(() => import('./FinanceHome'))
const CollectPage = lazyPage(() => import('./CollectPage'))
const PaymentList = lazyPage(() => import('./PaymentList'))
const Receivables = lazyPage(() => import('./Receivables'))
const EnrollmentList = lazyPage(() => import('./EnrollmentList'))
const EnrollmentCreate = lazyPage(() => import('./EnrollmentCreate'))
const EnrollmentDetail = lazyPage(() => import('./EnrollmentDetail'))
const Entries = lazyPage(() => import('./Entries'))
const Accounts = lazyPage(() => import('./Accounts'))
const AccountLedger = lazyPage(() => import('./AccountLedger'))
const Reports = lazyPage(() => import('./Reports'))
const Packages = lazyPage(() => import('./Packages'))
const PackageRequests = lazyPage(() => import('./PackageRequests'))
const Inventory = lazyPage(() => import('./Inventory'))
const Invoices = lazyPage(() => import('./Invoices'))
const InvoiceEditor = lazyPage(() => import('./InvoiceEditor'))
const Accounting = lazyPage(() => import('./Accounting'))
const Reconciliation = lazyPage(() => import('./Reconciliation'))
const Collections = lazyPage(() => import('./Collections'))
const Refunds = lazyPage(() => import('./Refunds'))
const FinanceSettings = lazyPage(() => import('./FinanceSettings'))
const PromissoryNotes = lazyPage(() => import('./PromissoryNotes'))

/** Eski /finans/tahsilat/veli adresi: "Veli toplu" sekmesine, mevcut sorgu parametreleri korunarak. */
function GuardianCollectRedirect() {
  const { search } = useLocation()
  const params = new URLSearchParams(search)
  params.set('kip', 'veli')
  return <Navigate to={`/finans/tahsilat?${params.toString()}`} replace />
}

/**
 * Bölüm sekmeleri (FinanceNav) üstte, sayfa içeriği altta. Sayfanın kendi başlığı ve
 * `?sekme=` iç sekmeleri korunur; şerit yalnız aynı gruptaki sayfalar arası geçiş içindir.
 * FinanceNav Suspense DIŞINDA kalır ki sayfa tembel yüklenirken bile görünür.
 */
const section = (node: ReactNode) => (
  <>
    <FinanceNav />
    {page(node)}
  </>
)

/**
 * Finans modülü. Rotalar /finans altında; tahsilat ekranı öğrenci profilinden
 * /finans/tahsilat?ogrenci=ID (isteğe bağlı &taksit=1,2) ile açılır.
 * Veli toplu tahsilat aynı sayfanın ikinci sekmesidir: /finans/tahsilat?kip=veli
 *
 * Kenar menüsü 3 üst girdiye indirildi (Finans / Kayıt ve sözleşmeler / Muhasebe ve raporlar);
 * gruptaki diğer sayfalara FinanceNav sekme şeridinden ulaşılır. Tüm rotalar ve komut paleti
 * girdileri olduğu gibi korunur (derin bağlantılar kırılmaz).
 */
export default {
  id: 'finance',
  routes: [
    { path: 'finans', element: section(<FinanceHome />) },
    { path: 'finans/tahsilat', element: section(<CollectPage />) },
    { path: 'finans/tahsilatlar', element: section(<PaymentList />) },
    { path: 'finans/alacaklar', element: section(<Receivables />) },
    { path: 'finans/kayitlar', element: section(<EnrollmentList />) },
    { path: 'finans/kayitlar/yeni', element: page(<EnrollmentCreate />) },
    { path: 'finans/kayitlar/:id', element: page(<EnrollmentDetail />) },
    { path: 'finans/gelir-gider', element: section(<Entries />) },
    { path: 'finans/hesaplar', element: section(<Accounts />) },
    { path: 'finans/hesaplar/:id', element: page(<AccountLedger />) },
    { path: 'finans/raporlar', element: section(<Reports />) },
    { path: 'finans/paketler', element: section(<Packages />) },
    { path: 'finans/paket-talepleri', element: section(<PackageRequests />) },
    { path: 'finans/envanter', element: section(<Inventory />) },
    { path: 'finans/tahsilat/veli', element: <GuardianCollectRedirect /> },
    { path: 'finans/faturalar', element: section(<Invoices />) },
    { path: 'finans/faturalar/yeni', element: page(<InvoiceEditor />) },
    { path: 'finans/faturalar/:id', element: page(<InvoiceEditor />) },
    { path: 'finans/iadeler', element: section(<Refunds />) },
    { path: 'finans/takip', element: section(<Collections />) },
    { path: 'finans/mutabakat', element: section(<Reconciliation />) },
    { path: 'finans/muhasebe', element: section(<Accounting />) },
    { path: 'finans/ayarlar', element: page(<FinanceSettings />) },
    { path: 'finans/senetler', element: section(<PromissoryNotes />) },
  ],
  // Finans kenar menüsü yalnız 3 üst girdi; grup içi geçiş FinanceNav sekme şeridiyle. Rotalar/komutlar korunur.
  // Paketler ve Ayarlar navigation.ts'deki MOVE_TO ile "Ayarlar" bölümünde gösterilir (Finans grubunu şişirmez).
  nav: [
    { section: 'finance', label: 'Finans', to: '/finans', icon: LayoutGrid, permission: 'finance.view', end: true, order: 1 },
    { section: 'finance', label: 'Kayıt ve sözleşmeler', to: '/finans/kayitlar', icon: FileSignature, permission: 'finance.view', order: 2 },
    { section: 'finance', label: 'Muhasebe ve raporlar', to: '/finans/gelir-gider', icon: BookText, permission: 'finance.view', order: 3 },
    { section: 'finance', label: 'Eğitim Paketleri', to: '/finans/paketler', icon: Package, permission: 'finance.view', order: 9 },
    { section: 'finance', label: 'Finans ve fatura', to: '/finans/ayarlar', icon: Settings2, permission: ['settings.manage', 'finance.accounting'], order: 30 },
  ],
  commands: [
    { id: 'finance-collect', label: 'Tahsilat Yap', to: '/finans/tahsilat', icon: HandCoins, permission: 'payments.create', hint: 'Finans', keywords: ['tahsilat', 'ödeme al', 'makbuz', 'para'] },
    { id: 'finance-expense', label: 'Gider Ekle', to: '/finans/gelir-gider?yeni=expense', icon: TrendingDown, permission: 'expenses.manage', hint: 'Finans', keywords: ['gider', 'masraf', 'fatura', 'harcama'] },
    { id: 'finance-income', label: 'Gelir Ekle', to: '/finans/gelir-gider?yeni=income', icon: CircleDollarSign, permission: 'expenses.manage', hint: 'Finans', keywords: ['gelir'] },
    { id: 'finance-installments', label: 'Taksitler', to: '/finans/alacaklar', icon: Wallet, permission: 'finance.view', hint: 'Finans', keywords: ['taksit', 'alacak', 'vade'] },
    { id: 'finance-overdue', label: 'Gecikmiş ödemeler', to: '/finans/alacaklar?status=overdue', icon: AlarmClock, permission: 'finance.view', hint: 'Finans', keywords: ['gecikmiş', 'geciken', 'borç', 'vadesi geçmiş'] },
    { id: 'finance-payments', label: 'Tahsilat listesi', to: '/finans/tahsilatlar', icon: ReceiptText, permission: 'finance.view', hint: 'Finans', keywords: ['makbuz', 'tahsilatlar'] },
    { id: 'finance-enroll', label: 'Yeni dönem kaydı', to: '/finans/kayitlar/yeni', icon: UserPlus, permission: 'enrollments.create', hint: 'Finans', keywords: ['kayıt', 'sözleşme', 'ödeme planı'] },
    { id: 'package-new', label: 'Paket tanımla', to: '/finans/paketler?yeni=1', icon: Package, permission: 'installments.manage', hint: 'Eğitim paketi', keywords: ['paket', 'tanımla', 'ekle', 'yeni', 'fiyat', 'eğitim paketi'] },
    { id: 'finance-package-requests', label: 'Paket talepleri', to: '/finans/paket-talepleri', icon: Inbox, permission: 'package_requests.manage', hint: 'Finans', keywords: ['paket talebi', 'koçluk talebi', 'portal talep', 'paket ekle'] },
    { id: 'finance-dayend', label: 'Gün sonu kasa özeti', to: '/finans/hesaplar?sekme=gun-sonu', icon: Landmark, permission: 'finance.view', hint: 'Finans', keywords: ['gün sonu', 'kasa', 'banka'] },
    { id: 'finance-invoice-new', label: 'Fatura kes', to: '/finans/faturalar/yeni', icon: FilePlus2, permission: 'finance.invoice', hint: 'Finans', keywords: ['fatura', 'e-arşiv', 'e-fatura', 'kdv'] },
    { id: 'finance-unbilled', label: 'Faturalanmamış tahsilatlar', to: '/finans/faturalar?sekme=faturalanmamis', icon: FileText, permission: 'finance.view', hint: 'Finans', keywords: ['fatura', 'toplu fatura', 'taslak'] },
    { id: 'finance-guardian-bulk', label: 'Veli toplu tahsilat', to: '/finans/tahsilat?kip=veli', icon: Users, permission: 'payments.create', hint: 'Finans', keywords: ['kardeş', 'veli', 'toplu tahsilat'] },
    { id: 'finance-collections', label: 'Gecikme takibi', to: '/finans/takip', icon: PhoneCall, permission: 'finance.view', hint: 'Finans', keywords: ['ödeme sözü', 'hatırlatma', 'yaşlandırma', 'arama'] },
    { id: 'finance-refunds', label: 'İadeler', to: '/finans/iadeler', icon: Undo2, permission: 'finance.view', hint: 'Finans', keywords: ['iade', 'geri ödeme'] },
    { id: 'finance-reconcile', label: 'Banka / POS mutabakatı', to: '/finans/mutabakat', icon: Scale, permission: 'finance.view', hint: 'Finans', keywords: ['mutabakat', 'pos', 'komisyon', 'banka ekstresi', 'yatış'] },
    { id: 'finance-journal', label: 'Yevmiye defteri', to: '/finans/muhasebe', icon: BookText, permission: 'finance.accounting', hint: 'Muhasebe', keywords: ['yevmiye', 'fiş', 'muhasebe'] },
    { id: 'finance-trial-balance', label: 'Mizan', to: '/finans/muhasebe?sekme=mizan', icon: BookText, permission: 'finance.accounting', hint: 'Muhasebe', keywords: ['mizan', 'bakiye', 'muhasebeci'] },
    { id: 'finance-period', label: 'Dönem kapat', to: '/finans/muhasebe?sekme=donem', icon: Lock, permission: 'finance.period_close', hint: 'Muhasebe', keywords: ['dönem kilidi', 'ay kapanışı'] },
    { id: 'finance-analytics', label: 'Gelir tablosu ve nakit akışı', to: '/raporlar/finans-analiz', icon: FileSpreadsheet, permission: 'reports.finance', hint: 'Raporlar', keywords: ['gelir tablosu', 'nakit akışı', 'kârlılık', 'kdv özeti'] },
    { id: 'finance-notes', label: 'Toplu senet basımı', to: '/finans/senetler', icon: FileSignature, permission: 'installments.manage', hint: 'Finans', keywords: ['senet', 'bono', 'yazdır', 'taksit senedi'] },
    { id: 'finance-reports', label: 'Finans raporları', to: '/finans/raporlar', icon: FileSpreadsheet, permission: 'reports.finance', hint: 'Finans', keywords: ['ciro', 'rapor', 'gelir gider'] },
  ],
} satisfies ModuleDef

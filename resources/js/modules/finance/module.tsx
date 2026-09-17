import {
  AlarmClock, BookOpen, BookText, FileSignature, CircleDollarSign, FilePlus2, FileSpreadsheet, FileText, HandCoins, Landmark, LayoutGrid, Lock, Package, PhoneCall,
  ReceiptText, Scale, Settings2, TrendingDown, Undo2, UserPlus, Users, Wallet,
} from 'lucide-react'
import { lazyPage } from '@/app/lazy'
import { page } from '@/app/page'
import type { ModuleDef } from '@/app/modules'

const FinanceHome = lazyPage(() => import('./FinanceHome'))
const CollectPayment = lazyPage(() => import('./CollectPayment'))
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
const Inventory = lazyPage(() => import('./Inventory'))
const Invoices = lazyPage(() => import('./Invoices'))
const InvoiceEditor = lazyPage(() => import('./InvoiceEditor'))
const Accounting = lazyPage(() => import('./Accounting'))
const Reconciliation = lazyPage(() => import('./Reconciliation'))
const Collections = lazyPage(() => import('./Collections'))
const Refunds = lazyPage(() => import('./Refunds'))
const GuardianCollect = lazyPage(() => import('./GuardianCollect'))
const FinanceSettings = lazyPage(() => import('./FinanceSettings'))
const PromissoryNotes = lazyPage(() => import('./PromissoryNotes'))

/**
 * Finans modülü. Rotalar /finans altında; tahsilat ekranı öğrenci profilinden
 * /finans/tahsilat?ogrenci=ID (isteğe bağlı &taksit=1,2) ile açılır.
 */
export default {
  id: 'finance',
  routes: [
    { path: 'finans', element: page(<FinanceHome />) },
    { path: 'finans/tahsilat', element: page(<CollectPayment />) },
    { path: 'finans/tahsilatlar', element: page(<PaymentList />) },
    { path: 'finans/alacaklar', element: page(<Receivables />) },
    { path: 'finans/kayitlar', element: page(<EnrollmentList />) },
    { path: 'finans/kayitlar/yeni', element: page(<EnrollmentCreate />) },
    { path: 'finans/kayitlar/:id', element: page(<EnrollmentDetail />) },
    { path: 'finans/gelir-gider', element: page(<Entries />) },
    { path: 'finans/hesaplar', element: page(<Accounts />) },
    { path: 'finans/hesaplar/:id', element: page(<AccountLedger />) },
    { path: 'finans/raporlar', element: page(<Reports />) },
    { path: 'finans/paketler', element: page(<Packages />) },
    { path: 'finans/envanter', element: page(<Inventory />) },
    { path: 'finans/tahsilat/veli', element: page(<GuardianCollect />) },
    { path: 'finans/faturalar', element: page(<Invoices />) },
    { path: 'finans/faturalar/yeni', element: page(<InvoiceEditor />) },
    { path: 'finans/faturalar/:id', element: page(<InvoiceEditor />) },
    { path: 'finans/iadeler', element: page(<Refunds />) },
    { path: 'finans/takip', element: page(<Collections />) },
    { path: 'finans/mutabakat', element: page(<Reconciliation />) },
    { path: 'finans/muhasebe', element: page(<Accounting />) },
    { path: 'finans/ayarlar', element: page(<FinanceSettings />) },
    { path: 'finans/senetler', element: page(<PromissoryNotes />) },
  ],
  nav: [
    { section: 'finance', label: 'Finans Merkezi', to: '/finans', icon: LayoutGrid, permission: 'finance.view', end: true, order: 1 },
    { section: 'finance', label: 'Tahsilat Al', to: '/finans/tahsilat', icon: HandCoins, permission: 'payments.create', order: 2 },
    { section: 'finance', label: 'Tahsilatlar', to: '/finans/tahsilatlar', icon: ReceiptText, permission: 'finance.view', order: 3 },
    { section: 'finance', label: 'Taksit ve Alacaklar', to: '/finans/alacaklar', icon: AlarmClock, permission: 'finance.view', order: 4 },
    { section: 'finance', label: 'Faturalar', to: '/finans/faturalar', icon: FileText, permission: 'finance.view', order: 4.5 },
    { section: 'finance', label: 'Senetler', to: '/finans/senetler', icon: FileSignature, permission: ['installments.manage', 'enrollments.create', 'finance.invoice'], order: 5.5 },
    { section: 'finance', label: 'Kayıt ve Planlar', to: '/finans/kayitlar', icon: Scale, permission: 'finance.view', order: 5 },
    { section: 'finance', label: 'Gelir ve Gider', to: '/finans/gelir-gider', icon: TrendingDown, permission: 'finance.view', order: 6 },
    { section: 'finance', label: 'Kasa ve Banka', to: '/finans/hesaplar', icon: Landmark, permission: 'finance.view', order: 7 },
    { section: 'finance', label: 'Muhasebe', to: '/finans/muhasebe', icon: BookText, permission: 'finance.accounting', order: 7.5 },
    { section: 'finance', label: 'Finans Raporları', to: '/finans/raporlar', icon: FileSpreadsheet, permission: 'reports.finance', order: 8 },
    { section: 'finance', label: 'Veli toplu tahsilat', to: '/finans/tahsilat/veli', icon: Users, permission: 'payments.create', order: 20 },
    { section: 'finance', label: 'İadeler', to: '/finans/iadeler', icon: Undo2, permission: 'finance.view', order: 21 },
    { section: 'finance', label: 'Gecikme takibi', to: '/finans/takip', icon: PhoneCall, permission: 'finance.view', order: 22 },
    { section: 'finance', label: 'Mutabakat', to: '/finans/mutabakat', icon: Scale, permission: 'finance.view', order: 23 },
    { section: 'finance', label: 'Finans ve fatura', to: '/finans/ayarlar', icon: Settings2, permission: ['settings.manage', 'finance.accounting'], order: 30 },
    { section: 'finance', label: 'Eğitim Paketleri', to: '/finans/paketler', icon: Package, permission: 'finance.view', order: 9 },
    { section: 'finance', label: 'Kitap ve Materyal', to: '/finans/envanter', icon: BookOpen, permission: ['finance.view', 'inventory.manage'], order: 10 },
  ],
  commands: [
    { id: 'finance-collect', label: 'Tahsilat Yap', to: '/finans/tahsilat', icon: HandCoins, permission: 'payments.create', hint: 'Finans', keywords: ['tahsilat', 'ödeme al', 'makbuz', 'para'] },
    { id: 'finance-expense', label: 'Gider Ekle', to: '/finans/gelir-gider?yeni=expense', icon: TrendingDown, permission: 'expenses.manage', hint: 'Finans', keywords: ['gider', 'masraf', 'fatura', 'harcama'] },
    { id: 'finance-income', label: 'Gelir Ekle', to: '/finans/gelir-gider?yeni=income', icon: CircleDollarSign, permission: 'expenses.manage', hint: 'Finans', keywords: ['gelir'] },
    { id: 'finance-installments', label: 'Taksitler', to: '/finans/alacaklar', icon: Wallet, permission: 'finance.view', hint: 'Finans', keywords: ['taksit', 'alacak', 'vade'] },
    { id: 'finance-overdue', label: 'Gecikmiş ödemeler', to: '/finans/alacaklar?status=overdue', icon: AlarmClock, permission: 'finance.view', hint: 'Finans', keywords: ['gecikmiş', 'geciken', 'borç', 'vadesi geçmiş'] },
    { id: 'finance-payments', label: 'Tahsilat listesi', to: '/finans/tahsilatlar', icon: ReceiptText, permission: 'finance.view', hint: 'Finans', keywords: ['makbuz', 'tahsilatlar'] },
    { id: 'finance-enroll', label: 'Yeni dönem kaydı', to: '/finans/kayitlar/yeni', icon: UserPlus, permission: 'enrollments.create', hint: 'Finans', keywords: ['kayıt', 'sözleşme', 'ödeme planı'] },
    { id: 'finance-dayend', label: 'Gün sonu kasa özeti', to: '/finans/hesaplar?sekme=gun-sonu', icon: Landmark, permission: 'finance.view', hint: 'Finans', keywords: ['gün sonu', 'kasa', 'banka'] },
    { id: 'finance-invoice-new', label: 'Fatura kes', to: '/finans/faturalar/yeni', icon: FilePlus2, permission: 'finance.invoice', hint: 'Finans', keywords: ['fatura', 'e-arşiv', 'e-fatura', 'kdv'] },
    { id: 'finance-unbilled', label: 'Faturalanmamış tahsilatlar', to: '/finans/faturalar?sekme=faturalanmamis', icon: FileText, permission: 'finance.view', hint: 'Finans', keywords: ['fatura', 'toplu fatura', 'taslak'] },
    { id: 'finance-guardian-bulk', label: 'Veli toplu tahsilat', to: '/finans/tahsilat/veli', icon: Users, permission: 'payments.create', hint: 'Finans', keywords: ['kardeş', 'veli', 'toplu tahsilat'] },
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

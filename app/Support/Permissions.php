<?php

namespace App\Support;

/**
 * Yetki kataloğu: tek doğruluk kaynağı. Rol varsayılanları seeder ile yazılır;
 * yönetici panelden özel rol oluşturup bu listeden seçer.
 */
final class Permissions
{
    /** @return array<string, array{label: string, items: array<string, string>}> */
    public static function catalog(): array
    {
        return [
            'dashboard' => ['label' => 'Genel', 'items' => [
                'dashboard.view' => 'Yönetim panosunu görme',
                'operations.view' => 'Günlük operasyon ekranı',
                'search.global' => 'Global arama',
            ]],
            'students' => ['label' => 'Öğrenciler', 'items' => [
                'students.view' => 'Öğrencileri görme',
                'students.create' => 'Öğrenci ekleme',
                'students.update' => 'Öğrenci düzenleme',
                'students.delete' => 'Öğrenci silme',
                'students.export' => 'Öğrenci dışa aktarma',
                'students.view_sensitive' => 'TC kimlik ve telefonları açık görme',
                'students.bulk' => 'Toplu işlemler',
                'students.credentials' => 'Öğrenci portal şifresini görme/sıfırlama',
                'students.impersonate' => 'Öğrenci olarak giriş yapma (önizleme)',
                'guardians.view' => 'Velileri görme',
                'guardians.manage' => 'Veli ekleme/düzenleme',
                'guardians.credentials' => 'Veli portal şifresini görme/sıfırlama',
                'guardians.impersonate' => 'Veli olarak giriş yapma (önizleme)',
                'documents.view' => 'Belgeleri görme',
                'documents.manage' => 'Belge yükleme/silme',
            ]],
            'staff' => ['label' => 'Öğretmen ve Personel', 'items' => [
                'teachers.view' => 'Öğretmenleri görme',
                'teachers.manage' => 'Öğretmen ekleme/düzenleme',
                'employees.view' => 'Personeli görme',
                'employees.manage' => 'Personel ekleme/düzenleme',
                'teachers.credentials' => 'Öğretmen portal şifresini görme/sıfırlama',
                'teachers.impersonate' => 'Öğretmen olarak giriş yapma (önizleme)',
            ]],
            'teacher_portal' => ['label' => 'Öğretmen Portalı', 'items' => [
                'teacher_portal.access' => 'Öğretmen portalını kullanma (yalnız kendi sınıf ve öğrencileri)',
                'teacher_portal.attendance' => 'Portalda kendi derslerinin yoklamasını alma/düzeltme',
                'teacher_portal.homework' => 'Portalda ödev verme, teslim kontrolü ve puanlama',
                'teacher_portal.observations' => 'Portalda öğrenci gözlem notu / davranış puanı ekleme',
            ]],
            'crm' => ['label' => 'CRM', 'items' => [
                'crm.view' => 'Adayları görme',
                'crm.manage' => 'Aday ekleme/düzenleme',
                'enrollments.create' => 'Kayıt oluşturma',
            ]],
            'academic' => ['label' => 'Akademik', 'items' => [
                'academic.view' => 'Program, sınıf, ders ve derslikleri görme',
                'academic.manage' => 'Program, sınıf, ders ve derslik yönetimi',
                'classroom_layouts.manage' => '3D derslik tasarımı ve oturma düzeni düzenleme',
                'schedule.view' => 'Ders programını görme',
                'schedule.manage' => 'Ders programı düzenleme',
                'study.view' => 'Etüt/birebir görme',
                'study.manage' => 'Etüt/birebir yönetimi',
                'homework.view' => 'Ödevleri görme',
                'homework.manage' => 'Ödev verme/değerlendirme',
            ]],
            'exams' => ['label' => 'Sınav Merkezi', 'items' => [
                'exams.view' => 'Sınavları ve sonuçları görme',
                'exams.manage' => 'Sınav oluşturma, cevap anahtarı',
                'exams.import' => 'Optik okuma içe aktarma',
                'exams.publish' => 'Sonuç yayımlama',
            ]],
            'attendance' => ['label' => 'Yoklama', 'items' => [
                'attendance.view' => 'Yoklama ve devamsızlık görme',
                'attendance.take' => 'Yoklama alma',
                'attendance.override' => 'Yoklama düzeltme (yönetici)',
                'presence.live' => 'Canlı giriş/çıkış',
                'devices.manage' => 'Cihaz yönetimi',
            ]],
            'guidance' => ['label' => 'Rehberlik', 'items' => [
                'guidance.view' => 'Rehberlik kayıtlarını görme',
                'guidance.manage' => 'Görüşme kaydetme',
                'guidance.private' => 'Gizli rehberlik notlarını görme',
                'risk.view' => 'Riskli öğrenci ekranı',
            ]],
            'discipline' => ['label' => 'Disiplin', 'items' => [
                'discipline.view' => 'Disiplin kayıtlarını görme',
                'discipline.create' => 'Olay kaydetme, savunma isteme/kaydetme',
                'discipline.decide' => 'Yaptırım verme (kurul gerektirmeyen) ve itiraz kararı',
                'discipline.board' => 'Disiplin kurulu (toplantı, oy, karar)',
                'discipline.settings' => 'Davranış kataloğu ve disiplin ayarları',
                'discipline.export' => 'Disiplin raporu / belge dışa aktarma',
            ]],
            'finance' => ['label' => 'Finans', 'items' => [
                'finance.view' => 'Finans verilerini görme',
                'payments.create' => 'Tahsilat alma',
                'payments.void' => 'Tahsilat iptali',
                'installments.manage' => 'Ödeme planı/taksit düzenleme',
                'expenses.manage' => 'Gelir/gider kaydetme',
                'accounts.manage' => 'Kasa/banka yönetimi ve transfer',
                'inventory.manage' => 'Kitap/materyal stoğu',
                'finance.invoice' => 'Fatura / e-Arşiv taslağı oluşturma, kesme, iptal',
                'finance.refund' => 'Tahsilat iadesi yapma/iptal etme',
                'finance.reconcile' => 'Banka / POS mutabakatı',
                'finance.collections' => 'Gecikme takibi: ödeme sözü, not, hatırlatma taslağı',
                'finance.accounting' => 'Muhasebe: yevmiye, mizan, hesap planı ve eşleme',
                'finance.period_close' => 'Muhasebe dönemini kapatma / yeniden açma',
            ]],
            'communication' => ['label' => 'İletişim', 'items' => [
                'messages.view' => 'Mesaj geçmişini görme',
                'messages.send' => 'WhatsApp/SMS gönderme',
                'messages.campaign' => 'Toplu e-posta/SMS taslağı hazırlama ve önizleme',
                'messages.campaign_send' => 'Toplu e-posta/SMS gönderimini onaylama, iptal, yeniden deneme',
                'messages.consents' => 'Ticari ileti izinleri (İYS) ve ret listesi',
                'announcements.manage' => 'Duyuru yayımlama',
                'templates.manage' => 'Mesaj şablonları',
                'automations.manage' => 'Otomasyon kuralları',
            ]],
            'reports' => ['label' => 'Raporlar', 'items' => [
                'reports.view' => 'Raporları görme',
                'reports.finance' => 'Finans raporları',
                'reports.export' => 'Rapor dışa aktarma',
            ]],
            'settings' => ['label' => 'Ayarlar', 'items' => [
                'settings.manage' => 'Kurum ayarları',
                'users.manage' => 'Kullanıcı ve rol yönetimi',
                'integrations.manage' => 'Entegrasyonlar ve webhook',
                'integrations.sms' => 'SMS sağlayıcı ayarları',
                'integrations.email' => 'E-posta (SMTP) ayarları',
                'audit.view' => 'Denetim kayıtları',
                'system.health' => 'Sistem sağlığı ve yedekler',
                'imports.manage' => 'Excel içe aktarma',
                'sync.use' => 'Masaüstü/mobil uygulamayla eşitleme (cihaz eşleştirme)',
                'sync.manage' => 'Bağlı cihazlar ve eşitleme çakışmaları',
            ]],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return collect(self::catalog())->flatMap(fn ($g) => array_keys($g['items']))->values()->all();
    }

    /**
     * Varsayılan roller. super-admin yetki denetimini tümüyle geçer (Gate::before).
     *
     * @return array<string, array{label: string, permissions: list<string>|'*'}>
     */
    public static function defaultRoles(): array
    {
        $viewAll = ['dashboard.view', 'operations.view', 'search.global', 'students.view', 'guardians.view', 'teachers.view',
            'academic.view', 'schedule.view', 'exams.view', 'attendance.view', 'presence.live', 'study.view', 'homework.view'];

        return [
            'super-admin' => ['label' => 'Sistem Yöneticisi', 'permissions' => '*'],
            'yonetici' => ['label' => 'Yönetici', 'permissions' => array_values(array_diff(self::all(), ['system.health']))],
            'mudur' => ['label' => 'Müdür', 'permissions' => array_merge($viewAll, [
                'students.create', 'students.update', 'students.export', 'students.bulk', 'students.credentials', 'students.impersonate', 'guardians.manage', 'guardians.credentials', 'guardians.impersonate', 'documents.view', 'documents.manage',
                'teachers.manage', 'teachers.credentials', 'teachers.impersonate', 'employees.view', 'crm.view', 'crm.manage', 'enrollments.create', 'academic.manage', 'classroom_layouts.manage', 'schedule.manage',
                'study.manage', 'homework.manage', 'exams.manage', 'exams.import', 'exams.publish', 'attendance.take', 'attendance.override',
                'guidance.view', 'risk.view', 'finance.view', 'messages.view', 'messages.send', 'announcements.manage', 'reports.view',
                'messages.campaign', 'messages.campaign_send', 'messages.consents',
                'reports.export', 'imports.manage',
                'payments.create', 'reports.finance',
                'finance.invoice', 'finance.refund', 'finance.reconcile', 'finance.collections', 'finance.accounting', 'finance.period_close',
                'discipline.view', 'discipline.create', 'discipline.decide', 'discipline.board', 'discipline.settings', 'discipline.export',
                'sync.use', 'sync.manage',
            ])],
            'muhasebe' => ['label' => 'Muhasebe', 'permissions' => [
                'dashboard.view', 'search.global', 'students.view', 'guardians.view', 'students.view_sensitive', 'documents.view',
                'enrollments.create', 'finance.view', 'payments.create', 'installments.manage', 'expenses.manage', 'accounts.manage',
                'inventory.manage', 'messages.view', 'messages.send', 'reports.view', 'reports.finance', 'reports.export',
                'finance.invoice', 'finance.refund', 'finance.reconcile', 'finance.collections', 'finance.accounting', 'finance.period_close',
                'sync.use',
            ]],
            'rehber' => ['label' => 'Rehber Öğretmen', 'permissions' => array_merge($viewAll, [
                'guidance.view', 'guidance.manage', 'guidance.private', 'risk.view', 'discipline.view', 'discipline.create', 'study.manage', 'messages.view', 'messages.send',
                'reports.view', 'documents.view',
                'teacher_portal.access', 'teacher_portal.attendance', 'teacher_portal.homework', 'teacher_portal.observations',
            ])],
            // Yalnız öğretmen portalı: yönetim ekranı yok ('staff' ara katmanı kapalı), veri kendi sınıf/öğrencileriyle sınırlı.
            // Yönetim ekranı da gereken öğretmene ek rol (ör. rehber, mudur) verilir.
            'ogretmen' => ['label' => 'Öğretmen', 'permissions' => [
                'teacher_portal.access', 'teacher_portal.attendance', 'teacher_portal.homework', 'teacher_portal.observations',
            ]],
            'danisman' => ['label' => 'Danışman / Kayıt Personeli', 'permissions' => [
                'dashboard.view', 'search.global', 'students.view', 'students.create', 'students.update', 'students.credentials',
                'students.impersonate', 'guardians.view', 'guardians.manage', 'guardians.credentials', 'guardians.impersonate', 'documents.view', 'documents.manage', 'crm.view', 'crm.manage', 'enrollments.create',
                'discipline.view', 'academic.view', 'finance.view', 'messages.view', 'messages.send', 'reports.view', 'reports.export',
                'messages.campaign', 'messages.consents', // toplu gönderim taslağı hazırlar; onay/gönderim müdür/yöneticide
            ]],
            'sistem' => ['label' => 'Teknik Yönetici', 'permissions' => [
                'dashboard.view', 'settings.manage', 'users.manage', 'integrations.manage', 'audit.view', 'system.health', 'sync.use', 'sync.manage',
                'devices.manage', 'templates.manage', 'automations.manage', 'imports.manage', 'integrations.sms', 'integrations.email',
            ]],
            'ogrenci' => ['label' => 'Öğrenci', 'permissions' => []],
            'veli' => ['label' => 'Veli', 'permissions' => []],
        ];
    }
}

<?php

namespace App\Support;

/**
 * Denetim kaydı konu türleri (morf takma adları) için Türkçe etiket ve ön yüz bağlantısı.
 * Bağlantı yalnız var olan ekranlara verilir; `{id}` konu kimliğiyle doldurulur.
 */
final class AuditSubjects
{
    /** @var array<string, array{0:string, 1:?string}> takma ad → [etiket, yol] */
    public const MAP = [
        'student' => ['Öğrenci', '/ogrenciler/{id}'],
        'guardian' => ['Veli', '/veliler/{id}'],
        'teacher' => ['Öğretmen', '/ogretmenler/{id}'],
        'employee' => ['Personel', '/personel'],
        'user' => ['Kullanıcı', '/ayarlar/kullanicilar'],
        'role' => ['Rol', '/ayarlar/roller'],
        'lead' => ['Ön kayıt', '/on-kayit'],
        'lead_activity' => ['Ön kayıt görüşmesi', '/on-kayit'],
        'task' => ['Görev', '/gorevlerim'],
        'payment' => ['Tahsilat', '/finans/tahsilatlar'],
        'enrollment' => ['Kayıt', '/finans/kayitlar/{id}'],
        'installment' => ['Taksit', '/finans/alacaklar'],
        'contract' => ['Sözleşme', null],
        'finance_entry' => ['Gelir/gider', '/finans/gelir-gider'],
        'invoice' => ['Fatura', '/finans/faturalar/{id}'],
        'refund' => ['İade', '/finans/iadeler'],
        'pos_settlement' => ['POS yatışı', '/finans/mutabakat'],
        'journal_entry' => ['Yevmiye fişi', '/finans/muhasebe'],
        'collection_note' => ['Tahsilat takip notu', '/finans/takip'],
        'finance_account' => ['Kasa/banka', '/finans/hesaplar/{id}'],
        'finance_category' => ['Finans kategorisi', '/finans/gelir-gider'],
        'account_transfer' => ['Hesaplar arası transfer', '/finans/hesaplar'],
        'education_package' => ['Eğitim paketi', '/finans/paketler'],
        'product' => ['Ürün/materyal', '/finans/envanter'],
        'stock_movement' => ['Stok hareketi', '/finans/envanter'],
        'exam' => ['Sınav', '/sinavlar/{id}'],
        'exam_type' => ['Sınav türü', '/sinavlar'],
        'exam_result' => ['Sınav sonucu', '/sinav-sonuclari'],
        'optical_import' => ['Optik okuma', '/optik-okuma'],
        'optical_layout' => ['Optik form şablonu', '/optik-okuma'],
        'class_group' => ['Sınıf', '/siniflar/{id}'],
        'class_group_subject_hour' => ['Sınıf ders saati', '/program-botu/sinif-yapisi'],
        'class_waitlist_entry' => ['Bekleme listesi', '/yerlestirme'],
        'placement_run' => ['Yerleştirme işlemi', '/yerlestirme'],
        'program' => ['Program', '/akademik/programlar/{id}'],
        'subject' => ['Ders', '/akademik/dersler/{id}'],
        'topic' => ['Konu', '/akademik'],
        'classroom' => ['Derslik', '/akademik/derslikler/{id}'],
        'academic_term' => ['Akademik dönem', '/ayarlar/kurum'],
        'lesson_schedule' => ['Ders programı', '/ders-programi'],
        'lesson_session' => ['Ders', '/ders-programi'],
        'time_template' => ['Zaman şablonu', '/program-botu/sablonlar'],
        'timetable_run' => ['Program botu çalışması', '/program-botu/{id}'],
        'teacher_availability' => ['Öğretmen uygunluğu', '/etut/uygunluk'],
        'teacher_leave' => ['Öğretmen izni', '/ogretmenler'],
        'holiday' => ['Tatil', null],
        'study_session' => ['Etüt/birebir', '/etut'],
        'homework' => ['Ödev', '/odevler/{id}'],
        'homework_submission' => ['Ödev teslimi', '/odevler'],
        'attendance' => ['Yoklama', '/yoklama/devamsizlik'],
        'device' => ['Cihaz', '/yoklama/cihazlar'],
        'device_identity' => ['Cihaz kimlik eşlemesi', '/yoklama/cihazlar'],
        'guidance_meeting' => ['Rehberlik görüşmesi', '/rehberlik/gorusmeler'],
        'student_goal' => ['Öğrenci hedefi', '/rehberlik/hedefler'],
        'student_note' => ['Öğrenci notu', null],
        'student_observation' => ['Öğretmen gözlemi', '/ogrenciler'],
        'discipline_incident' => ['Disiplin olayı', '/disiplin/olaylar/{id}'],
        'discipline_sanction' => ['Disiplin yaptırımı', '/disiplin/olaylar'],
        'discipline_board_meeting' => ['Disiplin kurulu', '/disiplin/kurul/{id}'],
        'discipline_behavior' => ['Disiplin davranışı', '/disiplin/katalog'],
        'discipline_sanction_type' => ['Yaptırım kademesi', '/disiplin/katalog'],
        'discipline_defense' => ['Savunma', '/disiplin/olaylar'],
        'discipline_appeal' => ['Disiplin itirazı', '/disiplin/olaylar'],
        'contact_request' => ['Veli talebi', '/veliler'],
        'document' => ['Belge', null],
        'automation_rule' => ['Otomasyon kuralı', '/iletisim/otomasyonlar'],
        'message_template' => ['Mesaj şablonu', '/iletisim/sablonlar'],
        'announcement' => ['Duyuru', '/iletisim/duyurular'],
        'outbound_message' => ['Giden mesaj', '/iletisim/whatsapp'],
        'integration' => ['Entegrasyon', '/ayarlar/entegrasyonlar'],
        'webhook' => ['Webhook', '/ayarlar/webhooklar'],
        'branch' => ['Şube', '/ayarlar/kurum'],
        'setting' => ['Ayar', '/ayarlar/kurum'],
        'backup_run' => ['Yedek', '/ayarlar/sistem-sagligi'],
        'import_job' => ['İçe aktarma', null],
        'tag' => ['Etiket', '/ogrenciler'],
        'school' => ['Okul', '/ayarlar/okullar'],
    ];

    public static function label(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::MAP[$type][0] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public static function url(?string $type, int|string|null $id): ?string
    {
        $path = $type !== null ? (self::MAP[$type][1] ?? null) : null;
        if ($path === null) {
            return null;
        }
        if (str_contains($path, '{id}')) {
            return $id !== null && $id !== '' ? str_replace('{id}', (string) (int) $id, $path) : null;
        }

        return $path;
    }

    /**
     * Konu: gerçek morf sütunu; yoksa eski geçici çözümün yazdığı changes.subject {type: SınıfAdı, id}.
     *
     * @return array{type: ?string, id: ?int}
     */
    public static function resolve(?string $subjectType, int|string|null $subjectId, mixed $changes): array
    {
        if ($subjectType) {
            return ['type' => $subjectType, 'id' => $subjectId !== null ? (int) $subjectId : null];
        }
        if (is_array($changes) && isset($changes['subject']['type'])) {
            $type = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $changes['subject']['type']));

            return ['type' => $type, 'id' => isset($changes['subject']['id']) ? (int) $changes['subject']['id'] : null];
        }

        return ['type' => null, 'id' => null];
    }
}

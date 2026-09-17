<?php

namespace App\Support\Discipline;

/**
 * Disiplin sisteminin sabit sözlükleri ve varsayılan referans verisi (migration ile şubeye yazılır;
 * kurum sonradan Disiplin › Katalog ekranından değiştirir). MEB okul yönetmeliğinin kopyası değildir;
 * özel öğretim kursu (dershane) için sade, ayarlanabilir bir başlangıçtır.
 */
final class DisciplineCatalog
{
    public const CATEGORIES = [
        'attendance' => 'Devamsızlık / geç kalma',
        'class_order' => 'Ders düzeni',
        'disrespect' => 'Saygısızlık',
        'cheating' => 'Kopya / sınav kuralı',
        'fight' => 'Kavga / zorbalık',
        'damage' => 'Zarar verme',
        'phone' => 'Telefon / cihaz',
        'substance' => 'Sigara / madde',
        'other' => 'Diğer',
        'appreciation' => 'Takdir / teşekkür',
        'effort' => 'Çaba ve gelişim',
        'helpfulness' => 'Yardımseverlik / örnek davranış',
    ];

    public const POSITIVE_CATEGORIES = ['appreciation', 'effort', 'helpfulness'];

    public const SEVERITIES = ['low' => 'Hafif', 'medium' => 'Orta', 'high' => 'Ağır', 'critical' => 'Çok ağır'];

    public const SEVERITY_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public const INCIDENT_STATUSES = [
        'open' => 'Açık',
        'review' => 'İncelemede',
        'decided' => 'Karara bağlandı',
        'appealed' => 'İtiraz edildi',
        'closed' => 'Kapandı',
    ];

    /** İzin verilen olay durum geçişleri (kaynak → hedefler). */
    public const INCIDENT_TRANSITIONS = [
        'open' => ['review', 'decided', 'closed'],
        'review' => ['open', 'decided', 'closed'],
        'decided' => ['appealed', 'closed', 'review'],
        'appealed' => ['decided', 'closed'],
        'closed' => ['review'],
    ];

    public const ROLES = ['involved' => 'Olaya karışan', 'victim' => 'Mağdur', 'witness' => 'Tanık'];

    public const SANCTION_STATUSES = [
        'proposed' => 'Kurul kararı bekliyor',
        'active' => 'Yürürlükte',
        'completed' => 'Tamamlandı',
        'expired' => 'Düştü',
        'appealed' => 'İtirazda',
        'overturned' => 'İtirazla kaldırıldı',
        'cancelled' => 'İptal edildi',
    ];

    /** Portal ve rapor için "sonuçlanmış" yaptırım durumları. */
    public const DECIDED_SANCTION_STATUSES = ['active', 'completed', 'expired', 'appealed'];

    /** Puan/uyarı seviyesine sayılan yaptırım durumları (kaldırılan/iptal/öneri sayılmaz). */
    public const COUNTED_SANCTION_STATUSES = ['active', 'completed', 'appealed'];

    public const SANCTION_TRANSITIONS = [
        'proposed' => ['active', 'cancelled'],
        'active' => ['completed', 'expired', 'appealed', 'cancelled'],
        'appealed' => ['active', 'overturned'],
        'completed' => [],
        'expired' => [],
        'overturned' => [],
        'cancelled' => [],
    ];

    public const APPEAL_STATUSES = ['pending' => 'Bekliyor', 'accepted' => 'Kabul edildi (yaptırım kaldırıldı)', 'rejected' => 'Reddedildi', 'modified' => 'Kısmen kabul (yaptırım hafifletildi)'];

    public const DEFENSE_STATUSES = ['requested' => 'Savunma bekleniyor', 'submitted' => 'Savunma verildi', 'waived' => 'Savunma alınmadı'];

    public const BOARD_STATUSES = ['planned' => 'Planlandı', 'held' => 'Yapıldı', 'cancelled' => 'İptal'];

    public const BOARD_ROLES = ['chair' => 'Başkan', 'member' => 'Üye', 'secretary' => 'Raportör'];

    public const LEVELS = [
        'none' => 'Temiz',
        'watch' => 'Dikkat',
        'warning' => 'Uyarı',
        'critical' => 'Kritik',
    ];

    /** Kurum ayarları (grup 'discipline'); kayıtlı değer yoksa bunlar geçerli. */
    public const SETTINGS = [
        'portal_enabled' => true,              // veli/öğrenci portalında sonuçlanmış yaptırımlar
        'portal_defense_requests' => true,     // portalda savunma istemi görünür
        'portal_defense_submission' => true,   // öğrenci savunmasını portaldan yazabilir
        'default_defense_days' => 3,
        'merit_offsets_penalty' => true,       // olumlu puan dönem ceza puanından düşülür
        'threshold_watch' => 10,
        'threshold_warning' => 20,
        'threshold_critical' => 35,
        'suspension_attendance_status' => 'excused', // uzaklaştırma günlerinde otomatik yoklama: excused | absent
        'teacher_portal_reporting' => true,
    ];

    /** @return array<string, array<string, mixed>> */
    public static function defaultSanctionTypes(): array
    {
        return [
            'verbal_warning' => ['name' => 'Sözlü uyarı', 'level' => 1, 'authority' => 'staff', 'is_suspension' => false, 'has_duty' => false, 'expires_after_days' => 60, 'tone' => 'info',
                'description' => 'Öğrenciyle yüz yüze konuşulur; kayda geçer.'],
            'written_warning' => ['name' => 'Yazılı uyarı', 'level' => 2, 'authority' => 'staff', 'is_suspension' => false, 'has_duty' => false, 'expires_after_days' => 120, 'tone' => 'warning',
                'description' => 'Öğrenciye ve veliye yazılı bildirilir.'],
            'guardian_meeting' => ['name' => 'Veli görüşmesi / çağrısı', 'level' => 3, 'authority' => 'staff', 'is_suspension' => false, 'has_duty' => false, 'expires_after_days' => 120, 'tone' => 'warning',
                'description' => 'Veli kuruma çağrılır, görüşme tutanağı tutulur.'],
            'study_duty' => ['name' => 'Etüt / hizmet görevi', 'level' => 3, 'authority' => 'staff', 'is_suspension' => false, 'has_duty' => true, 'expires_after_days' => 90, 'tone' => 'accent',
                'description' => 'Ek etüt ya da kurum içi hizmet görevi (gün ve görev yazılır).'],
            'reprimand' => ['name' => 'Kınama', 'level' => 4, 'authority' => 'board', 'is_suspension' => false, 'has_duty' => false, 'expires_after_days' => 240, 'tone' => 'danger',
                'description' => 'Disiplin kurulu kararıyla verilir.'],
            'suspension' => ['name' => 'Geçici uzaklaştırma', 'level' => 5, 'authority' => 'board', 'is_suspension' => true, 'has_duty' => false, 'expires_after_days' => 365, 'tone' => 'danger',
                'description' => 'Belirtilen günlerde derslere alınmaz; yoklamaya "disiplin" notu düşer.'],
            'termination' => ['name' => 'Kayıt sonlandırma önerisi', 'level' => 6, 'authority' => 'board', 'is_suspension' => false, 'has_duty' => false, 'expires_after_days' => null, 'tone' => 'danger',
                'description' => 'Kurul önerisi; kaydın sonlandırılması ayrıca yönetim kararıyla yapılır.'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaultBehaviors(): array
    {
        $n = fn (string $name, string $cat, int $pts, string $sev, ?string $sug, ?string $desc = null) => [
            'name' => $name, 'category' => $cat, 'kind' => 'negative', 'points' => $pts, 'severity' => $sev, 'suggested_sanction' => $sug, 'description' => $desc,
        ];
        $p = fn (string $name, string $cat, int $pts, ?string $desc = null) => [
            'name' => $name, 'category' => $cat, 'kind' => 'positive', 'points' => $pts, 'severity' => 'low', 'suggested_sanction' => null, 'description' => $desc,
        ];

        return [
            'late_arrival' => $n('Derse geç gelme', 'attendance', 1, 'low', 'verbal_warning', 'Ders başladıktan sonra sınıfa girme'),
            'repeated_late' => $n('Tekrarlayan geç kalma', 'attendance', 3, 'medium', 'written_warning', 'Bir ay içinde 3 ve üzeri geç kalma'),
            'unexcused_absence' => $n('Habersiz derse girmeme', 'attendance', 3, 'medium', 'guardian_meeting', 'Kurumdayken derse girmeme / dersi terk etme'),
            'class_disruption' => $n('Dersin akışını bozma', 'class_order', 2, 'low', 'verbal_warning', 'Konuşma, gürültü, dikkat dağıtma'),
            'no_materials' => $n('Ders materyali getirmeme (tekrarlayan)', 'class_order', 1, 'low', 'verbal_warning'),
            'eating_in_class' => $n('Sınıfta yiyecek tüketme / düzeni bozma', 'class_order', 1, 'low', 'verbal_warning'),
            'phone_use' => $n('Derste telefon kullanma', 'phone', 2, 'low', 'verbal_warning', 'Telefonun ders sırasında açık / kullanımda olması'),
            'phone_repeat' => $n('Telefon kuralını tekrar ihlal', 'phone', 4, 'medium', 'written_warning'),
            'recording' => $n('İzinsiz görüntü / ses kaydı', 'phone', 8, 'high', 'reprimand', 'Öğretmen veya öğrencinin izinsiz kaydı, paylaşımı'),
            'disrespect_teacher' => $n('Öğretmene saygısızlık', 'disrespect', 6, 'medium', 'guardian_meeting'),
            'disrespect_peer' => $n('Arkadaşına saygısızlık / alay', 'disrespect', 3, 'low', 'written_warning'),
            'insult' => $n('Kaba söz / hakaret', 'disrespect', 6, 'high', 'guardian_meeting'),
            'cheating_exam' => $n('Deneme sınavında kopya', 'cheating', 8, 'high', 'reprimand', 'Kopya çekme, çektirme, cevap paylaşma'),
            'homework_copy' => $n('Ödevde kopya / başkasının ödevini verme', 'cheating', 3, 'low', 'written_warning'),
            'fight' => $n('Kavga etme', 'fight', 12, 'high', 'suspension'),
            'bullying' => $n('Zorbalık / tehdit', 'fight', 15, 'critical', 'suspension', 'Fiziksel, sözlü ya da çevrim içi zorbalık'),
            'damage_property' => $n('Kurum eşyasına zarar verme', 'damage', 8, 'high', 'reprimand', 'Zarar ayrıca veliye bildirilir'),
            'littering' => $n('Ortak alanı kirletme', 'damage', 2, 'low', 'study_duty'),
            'smoking' => $n('Kurumda / önünde sigara içme', 'substance', 8, 'high', 'guardian_meeting'),
            'substance' => $n('Alkol / madde bulundurma', 'substance', 20, 'critical', 'termination'),
            'false_info' => $n('Kuruma yanlış bilgi verme / imza taklidi', 'other', 8, 'high', 'reprimand'),
            'leaving_building' => $n('İzinsiz kurumdan ayrılma', 'other', 4, 'medium', 'guardian_meeting'),
            'appreciation' => $p('Takdir (öğretmen)', 'appreciation', 3, 'Örnek davranış, derse üstün katkı'),
            'thanks' => $p('Teşekkür (kurum)', 'appreciation', 5, 'Kurum adına yazılı teşekkür'),
            'exam_progress' => $p('Denemede belirgin net artışı', 'effort', 3),
            'full_attendance' => $p('Ay boyunca tam devam', 'effort', 2),
            'peer_help' => $p('Arkadaşına ders desteği / yardımseverlik', 'helpfulness', 3),
            'social_responsibility' => $p('Sosyal sorumluluk / kurum etkinliğine katkı', 'helpfulness', 4),
        ];
    }

    /** Veli bildirim taslağı şablonları (message_templates, whatsapp). Otomatik gönderim KAPALI kurala bağlıdır. */
    public static function defaultTemplates(): array
    {
        $sign = "\n\nErbaa Bilgi Eğitim";

        return [
            'discipline.sanction.guardian' => ['Disiplin: yaptırım bilgisi (veli)',
                "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} ile ilgili {{olay_tarihi}} tarihli olay ({{davranis}}) değerlendirilmiş ve \"{{yaptirim}}\" kararı verilmiştir.{{sure_bilgisi}}\n\nKonuyu birlikte ele almak için rehberlik servisimizle görüşebilirsiniz. İtiraz hakkınızı kararın bildiriminden itibaren 5 gün içinde yazılı olarak kullanabilirsiniz.".$sign],
            'discipline.defense.guardian' => ['Disiplin: savunma istemi (veli)',
                "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} ile ilgili {{olay_tarihi}} tarihli olay ({{davranis}}) nedeniyle öğrencimizden {{son_tarih}} tarihine kadar yazılı savunması istenmiştir. Savunma kuruma teslim edilebilir ya da öğrenci portalından yazılabilir.".$sign],
            'discipline.positive.guardian' => ['Takdir / teşekkür bilgisi (veli)',
                "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}}, {{olay_tarihi}} tarihinde \"{{davranis}}\" ile kurumumuzun takdirini kazanmıştır. Tebrik ederiz!".$sign],
        ];
    }
}

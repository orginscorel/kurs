<?php

namespace App\Support\Notifications;

/**
 * Bildirim omurgasının olay kataloğu: her olay için hedef kitleler, kullanılabilir değişkenler ve
 * kitle bazlı VARSAYILAN şablon gövdeleri. Bunlar manuel "taslak → önizleme → onay → gönder"
 * akışında kullanılır (otomasyon tetikleyicilerinden ayrıdır; onlar AutomationRule::TRIGGERS).
 *
 * Şablon gövdeleri {{degisken}} yer tutucusu kullanır; bilinmeyen değişken boş kalır (MessageTemplate::render).
 * Yeni bir kurulumda şablonlar EventNotificationService tarafından ilk kullanımda otomatik oluşturulur.
 */
class NotificationCatalog
{
    public const AUDIENCES = ['student' => 'Öğrenci', 'parent' => 'Veli', 'teacher' => 'Öğretmen', 'admin' => 'Yönetici'];

    /** audience → RecipientResolver::resolve() `to` anahtarı */
    public const AUDIENCE_TO = ['student' => 'student', 'parent' => 'guardian', 'teacher' => 'teacher', 'admin' => 'admin'];

    private const SIGN = "\n\nErbaa Bilgi Eğitim";

    /**
     * @return array<string, array{label:string, group:string, audiences:array<int,string>, variables:array<int,string>, templates:array<string, array{name:string, body:string}>}>
     */
    public static function events(): array
    {
        $s = self::SIGN;

        return [
            'schedule.published' => [
                'label' => 'Ders programı yayını',
                'group' => 'academic',
                'audiences' => ['student', 'parent', 'teacher', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'ogretmen_adi', 'sinif', 'hafta', 'ders_listesi', 'tarih'],
                'templates' => [
                    'student' => ['name' => 'Ders programı (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{hafta}} haftası ders programın yayımlandı:\n{{ders_listesi}}\n\nDetaylar öğrenci panelinde.".$s],
                    'parent' => ['name' => 'Ders programı (veli)', 'body' => "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} için {{hafta}} haftası ders programı yayımlanmıştır. Program veli panelinizden görüntülenebilir.".$s],
                    'teacher' => ['name' => 'Ders programı (öğretmen)', 'body' => "Sayın {{ogretmen_adi}},\n\n{{sinif}} sınıfının {{hafta}} haftası ders programı yayımlanmıştır. Programınız öğretmen panelinizde.".$s],
                    'admin' => ['name' => 'Ders programı (yönetici)', 'body' => "{{sinif}} — {{hafta}} haftası ders programı yayımlandı ({{tarih}})."],
                ],
            ],
            'guidance.meeting' => [
                'label' => 'Rehberlik görüşmesi',
                'group' => 'guidance',
                'audiences' => ['student', 'parent', 'teacher', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'rehber_adi', 'tarih', 'saat', 'yer', 'konu'],
                'templates' => [
                    'student' => ['name' => 'Rehberlik görüşmesi (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{tarih}} {{saat}} tarihinde {{rehber_adi}} ile rehberlik görüşmen planlanmıştır. Yer: {{yer}}.".$s],
                    'parent' => ['name' => 'Rehberlik görüşmesi (veli)', 'body' => "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} için {{tarih}} {{saat}} tarihinde {{rehber_adi}} ile rehberlik görüşmesi planlanmıştır.".$s],
                    'teacher' => ['name' => 'Rehberlik görüşmesi (rehber)', 'body' => "Sayın {{rehber_adi}},\n\n{{ogrenci_adi}} ile {{tarih}} {{saat}} rehberlik görüşmeniz planlandı. Konu: {{konu}}.".$s],
                    'admin' => ['name' => 'Rehberlik görüşmesi (yönetici)', 'body' => "Rehberlik: {{ogrenci_adi}} — {{rehber_adi}} — {{tarih}} {{saat}}."],
                ],
            ],
            'study.plan' => [
                'label' => 'Etüt / çalışma planı',
                'group' => 'academic',
                'audiences' => ['student', 'parent', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'hafta', 'plan_ozeti', 'tarih'],
                'templates' => [
                    'student' => ['name' => 'Etüt planı (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{hafta}} haftası çalışma/etüt planın hazır:\n{{plan_ozeti}}".$s],
                    'parent' => ['name' => 'Etüt planı (veli)', 'body' => "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} için {{hafta}} haftası etüt/çalışma planı oluşturulmuştur.".$s],
                    'admin' => ['name' => 'Etüt planı (yönetici)', 'body' => "Etüt planı: {{ogrenci_adi}} — {{hafta}} ({{tarih}})."],
                ],
            ],
            'coaching.session' => [
                'label' => 'Koçluk görüşmesi',
                'group' => 'coaching',
                'audiences' => ['student', 'parent', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'koc_adi', 'tarih', 'saat', 'konu', 'plan_ozeti'],
                'templates' => [
                    'student' => ['name' => 'Koçluk görüşmesi (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{tarih}} {{saat}} tarihinde koçun {{koc_adi}} ile görüşmen var. Konu: {{konu}}.".$s],
                    'parent' => ['name' => 'Koçluk görüşmesi (veli)', 'body' => "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} için {{tarih}} {{saat}} tarihinde koç {{koc_adi}} ile koçluk görüşmesi planlanmıştır.".$s],
                    'admin' => ['name' => 'Koçluk görüşmesi (yönetici)', 'body' => "Koçluk: {{ogrenci_adi}} — {{koc_adi}} — {{tarih}} {{saat}}."],
                ],
            ],
            'lesson.one_to_one' => [
                'label' => 'Birebir ders',
                'group' => 'academic',
                'audiences' => ['student', 'parent', 'teacher', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'ogretmen_adi', 'ders_adi', 'tarih', 'saat', 'derslik'],
                'templates' => [
                    'student' => ['name' => 'Birebir ders (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{tarih}} {{saat}} tarihinde {{ogretmen_adi}} ile {{ders_adi}} birebir dersin planlandı. Derslik: {{derslik}}.".$s],
                    'parent' => ['name' => 'Birebir ders (veli)', 'body' => "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} için {{tarih}} {{saat}} tarihinde {{ders_adi}} birebir ders planlanmıştır.".$s],
                    'teacher' => ['name' => 'Birebir ders (öğretmen)', 'body' => "Sayın {{ogretmen_adi}},\n\n{{ogrenci_adi}} ile {{tarih}} {{saat}} {{ders_adi}} birebir dersiniz planlandı. Derslik: {{derslik}}.".$s],
                    'admin' => ['name' => 'Birebir ders (yönetici)', 'body' => "Birebir ders: {{ogrenci_adi}} — {{ogretmen_adi}} — {{ders_adi}} — {{tarih}} {{saat}}."],
                ],
            ],
            'payment.reminder' => [
                'label' => 'Ödeme hatırlatma',
                'group' => 'finance',
                'audiences' => ['parent', 'student', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'tutar', 'vade_tarihi', 'gecikme_gun'],
                'templates' => [
                    'parent' => ['name' => 'Ödeme hatırlatma (veli)', 'body' => "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için {{vade_tarihi}} vadeli {{tutar}} TL tutarındaki taksit ödemenizi hatırlatırız.".$s],
                    'student' => ['name' => 'Ödeme hatırlatma (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{vade_tarihi}} vadeli {{tutar}} TL taksit ödemesi hatırlatmasıdır.".$s],
                    'admin' => ['name' => 'Ödeme hatırlatma (yönetici)', 'body' => "Ödeme hatırlatma: {{ogrenci_adi}} — {{tutar}} TL — vade {{vade_tarihi}}."],
                ],
            ],
            'payment.receipt' => [
                'label' => 'Ödeme makbuzu (PDF)',
                'group' => 'finance',
                'audiences' => ['parent', 'student', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'tutar', 'tarih', 'makbuz_no'],
                'templates' => [
                    'parent' => ['name' => 'Ödeme makbuzu (veli)', 'body' => "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} için aldığımız {{tutar}} TL tutarındaki {{tarih}} tarihli ödemenizin makbuzu ({{makbuz_no}}) hazırlanmıştır.".$s],
                    'student' => ['name' => 'Ödeme makbuzu (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{tarih}} tarihli {{tutar}} TL ödeme makbuzun ({{makbuz_no}}) hazırlanmıştır.".$s],
                    'admin' => ['name' => 'Ödeme makbuzu (yönetici)', 'body' => "Makbuz: {{ogrenci_adi}} — {{tutar}} TL — {{makbuz_no}} ({{tarih}})."],
                ],
            ],
            'attendance.mark' => [
                'label' => 'Yoklama (derse geldi/gelmedi)',
                'group' => 'attendance',
                'audiences' => ['parent', 'student', 'admin'],
                'variables' => ['ogrenci_adi', 'veli_adi', 'ders_adi', 'ders_saati', 'durum', 'tarih'],
                'templates' => [
                    'parent' => ['name' => 'Yoklama (veli)', 'body' => "Sayın {{veli_adi}},\n\nÖğrencimiz {{ogrenci_adi}} {{tarih}} {{ders_saati}} {{ders_adi}} dersi yoklama durumu: {{durum}}.".$s],
                    'student' => ['name' => 'Yoklama (öğrenci)', 'body' => "Merhaba {{ogrenci_adi}},\n\n{{tarih}} {{ders_saati}} {{ders_adi}} dersi yoklaman: {{durum}}.".$s],
                    'admin' => ['name' => 'Yoklama (yönetici)', 'body' => "Yoklama: {{ogrenci_adi}} — {{ders_adi}} — {{durum}} ({{tarih}})."],
                ],
            ],
        ];
    }

    public static function eventTypes(): array
    {
        return array_keys(self::events());
    }

    public static function event(string $type): ?array
    {
        return self::events()[$type] ?? null;
    }

    public static function label(string $type): string
    {
        return self::events()[$type]['label'] ?? $type;
    }

    /** Bir olay için istenen (ya da varsayılan tüm) kitleler, olayın izin verdiğiyle kesişimi. */
    public static function audiencesFor(string $type, ?array $requested = null): array
    {
        $allowed = self::events()[$type]['audiences'] ?? [];
        if ($requested === null) {
            return $allowed;
        }

        return array_values(array_intersect($allowed, $requested));
    }
}

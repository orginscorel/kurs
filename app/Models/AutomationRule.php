<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    use BelongsToBranch;

    public const TRIGGERS = [
        'attendance.absent' => 'Öğrenci derse gelmedi',
        'attendance.late' => 'Öğrenci derse geç kaldı',
        'student.entry' => 'Öğrenci kuruma giriş yaptı',
        'student.exit' => 'Öğrenci kurumdan çıktı',
        'student.no_show_today' => 'Öğrenci bugün kuruma gelmedi',
        'installment.upcoming' => 'Taksit vadesi yaklaşıyor',
        'installment.due' => 'Taksit vadesi bugün',
        'installment.overdue' => 'Taksit gecikti',
        'payment.received' => 'Tahsilat alındı',
        'exam.result_published' => 'Sınav sonucu yayımlandı',
        'homework.due_tomorrow' => 'Ödev teslimi yarın',
        'lesson.starting' => 'Ders başlamak üzere',
        'lesson.cancelled' => 'Ders iptal edildi',
        'schedule.tomorrow' => 'Yarınki ders programı (akşam)',
        'enrollment.welcome' => 'Yeni kayıt tamamlandı (hoş geldin)',
        'homework.missed' => 'Ödev teslim edilmedi',
        'risk.high' => 'Öğrenci yüksek risk seviyesine geçti',
        'student.class_changed' => 'Öğrencinin sınıfı değişti',
        'discipline.sanction_decided' => 'Disiplin yaptırımı kesinleşti',
    ];

    /**
     * Her tetikleyicinin hangi olay/zamanlamadan beslendiği (Otomasyonlar ekranı "kaynak" açıklaması).
     * kind: event = modül servisinden anlık olay; schedule = zamanlanmış komut.
     *
     * @var array<string, array{kind: 'event'|'schedule', source: string}>
     */
    public const TRIGGER_SOURCES = [
        'attendance.absent' => ['kind' => 'event', 'source' => 'Otomatik yoklama (biyometrik giriş yok, 5 dk\'da bir) ya da öğretmen/yönetici yoklamasında GELMEDİ işaretlenince. Gecikmeli kurallarda gönderimden önce durum yeniden kontrol edilir.'],
        'attendance.late' => ['kind' => 'event', 'source' => 'Otomatik yoklama (eşikten sonra giriş) ya da elle yoklamada GEÇ işaretlenince; {{gec_dakika}} değişkeni dolu gelir. Aynı ders için gelmedi mesajı gittiyse ikinci mesaj gitmez.'],
        'student.entry' => ['kind' => 'event', 'source' => 'Kiosk/parmak izi/QR girişi (yalnız canlı olaylar; 6 saatten eski senkron kayıtlar bildirim üretmez).'],
        'student.exit' => ['kind' => 'event', 'source' => 'Kiosk/parmak izi/QR çıkışı (yalnız canlı olaylar).'],
        'student.no_show_today' => ['kind' => 'schedule', 'source' => 'kurs:attendance-no-show — 07:00-20:00 arası 5 dk\'da bir; ilk dersinden X dk sonra kuruma girişi olmayan öğrenci, günde bir kez.'],
        'installment.upcoming' => ['kind' => 'schedule', 'source' => 'kurs:installment-reminders — Ayarlar > Ödeme hatırlatma saatinden (varsayılan 10:00) itibaren; ayardaki negatif ofsetler (varsayılan vadeye 5/2 gün kala), taksit ve kural başına tek sefer. Hatırlatmalar kapalıysa çalışmaz.'],
        'installment.due' => ['kind' => 'schedule', 'source' => 'kurs:installment-reminders — hatırlatma saatinden itibaren; vade günü (ofset 0), tek sefer.'],
        'installment.overdue' => ['kind' => 'schedule', 'source' => 'kurs:installment-reminders — hatırlatma saatinden itibaren; ayardaki pozitif ofsetler (varsayılan 3/7 gün gecikme), tek sefer.'],
        'payment.received' => ['kind' => 'event', 'source' => 'Finans → Tahsilat kaydı (PaymentService). {{makbuz_no}}, {{tutar}}, {{kalan_bakiye}} değişkenleri gelir.'],
        'exam.result_published' => ['kind' => 'event', 'source' => 'Sınav Merkezi → Sonuçları yayımla. Kuyrukta öğrenci başına çalışır; görsel sonuç kartı eklenebilir.'],
        'homework.due_tomorrow' => ['kind' => 'schedule', 'source' => 'kurs:homework-due-tomorrow-reminders — her gün 18:00; son teslimi yarın olan ödevin sınıfındaki öğrenciler.'],
        'homework.missed' => ['kind' => 'schedule', 'source' => 'kurs:homework-missed-notify — saatte bir (ödev kapanışından sonra); YAPILMADI olan öğrenci başına tek sefer. {{tekrar_sayisi}} = son 30 gündeki yapılmayan ödev sayısı.'],
        'lesson.starting' => ['kind' => 'schedule', 'source' => 'kurs:lesson-starting-reminders — 5 dk\'da bir; kuraldaki "dakika önce" koşuluna göre.'],
        'lesson.cancelled' => ['kind' => 'event', 'source' => 'Ders programı/takvim: ders iptali (akademik modül).'],
        'schedule.tomorrow' => ['kind' => 'schedule', 'source' => 'kurs:schedule-tomorrow — her akşam 20:00.'],
        'enrollment.welcome' => ['kind' => 'event', 'source' => 'Kayıt + ödeme planı oluşturulunca (öğrenci formu ya da CRM adayını dönüştürme).'],
        'student.class_changed' => ['kind' => 'event', 'source' => 'Sınıflar ve yerleştirme: tekil sınıf değişikliği, karşılıklı takas ve seviye atlatma (yeni sınıfa giriş). Toplu otomatik yerleştirme tetiklemez. {{eski_sinif}}, {{yeni_sinif}}, {{tarih}}, {{aciklama}} değişkenleri gelir; "sınıf" koşulu YENİ sınıfa bakar.'],
        'discipline.sanction_decided' => ['kind' => 'event', 'source' => 'Disiplin: yaptırım yürürlüğe girince (yetkili kararı ya da kurul kabulü; portalda gizli yaptırımlar tetiklemez). {{olay_tarihi}}, {{davranis}}, {{yaptirim}}, {{sure_bilgisi}}, {{olay_no}} değişkenleri gelir. Varsayılan kural PASİF.'],
        'risk.high' => ['kind' => 'event', 'source' => 'Risk puanı yeniden hesaplanıp seviye yüksek\'e GEÇTİĞİNDE (gece 02:15, sınav yayımı sonrası, rehberlik görüşmesi sonrası). Her gece tekrar etmez.'],
    ];

    protected $fillable = ['branch_id', 'name', 'trigger', 'conditions', 'actions', 'delay_minutes', 'is_active', 'created_by'];

    protected $casts = ['conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}

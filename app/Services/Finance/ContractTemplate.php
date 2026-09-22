<?php

namespace App\Services\Finance;

use App\Models\Enrollment;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Kayıt sözleşmesi şablonu. Kurum genelinde düzenlenebilir bir metin şablonu (yer tutucularla) +
 * kayda özel düzenlenebilir metin. Yer tutucular render sırasında güncel kayıt bilgileriyle doldurulur:
 * ödeme planı ({taksit_tablosu}) ve ücret dökümü ({ucret_tablosu}) tablo olarak otomatik gömülür.
 *
 * Tasarım:
 *  - Kaynak metin (template) yer tutucular içerir; düzenlenebilir ve saklanır.
 *  - render() = kaynak metindeki yer tutucuları o anki değerlerle değiştirir → dondurulacak HTML.
 *  - resolve()/planTable()/signatures() saf fonksiyondur (veritabanı yok) → birim testli.
 *
 * PDF sarmalayıcı (pdf.finance.contract) marka başlığını, CSS'i ve alt bilgiyi sağlar; burada üretilen
 * HTML aynı sınıfları (kv, grid, signs) kullandığı için çıktı görünümü korunur.
 */
final class ContractTemplate
{
    /**
     * Kullanıcı arayüzünde gösterilecek yer tutucular. Anahtar = metne eklenecek etiket.
     *
     * @return list<array{key:string, label:string, kind:string}>
     */
    public static function placeholders(): array
    {
        return [
            ['key' => '{kurum_adi}', 'label' => 'Kurum adı', 'kind' => 'metin'],
            ['key' => '{kurum_adres}', 'label' => 'Kurum adresi', 'kind' => 'metin'],
            ['key' => '{ogrenci_ad}', 'label' => 'Öğrenci adı', 'kind' => 'metin'],
            ['key' => '{ogrenci_no}', 'label' => 'Öğrenci no', 'kind' => 'metin'],
            ['key' => '{ogrenci_okul}', 'label' => 'Öğrencinin okulu', 'kind' => 'metin'],
            ['key' => '{veli_ad}', 'label' => 'Veli / ödeme sorumlusu', 'kind' => 'metin'],
            ['key' => '{veli_telefon}', 'label' => 'Veli telefonu', 'kind' => 'metin'],
            ['key' => '{program}', 'label' => 'Program', 'kind' => 'metin'],
            ['key' => '{donem}', 'label' => 'Dönem', 'kind' => 'metin'],
            ['key' => '{paket}', 'label' => 'Eğitim paketi', 'kind' => 'metin'],
            ['key' => '{sinif}', 'label' => 'Sınıf', 'kind' => 'metin'],
            ['key' => '{kayit_no}', 'label' => 'Kayıt no', 'kind' => 'metin'],
            ['key' => '{kayit_tarihi}', 'label' => 'Kayıt tarihi', 'kind' => 'metin'],
            ['key' => '{liste_fiyati}', 'label' => 'Liste fiyatı', 'kind' => 'metin'],
            ['key' => '{indirim}', 'label' => 'İndirim tutarı', 'kind' => 'metin'],
            ['key' => '{burs}', 'label' => 'Burs tutarı', 'kind' => 'metin'],
            ['key' => '{net_bedel}', 'label' => 'Net eğitim bedeli', 'kind' => 'metin'],
            ['key' => '{net_bedel_yazi}', 'label' => 'Net bedel (yazıyla)', 'kind' => 'metin'],
            ['key' => '{taksit_sayisi}', 'label' => 'Taksit sayısı', 'kind' => 'metin'],
            ['key' => '{tarih}', 'label' => 'Düzenlenme / imza tarihi', 'kind' => 'metin'],
            ['key' => '{ucret_tablosu}', 'label' => 'Ücret dökümü tablosu', 'kind' => 'tablo'],
            ['key' => '{taksit_tablosu}', 'label' => 'Ödeme planı tablosu', 'kind' => 'tablo'],
            ['key' => '{imza_bloklari}', 'label' => 'İmza alanları', 'kind' => 'tablo'],
        ];
    }

    /**
     * Şablon yoksa kullanılacak makul Türkçe varsayılan sözleşme metni (yer tutuculu).
     * Kurum bunu ayarlardan değiştirebilir; kayda özel de düzenlenebilir.
     */
    public static function defaultTemplate(): string
    {
        return <<<'HTML'
<h1>EĞİTİM HİZMETİ KAYIT SÖZLEŞMESİ</h1>

<p class="intro">İşbu sözleşme; bir tarafta <strong>{kurum_adi}</strong> ({kurum_adres}) — bundan sonra "Kurum" olarak anılacaktır — ile diğer tarafta aşağıda kimlik bilgileri yer alan öğrenci ve öğrenci adına hareket eden veli / ödeme sorumlusu — bundan sonra "Veli" olarak anılacaktır — arasında, aşağıdaki şart ve hükümlerle {tarih} tarihinde düzenlenmiştir.</p>

<h2>MADDE 1 — TARAFLAR</h2>
<table class="kv">
  <tr><td class="k">Kurum</td><td>{kurum_adi} — {kurum_adres}</td></tr>
  <tr><td class="k">Öğrenci</td><td>{ogrenci_ad} (Öğrenci No: {ogrenci_no})</td></tr>
  <tr><td class="k">Veli / Ödeme Sorumlusu</td><td>{veli_ad} · {veli_telefon}</td></tr>
</table>

<h2>MADDE 2 — SÖZLEŞMENİN KONUSU</h2>
<p>İşbu sözleşmenin konusu; öğrencinin aşağıda belirtilen eğitim programına kaydı ile bu program kapsamında Kurum tarafından sunulacak eğitim ve öğretim hizmetinin şartlarının, ücretinin ve tarafların karşılıklı hak ve yükümlülüklerinin belirlenmesidir.</p>
<table class="kv">
  <tr><td class="k">Program</td><td>{program}</td></tr>
  <tr><td class="k">Dönem</td><td>{donem}</td></tr>
  <tr><td class="k">Eğitim Paketi</td><td>{paket}</td></tr>
  <tr><td class="k">Sınıf</td><td>{sinif}</td></tr>
  <tr><td class="k">Kayıt No / Tarihi</td><td>{kayit_no} · {kayit_tarihi}</td></tr>
</table>

<h2>MADDE 3 — EĞİTİM ÜCRETİ</h2>
<p>Taraflarca kararlaştırılan net eğitim bedeli aşağıda gösterilmiştir. Bu tutar, dönem boyunca sunulacak eğitim ve öğretim hizmetinin toplam karşılığıdır.</p>
{ucret_tablosu}

<h2>MADDE 4 — ÖDEME PLANI</h2>
<p>Net eğitim bedeli, aşağıdaki ödeme planında belirtilen tutar ve vade tarihlerinde ödenecektir. Yapılan her ödeme için Kurum tarafından numaralı tahsilat makbuzu düzenlenir.</p>
{taksit_tablosu}

<h2>MADDE 5 — GENEL HÜKÜMLER</h2>
<ol class="terms">
  <li>Kurum, yukarıda belirtilen program kapsamındaki eğitim ve öğretim hizmetini dönem boyunca sunmayı taahhüt eder.</li>
  <li>Veli, ödeme planında yer alan taksitleri vade tarihlerinde ödemeyi kabul ve taahhüt eder. Vadesinde ödenmeyen taksitler için Kurum yazılı bildirimde bulunur.</li>
  <li>Ödeme planında yapılacak değişiklikler (vade, tutar, indirim veya burs) tarafların mutabakatı ile Kurum kayıtlarına işlenir ve yazılı olarak bildirilir; toplam net bedel, taraflarca aksi kararlaştırılmadıkça değişmez.</li>
  <li>Kayıt dondurma, ayrılma ve ücret iadesi talepleri Kurum yönetimine yazılı olarak yapılır; yürürlükteki mevzuat ve Kurum yönetmeliği çerçevesinde değerlendirilir.</li>
  <li>Öğrenci ve veliye ait kişisel veriler, 6698 sayılı Kişisel Verilerin Korunması Kanunu kapsamında yalnızca eğitim hizmetinin yürütülmesi amacıyla işlenir ve üçüncü kişilerle paylaşılmaz.</li>
  <li>İşbu sözleşmeden doğabilecek uyuşmazlıkların çözümünde Kurumun bulunduğu yer mahkemeleri ve icra daireleri yetkilidir.</li>
  <li>Yedi (7) maddeden ibaret işbu sözleşme, taraflarca okunup içeriği kabul edilerek imza altına alınmış olup imza tarihinde yürürlüğe girer.</li>
</ol>

{imza_bloklari}
HTML;
    }

    /**
     * Kaynak metindeki yer tutucuları verilen değerlerle değiştirir. Bilinmeyen yer tutucular olduğu gibi kalır.
     *
     * @param array<string, string> $values  '{anahtar}' => 'değer' (tablo değerleri HTML)
     */
    public static function resolve(string $template, array $values): string
    {
        return strtr($template, $values);
    }

    /** Kurum ayarındaki şablon boşsa varsayılana düşer. */
    public static function effective(?string $stored): string
    {
        $stored = is_string($stored) ? trim($stored) : '';

        return $stored !== '' ? $stored : self::defaultTemplate();
    }

    /**
     * Kayda özel render: (kayıt şablonu ?? kurum şablonu ?? varsayılan) + güncel değerler.
     *
     * @param array<string, mixed> $institution  FinanceDocuments::institution()
     */
    public static function render(Enrollment $enrollment, array $institution, ?string $enrollmentTemplate, ?string $institutionTemplate, ?string $signedBy = null, ?CarbonInterface $signedAt = null): string
    {
        $source = is_string($enrollmentTemplate) && trim($enrollmentTemplate) !== ''
            ? $enrollmentTemplate
            : self::effective($institutionTemplate);

        return self::resolve($source, self::values($enrollment, $institution, $signedBy, $signedAt));
    }

    /**
     * Yer tutucu değerleri. Metin değerleri HTML-escape edilir; *_tablosu ve imza_bloklari HTML üretir.
     *
     * @param array<string, mixed> $institution
     * @return array<string, string>
     */
    public static function values(Enrollment $enrollment, array $institution, ?string $signedBy = null, ?CarbonInterface $signedAt = null): array
    {
        $enrollment->loadMissing(['student', 'program', 'term', 'package', 'classGroup', 'financialGuardian', 'installments']);
        $student = $enrollment->student;
        $guardian = $enrollment->financialGuardian
            ?? $student?->guardians()->orderByDesc('guardian_student.is_financially_responsible')->orderByDesc('guardian_student.is_primary')->first();

        $sym = $institution['currency_symbol'] ?? '₺';
        $money = fn ($v) => Money::format((string) $v).' '.$sym;
        $installments = $enrollment->installments->where('status', '!=', 'cancelled')->sortBy('sequence')->values();

        $guardianName = $guardian ? trim(($guardian->first_name ?? '').' '.($guardian->last_name ?? '')) : '';

        return [
            '{kurum_adi}' => e($institution['name'] ?? ''),
            '{kurum_adres}' => e($institution['address'] ?? ''),
            '{ogrenci_ad}' => e($student?->full_name ?? ''),
            '{ogrenci_no}' => e($student?->student_no ?? ''),
            '{ogrenci_okul}' => e($student?->school_name ?? ''),
            '{veli_ad}' => e($guardianName !== '' ? $guardianName : '—'),
            '{veli_telefon}' => e($guardian?->phone ?? ''),
            '{program}' => e($enrollment->program?->name ?? ''),
            '{donem}' => e($enrollment->term?->name ?? ''),
            '{paket}' => e($enrollment->package?->name ?? '—'),
            '{sinif}' => e($enrollment->classGroup?->name ?? '—'),
            '{kayit_no}' => e($enrollment->enrollment_no ?? ''),
            '{kayit_tarihi}' => e($enrollment->enrolled_on?->format('d.m.Y') ?? ''),
            '{liste_fiyati}' => e($money($enrollment->list_price)),
            '{indirim}' => e($money($enrollment->discount_amount)),
            '{burs}' => e($money($enrollment->scholarship_amount)),
            '{net_bedel}' => e($money($enrollment->net_price)),
            '{net_bedel_yazi}' => e(AmountInWords::lira((string) $enrollment->net_price)),
            '{taksit_sayisi}' => (string) $installments->count(),
            '{tarih}' => e($signedAt ? $signedAt->format('d.m.Y') : ($institution['generated_date'] ?? now()->format('d.m.Y'))),
            '{ucret_tablosu}' => self::feeTable($enrollment, $money, $sym),
            '{taksit_tablosu}' => self::planTable($installments, $money),
            '{imza_bloklari}' => self::signatures($institution['name'] ?? '', $signedBy ?? ($guardianName !== '' ? $guardianName : ''), $signedAt),
        ];
    }

    /** Ücret dökümü tablosu (kv). İndirim/burs yalnız pozitifse gösterilir. */
    public static function feeTable(Enrollment $enrollment, callable $money, string $sym): string
    {
        $rows = ['<tr><td class="k">Liste fiyatı</td><td>'.e($money($enrollment->list_price)).'</td></tr>'];
        if (bccomp((string) $enrollment->discount_amount, '0', 2) > 0) {
            $reason = $enrollment->discount_reason ? ' ('.e($enrollment->discount_reason).')' : '';
            $rows[] = '<tr><td class="k">İndirim</td><td>−'.e($money($enrollment->discount_amount)).$reason.'</td></tr>';
        }
        if (bccomp((string) $enrollment->scholarship_amount, '0', 2) > 0) {
            $reason = $enrollment->scholarship_reason ? ' ('.e($enrollment->scholarship_reason).')' : '';
            $rows[] = '<tr><td class="k">Burs</td><td>−'.e($money($enrollment->scholarship_amount)).$reason.'</td></tr>';
        }
        $rows[] = '<tr><td class="k"><strong>Net eğitim bedeli</strong></td><td><strong>'.e($money($enrollment->net_price)).'</strong> <span class="muted">('.e(AmountInWords::lira((string) $enrollment->net_price)).')</span></td></tr>';

        return '<table class="kv">'.implode('', $rows).'</table>';
    }

    /**
     * Ödeme planı tablosu (grid). Boşsa açıklama satırı döner.
     *
     * @param Collection<int, \App\Models\Installment> $installments
     */
    public static function planTable(Collection $installments, callable $money): string
    {
        if ($installments->isEmpty()) {
            return '<p>Bu kayıt için ödeme planı bulunmamaktadır.</p>';
        }

        $body = '';
        $total = '0.00';
        foreach ($installments->values() as $idx => $i) {
            $total = bcadd($total, (string) $i->amount, 2);
            $body .= '<tr><td>'.($i->sequence ?? $idx + 1).'</td><td>'.e($i->due_date->format('d.m.Y')).'</td><td class="r">'.e($money($i->amount)).'</td></tr>';
        }

        return '<table class="grid"><thead><tr><th>#</th><th>Vade</th><th class="r">Tutar</th></tr></thead><tbody>'
            .$body
            .'<tr class="total"><td colspan="2">Toplam</td><td class="r">'.e($money($total)).'</td></tr>'
            .'</tbody></table>';
    }

    /** İmza alanları (signs). İmzalanmışsa imzalayan ad + tarih gösterilir. */
    public static function signatures(string $institutionName, string $signedBy, ?CarbonInterface $signedAt): string
    {
        $signerLine = $signedAt
            ? '<br><span class="muted">İmza tarihi: '.e($signedAt->format('d.m.Y H:i')).'</span>'
            : '';

        return '<table class="signs"><tr>'
            .'<td><div class="line">Kurum Adına — Yetkili İmza / Kaşe<br><strong>'.e($institutionName).'</strong></div></td>'
            .'<td><div class="line">Veli / Ödeme Sorumlusu — İmza<br><strong>'.e($signedBy).'</strong>'.$signerLine.'</div></td>'
            .'</tr></table>';
    }
}

@php
  // Optik sonuç belgesi düzeni: kurumsal başlık, öğrenci kimlik tablosu, özet, ders sonuçları,
  // sıralamalar, cevap formu (anahtar / öğrenci cevabı) ve konu analizi.
  $n = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
  $inst = $d['institution'];
  $kisaltma = collect(preg_split('/\s+/u', trim($inst['name'])))->filter()->take(3)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
  $toplamSoru = $d['totals']['question_count'];
  $basari = $toplamSoru ? max(0, min(100, $d['totals']['net'] / $toplamSoru * 100)) : 0;
  $harf = fn ($c) => $c === ' ' || $c === '' || $c === null ? '·' : $c;
  $hist = array_slice($d['history'], -8);
  $histMax = max(1, ...array_map(fn ($h) => $h['net'], $hist ?: [['net' => 1]]));
@endphp
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $d['student']['name'] }} — {{ $d['exam']['name'] }}</title>
<style>
  @page { margin: 12mm 12mm 16mm 12mm; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #1b1f27; font-size: 8.4pt; margin: 0; line-height: 1.25; }
  table { border-collapse: collapse; width: 100%; }
  .navy { color: #13233f; }
  .muted { color: #5b6474; }
  .num { text-align: right; }
  .c { text-align: center; }
  .b { font-weight: bold; }

  /* Başlık */
  .ust td { vertical-align: middle; }
  .amblem { width: 16mm; height: 16mm; border-collapse: collapse; }
  .amblem td { background: #13233f; color: #fff; text-align: center; vertical-align: middle; font-weight: bold; font-size: 13pt; letter-spacing: .5px; height: 16mm; padding: 0; }
  .kurum { font-size: 13.5pt; font-weight: bold; color: #13233f; letter-spacing: .2px; }
  .belge { font-size: 8.4pt; color: #5b6474; letter-spacing: 1.6px; margin-top: 1.5mm; }
  .sinavkutu { border: 0.6pt solid #13233f; }
  .sinavkutu td { padding: 1.2mm 2.4mm; font-size: 7.8pt; }
  .sinavkutu .e { color: #5b6474; width: 22mm; }
  .sinavkutu .baslik { background: #13233f; color: #fff; font-weight: bold; font-size: 8.6pt; letter-spacing: .6px; }
  .cizgi { border-top: 1.6pt solid #13233f; margin: 3mm 0 3.5mm; }

  /* Kimlik ve genel tablolar */
  .kimlik td { border: 0.5pt solid #b8c1cf; padding: 1.6mm 2.4mm; }
  .kimlik .e { background: #eef2f7; color: #3a4456; font-size: 7.2pt; letter-spacing: .5px; width: 20mm; }
  .kimlik .v { font-weight: bold; font-size: 9pt; }

  h2 { font-size: 8.6pt; color: #fff; background: #13233f; margin: 4.5mm 0 0; padding: 1.3mm 2.4mm; letter-spacing: 1.2px; font-weight: bold; }
  .tablo th { background: #eef2f7; color: #3a4456; font-size: 7.1pt; font-weight: bold; letter-spacing: .3px; border: 0.5pt solid #b8c1cf; padding: 1.4mm 1.6mm; }
  .tablo td { border: 0.5pt solid #b8c1cf; padding: 1.5mm 1.8mm; }
  .tablo tr.top td { background: #e3e8f0; font-weight: bold; }
  .tablo td.net { font-weight: bold; color: #13233f; }

  .ozet td { border: 0.5pt solid #b8c1cf; text-align: center; padding: 1.6mm 1mm 1.8mm; width: 14.28%; }
  .ozet .e { font-size: 6.8pt; color: #5b6474; letter-spacing: .6px; }
  .ozet .v { font-size: 13pt; font-weight: bold; margin-top: .8mm; }
  .ozet td.vurgu { background: #13233f; color: #fff; }
  .ozet td.vurgu .e { color: #c9d3e6; }
  .dogru { color: #1e7a4f; } .yanlis { color: #b3261e; } .bos { color: #6b7280; }

  .cubuk { height: 2.2mm; background: #e3e8f0; width: 100%; }
  .cubuk div { height: 2.2mm; background: #13233f; }

  /* Cevap formu (optik çıktı) */
  .form { margin-top: 1.6mm; }
  .form td { border: 0.4pt solid #c3cad6; text-align: center; font-family: 'DejaVu Sans Mono', monospace; font-size: 7.2pt; padding: .6mm 0; width: 7.7mm; }
  .form td.e { width: 23mm; white-space: nowrap; text-align: left; padding-left: 1.6mm; font-family: 'DejaVu Sans', sans-serif; font-size: 6.6pt; color: #3a4456; background: #eef2f7; }
  .form tr.no td { color: #5b6474; font-size: 6.2pt; background: #f6f8fb; }
  .form tr.anahtar td { color: #13233f; font-weight: bold; }
  .form td.s-c { background: #e3f1ea; color: #1e7a4f; font-weight: bold; }
  .form td.s-w { background: #f8e1df; color: #b3261e; font-weight: bold; }
  .form td.s-b { color: #9aa1ad; }
  .form td.s-x { background: #eceef2; color: #6b7280; }
  .bolum { margin-top: 2.4mm; font-size: 7.8pt; }
  .bolum .sag { float: right; color: #3a4456; }

  .gelisim td { vertical-align: bottom; text-align: center; padding: 0 1mm; }
  .gelisim .kolon { background: #13233f; width: 7mm; margin: 0 auto; }
  .gelisim .kolon.son { background: #1e7a4f; }
  .gelisim .deger { font-size: 6.6pt; font-weight: bold; }
  .gelisim .etiket { font-size: 6.2pt; color: #5b6474; border-top: 0.5pt solid #b8c1cf; padding-top: .8mm; }

  .yan td.kutu { vertical-align: top; border: 0.5pt solid #b8c1cf; padding: 0; }
  .kutubas { background: #eef2f7; font-size: 7.1pt; font-weight: bold; letter-spacing: .6px; color: #3a4456; padding: 1.3mm 2.2mm; border-bottom: 0.5pt solid #b8c1cf; }
  .kv td { padding: 1.3mm 2.2mm; border-bottom: 0.4pt solid #e1e5ec; }
  .kv td.v { text-align: right; font-weight: bold; color: #13233f; }

  .aciklama { font-size: 6.6pt; color: #5b6474; margin-top: 1.4mm; }
  .aciklama span { display: inline-block; width: 3mm; height: 2.4mm; vertical-align: middle; margin: 0 .8mm 0 2.4mm; border: 0.4pt solid #c3cad6; }

  .imza { margin-top: 6mm; }
  .imza td { width: 50%; vertical-align: bottom; font-size: 7.4pt; color: #3a4456; }
  .imza .alan { border-top: 0.5pt solid #3a4456; width: 55mm; padding-top: 1mm; text-align: center; }

  .alt { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 6.6pt; color: #5b6474; border-top: 0.6pt solid #13233f; padding-top: 1.4mm; }
  .alt .sayfa { float: right; }
  .alt .sayfa:after { content: "Sayfa " counter(page); }
  .kir { page-break-inside: avoid; }
</style>
</head>
<body>
  <div class="alt">
    {{ $inst['name'] }}@if($inst['phone']) · {{ $inst['phone'] }}@endif @if(!empty($inst['footer_line'])) · {{ $inst['footer_line'] }}@endif · Bu belge {{ $d['generated_at'] }} tarihinde elektronik ortamda oluşturulmuştur.
    <span class="sayfa"></span>
  </div>

  {{-- BAŞLIK --}}
  <table class="ust">
    <tr>
      <td style="width: 19mm;">
        @if($inst['logo_path'])
          <img src="{{ $inst['logo_path'] }}" style="max-width: 16mm; max-height: 16mm;">
        @else
          <table class="amblem"><tr><td>{{ $kisaltma ?: 'EB' }}</td></tr></table>
        @endif
      </td>
      <td>
        <div class="kurum">{{ mb_strtoupper($inst['name']) }}</div>
        <div class="belge">DENEME SINAVI SONUÇ BELGESİ</div>
      </td>
      <td style="width: 78mm;">
        <table class="sinavkutu">
          <tr><td colspan="2" class="baslik">{{ mb_strtoupper($d['exam']['name']) }}</td></tr>
          <tr><td class="e">Sınav türü</td><td class="b">{{ $d['exam']['type'] ?: '—' }}</td></tr>
          <tr><td class="e">Sınav tarihi</td><td class="b">{{ $d['exam']['date'] }}</td></tr>
          <tr><td class="e">Yayın</td><td class="b">{{ $d['exam']['publisher'] ?: 'Kurum' }}</td></tr>
        </table>
      </td>
    </tr>
  </table>
  <div class="cizgi"></div>

  {{-- KİMLİK --}}
  <table class="kimlik">
    <tr>
      <td class="e">ADI SOYADI</td><td class="v" style="width: 64mm;">{{ mb_strtoupper($d['student']['name']) }}</td>
      <td class="e">ÖĞRENCİ NO</td><td class="v">{{ $d['student']['no'] ?: '—' }}</td>
    </tr>
    <tr>
      <td class="e">SINIF / ŞUBE</td><td class="v">{{ $d['student']['class'] ?: '—' }}</td>
      <td class="e">KİTAPÇIK</td><td class="v">{{ $d['exam']['booklet'] ?: '—' }}</td>
    </tr>
  </table>

  {{-- ÖZET --}}
  <table class="ozet" style="margin-top: 3mm;">
    <tr>
      <td><div class="e">SORU</div><div class="v">{{ $toplamSoru }}</div></td>
      <td><div class="e">DOĞRU</div><div class="v dogru">{{ $d['totals']['correct'] }}</div></td>
      <td><div class="e">YANLIŞ</div><div class="v yanlis">{{ $d['totals']['wrong'] }}</div></td>
      <td><div class="e">BOŞ</div><div class="v bos">{{ $d['totals']['blank'] }}</div></td>
      <td class="vurgu"><div class="e">TOPLAM NET</div><div class="v">{{ $n($d['totals']['net']) }}</div></td>
      <td class="vurgu"><div class="e">PUAN</div><div class="v">{{ $d['totals']['score'] !== null ? $n($d['totals']['score'], 3) : '—' }}</div></td>
      <td><div class="e">BAŞARI</div><div class="v navy">%{{ $n($basari, 1) }}</div></td>
    </tr>
  </table>

  {{-- DERS SONUÇLARI --}}
  <h2>DERS BAZINDA SONUÇLAR</h2>
  <table class="tablo">
    <thead>
      <tr>
        <th style="text-align: left;">DERS</th>
        <th>SORU</th><th>DOĞRU</th><th>YANLIŞ</th><th>BOŞ</th><th>NET</th>
        <th style="width: 30mm;">BAŞARI</th>
        <th>SINIF ORT.</th><th>KURUM ORT.</th>
      </tr>
    </thead>
    <tbody>
    @foreach($answers as $sec)
      @php
        $bs = collect($d['sections'])->firstWhere('code', $sec['code']) ?? ['question_count' => count($sec['questions'])];
        $qc = (int) ($bs['question_count'] ?? count($sec['questions']));
        $yuzde = $qc ? max(0, min(100, $sec['net'] / $qc * 100)) : 0;
      @endphp
      <tr>
        <td class="b">{{ $sec['name'] }}</td>
        <td class="c">{{ $qc }}</td>
        <td class="c dogru">{{ $sec['correct'] }}</td>
        <td class="c yanlis">{{ $sec['wrong'] }}</td>
        <td class="c bos">{{ $sec['blank'] }}</td>
        <td class="num net">{{ $n($sec['net']) }}</td>
        <td>
          <table><tr>
            <td style="border: 0; padding: 0; width: 20mm;"><div class="cubuk"><div style="width: {{ round($yuzde) }}%;"></div></div></td>
            <td style="border: 0; padding: 0 0 0 1.4mm; font-size: 7pt;" class="num">%{{ $n($yuzde, 0) }}</td>
          </tr></table>
        </td>
        <td class="num">{{ isset($ort['sinif'][$sec['id']]) ? $n($ort['sinif'][$sec['id']]) : '—' }}</td>
        <td class="num">{{ isset($ort['kurum'][$sec['id']]) ? $n($ort['kurum'][$sec['id']]) : '—' }}</td>
      </tr>
    @endforeach
      <tr class="top">
        <td>TOPLAM</td>
        <td class="c">{{ $toplamSoru }}</td>
        <td class="c">{{ $d['totals']['correct'] }}</td>
        <td class="c">{{ $d['totals']['wrong'] }}</td>
        <td class="c">{{ $d['totals']['blank'] }}</td>
        <td class="num">{{ $n($d['totals']['net']) }}</td>
        <td class="num">%{{ $n($basari, 0) }}</td>
        <td class="num">{{ $ort['sinif_net'] !== null ? $n($ort['sinif_net']) : '—' }}</td>
        <td class="num">{{ $n($ort['kurum_net']) }}</td>
      </tr>
    </tbody>
  </table>

  {{-- SIRALAMA + GELİŞİM --}}
  <table class="yan kir" style="margin-top: 4.5mm;">
    <tr>
      <td class="kutu" style="width: 44%;">
        <div class="kutubas">SIRALAMALAR</div>
        <table class="kv">
          <tr><td>Sınıf sıralaması</td><td class="v">{{ $d['ranks']['class'] ? $d['ranks']['class'].' / '.$d['ranks']['class_total'] : '—' }}</td></tr>
          <tr><td>Kurum sıralaması</td><td class="v">{{ $d['ranks']['institution'] ? $d['ranks']['institution'].' / '.$d['ranks']['institution_total'] : '—' }}</td></tr>
          <tr><td>Türkiye geneli</td><td class="v">{{ $d['ranks']['national'] ? number_format($d['ranks']['national'], 0, ',', '.') : ($d['exam']['scope'] === 'national' ? 'Açıklanmadı' : '—') }}</td></tr>
          <tr><td>Sınıf ortalaması (net)</td><td class="v">{{ $ort['sinif_net'] !== null ? $n($ort['sinif_net']) : '—' }}</td></tr>
          <tr><td style="border-bottom: 0;">Kurum ortalaması (net)</td><td class="v" style="border-bottom: 0;">{{ $n($ort['kurum_net']) }}</td></tr>
        </table>
      </td>
      <td style="width: 3%; border: 0;"></td>
      <td class="kutu">
        <div class="kutubas">NET GELİŞİMİ @if($d['exam']['type'])· {{ mb_strtoupper($d['exam']['type']) }}@endif</div>
        @if(count($hist) >= 2)
          <table class="gelisim" style="margin: 2mm 0 1.6mm; height: 30mm;">
            <tr>
              @foreach($hist as $i => $h)
                @php $boy = max(1.5, round($h['net'] / $histMax * 22, 1)); @endphp
                <td>
                  <div class="deger">{{ $n($h['net'], 1) }}</div>
                  <div class="kolon {{ $i === count($hist) - 1 ? 'son' : '' }}" style="height: {{ $boy }}mm;"></div>
                </td>
              @endforeach
            </tr>
            <tr>
              @foreach($hist as $h)
                <td class="etiket">{{ $h['label'] }}</td>
              @endforeach
            </tr>
          </table>
        @else
          <div style="padding: 6mm 2.4mm; color: #5b6474;">Bu sınav türünde ilk deneme; gelişim grafiği sonraki denemelerle oluşur.</div>
        @endif
      </td>
    </tr>
  </table>

  {{-- CEVAP FORMU --}}
  @if(!empty($answers))
    <h2>CEVAP FORMU</h2>
    @foreach($answers as $sec)
      <div class="kir">
        <div class="bolum"><span class="b">{{ mb_strtoupper($sec['name']) }}</span>
          <span class="sag">D {{ $sec['correct'] }} · Y {{ $sec['wrong'] }} · B {{ $sec['blank'] }} · <span class="b navy">Net {{ $n($sec['net']) }}</span></span>
        </div>
        @foreach(array_chunk($sec['questions'], 20) as $parca)
          <table class="form">
            <tr class="no"><td class="e">Soru</td>@foreach($parca as $q)<td>{{ $q['number'] }}</td>@endforeach @for($k = count($parca); $k < 20; $k++)<td></td>@endfor</tr>
            <tr class="anahtar"><td class="e">Cevap anahtarı</td>@foreach($parca as $q)<td>{{ $q['state'] === 'x' ? 'İ' : $harf($q['key']) }}</td>@endforeach @for($k = count($parca); $k < 20; $k++)<td></td>@endfor</tr>
            <tr><td class="e">Öğrenci cevabı</td>@foreach($parca as $q)<td class="s-{{ $q['state'] }}">{{ $harf($q['given']) }}</td>@endforeach @for($k = count($parca); $k < 20; $k++)<td></td>@endfor</tr>
          </table>
        @endforeach
      </div>
    @endforeach
    <div class="aciklama">
      Açıklama:<span style="background: #e3f1ea;"></span>Doğru<span style="background: #f8e1df;"></span>Yanlış<span></span>Boş (·)<span style="background: #eceef2;"></span>İptal (İ) — cevaplar soru sırasıyla gösterilmiştir.
    </div>
  @endif

  {{-- KONU ANALİZİ --}}
  @if(!empty($konular))
    <h2>KONU ANALİZİ</h2>
    <table class="tablo">
      <thead>
        <tr>
          <th style="text-align: left; width: 34mm;">DERS</th><th style="text-align: left;">KONU</th>
          <th>SORU</th><th>D</th><th>Y</th><th>B</th><th style="width: 30mm;">BAŞARI</th>
        </tr>
      </thead>
      <tbody>
        @foreach($konular as $k)
          @php $ky = $k['soru'] ? round($k['d'] / $k['soru'] * 100) : 0; @endphp
          <tr class="kir">
            <td class="muted">{{ $k['ders'] }}</td>
            <td>{{ $k['konu'] }}</td>
            <td class="c">{{ $k['soru'] }}</td>
            <td class="c dogru">{{ $k['d'] }}</td>
            <td class="c yanlis">{{ $k['y'] }}</td>
            <td class="c bos">{{ $k['b'] }}</td>
            <td>
              <table><tr>
                <td style="border: 0; padding: 0; width: 20mm;"><div class="cubuk"><div style="width: {{ $ky }}%; background: {{ $ky >= 70 ? '#1e7a4f' : ($ky >= 40 ? '#13233f' : '#b3261e') }};"></div></div></td>
                <td style="border: 0; padding: 0 0 0 1.4mm; font-size: 7pt;" class="num">%{{ $ky }}</td>
              </tr></table>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <table class="imza kir">
    <tr>
      <td>Veli imzası<div class="alan" style="margin-top: 12mm;">&nbsp;</div></td>
      <td style="text-align: right;">{{ $inst['name'] }}<div class="alan" style="margin: 12mm 0 0 auto;">Kurum Müdürü · Mühür</div></td>
    </tr>
  </table>
</body>
</html>

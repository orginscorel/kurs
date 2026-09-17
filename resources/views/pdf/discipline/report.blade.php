<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Disiplin raporu {{ $r['from'] }} – {{ $r['to'] }}</title>
@php($d = fn ($v) => \Carbon\Carbon::parse($v)->format('d.m.Y'))
@php($n = fn ($v) => number_format((int) $v, 0, ',', '.'))
@include('pdf.discipline._style')
</head>
<body>
@include('pdf.discipline._footer')
@include('pdf.discipline._header', ['docLabel' => 'RAPOR MERKEZİ', 'boxTitle' => 'DİSİPLİN RAPORU', 'boxRows' => [
  'Dönem' => $d($r['from']).' – '.$d($r['to']), 'Kapsam' => $scope,
]])

@php($t = $r['totals'])
<table class="kpi">
  <tr>
    <td class="vurgu"><div class="e">OLAY</div><div class="v">{{ $n($t['incidents']) }}</div></td>
    <td><div class="e">ÖĞRENCİ</div><div class="v">{{ $n($t['students']) }}</div></td>
    <td><div class="e">CEZA PUANI</div><div class="v kirmizi">{{ $n($t['penalty']) }}</div></td>
    <td><div class="e">YAPTIRIM</div><div class="v">{{ $n($t['sanctions']) }}</div></td>
    <td><div class="e">UZAKLAŞTIRMA GÜNÜ</div><div class="v">{{ $n($t['suspension_days']) }}</div></td>
    <td><div class="e">OLUMLU KAYIT</div><div class="v yesil">{{ $n($t['positives']) }}</div></td>
  </tr>
</table>

<table style="margin-top: 1mm;"><tr>
<td style="width: 50%; vertical-align: top; padding-right: 2mm;">
  <h2>EN SIK DAVRANIŞLAR</h2>
  <table class="tablo">
    <thead><tr><th>Davranış</th><th class="r" style="width: 14mm;">Sayı</th><th style="width: 16mm;"></th></tr></thead>
    <tbody>
    @php($neg = collect($r['by_behavior'])->where('kind', 'negative')->values())
    @php($max = max(1, $neg->max('count') ?? 1))
    @forelse($neg->take(12) as $b)
      <tr><td>{{ $b['name'] }}</td><td class="r">{{ $b['count'] }}</td><td><div class="cubuk"><div style="width: {{ round($b['count'] / $max * 100) }}%;"></div></div></td></tr>
    @empty
      <tr><td colspan="3" class="muted">Kayıt yok.</td></tr>
    @endforelse
    </tbody>
  </table>
</td>
<td style="width: 50%; vertical-align: top; padding-left: 2mm;">
  <h2>YAPTIRIM DAĞILIMI</h2>
  <table class="tablo">
    <thead><tr><th>Yaptırım</th><th class="r" style="width: 14mm;">Sayı</th><th class="r" style="width: 18mm;">Yürürlükte</th></tr></thead>
    <tbody>
    @forelse($r['by_sanction'] as $s)
      <tr><td>{{ $s['name'] }}</td><td class="r">{{ $s['count'] }}</td><td class="r">{{ $s['active'] }}</td></tr>
    @empty
      <tr><td colspan="3" class="muted">Yaptırım yok.</td></tr>
    @endforelse
    </tbody>
  </table>
  <h2>KATEGORİLER</h2>
  <table class="tablo">
    <tbody>
    @foreach(collect($r['by_category'])->where('kind', 'negative') as $c)
      <tr><td>{{ $c['label'] }}</td><td class="r" style="width: 14mm;">{{ $c['count'] }}</td></tr>
    @endforeach
    </tbody>
  </table>
</td>
</tr></table>

<h2>SINIF BAZINDA</h2>
<table class="tablo">
  <thead><tr><th>Sınıf</th><th class="r">Olay</th><th class="r">Öğrenci</th><th class="r">Ceza puanı</th><th class="r">Yaptırım</th><th class="r">Olumlu</th></tr></thead>
  <tbody>
  @forelse($r['by_class'] as $c)
    <tr><td>{{ $c['name'] }}</td><td class="r">{{ $c['incidents'] }}</td><td class="r">{{ $c['students'] }}</td><td class="r">{{ $c['penalty'] }}</td><td class="r">{{ $c['sanctions'] }}</td><td class="r">{{ $c['positives'] }}</td></tr>
  @empty
    <tr><td colspan="6" class="muted">Kayıt yok.</td></tr>
  @endforelse
  </tbody>
</table>

<h2>TEKRAR EDEN ÖĞRENCİLER (2+ OLAY)</h2>
<table class="tablo">
  <thead><tr><th>No</th><th>Ad Soyad</th><th>Sınıf</th><th class="r">Olay</th><th class="r">Ceza puanı</th><th class="r">Olumlu</th><th>Seviye</th><th>Son olay</th></tr></thead>
  <tbody>
  @forelse($r['repeaters'] as $s)
    <tr><td class="muted">{{ $s['student_no'] }}</td><td>{{ $s['full_name'] }}</td><td>{{ $s['class_names'] }}</td><td class="r">{{ $s['incidents'] }}</td><td class="r b">{{ $s['penalty'] }}</td><td class="r">{{ $s['merit'] }}</td><td>{{ $s['level_label'] }}</td><td>{{ $d($s['last_at']) }}</td></tr>
  @empty
    <tr><td colspan="8" class="muted">Tekrar eden öğrenci yok.</td></tr>
  @endforelse
  </tbody>
</table>

@if(count($r['by_teacher']))
<h2>BİLDİREN ÖĞRETMEN / PERSONEL</h2>
<table class="tablo">
  <thead><tr><th>Ad</th><th class="r">Olay</th><th class="r">Olumlu kayıt</th></tr></thead>
  <tbody>
  @foreach($r['by_teacher'] as $t2)
    <tr><td>{{ $t2['name'] }}</td><td class="r">{{ $t2['incidents'] }}</td><td class="r">{{ $t2['positives'] }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

<h2>AYLIK EĞİLİM</h2>
<table class="tablo">
  <thead><tr><th>Ay</th><th class="r">Olay</th><th class="r">Olumlu</th><th class="r">Yaptırım</th></tr></thead>
  <tbody>
  @foreach($r['trend'] as $m)
    <tr><td>{{ $m['label'] }}</td><td class="r">{{ $m['incidents'] }}</td><td class="r">{{ $m['positives'] }}</td><td class="r">{{ $m['sanctions'] }}</td></tr>
  @endforeach
  </tbody>
</table>
</body>
</html>

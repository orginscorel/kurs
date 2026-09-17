<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Devamsızlık raporu {{ $r['from'] }} – {{ $r['to'] }}</title>
@php($d = fn ($v) => \Carbon\Carbon::parse($v)->format('d.m.Y'))
@php($p = fn ($v) => $v === null ? '—' : '%'.number_format($v, 1, ',', '.'))
<style>
  @page { margin: 14mm 12mm 16mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 8.5pt; color: #16161d; margin: 0; }
  .brand { width: 100%; border-bottom: 2px solid #4b3fe3; padding-bottom: 6px; margin-bottom: 12px; }
  .name { font-size: 13pt; font-weight: bold; }
  .sub { font-size: 7.5pt; color: #55556a; }
  .doc { text-align: right; }
  .doc .title { font-size: 11pt; font-weight: bold; color: #4b3fe3; }
  h2 { font-size: 9.5pt; margin: 14px 0 6px; color: #3a2fc2; }
  table.kpi { width: 100%; border-collapse: separate; border-spacing: 4px; margin: 0 -4px; }
  table.kpi td { border: 1px solid #e7e7ec; padding: 6px 8px; width: 25%; }
  table.kpi .l { font-size: 7.5pt; color: #8e8ea0; }
  table.kpi .v { font-size: 11.5pt; font-weight: bold; margin-top: 2px; }
  table.grid { width: 100%; border-collapse: collapse; }
  table.grid th { text-align: right; color: #8e8ea0; font-weight: normal; border-bottom: 1px solid #d6d6de; padding: 4px 3px; font-size: 7.5pt; }
  table.grid th.l, table.grid td.l { text-align: left; }
  table.grid td { text-align: right; border-bottom: 1px solid #f0f0f4; padding: 3px; }
  .neg { color: #d6344b; }
  .muted { color: #8e8ea0; }
  .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; color: #8e8ea0; text-align: center; }
</style>
</head>
<body>
<table class="brand">
  <tr>
    <td><div class="name">{{ $institution['name'] ?? '' }}</div><div class="sub">{{ $institution['address'] ?? '' }}</div></td>
    <td class="doc">
      <div class="title">Devamsızlık Raporu</div>
      <div class="sub">{{ $d($r['from']) }} – {{ $d($r['to']) }}{{ $className ? ' · '.$className : ' · Tüm sınıflar' }}</div>
    </td>
  </tr>
</table>

@php($t = $r['totals'])
<table class="kpi">
  <tr>
    <td><div class="l">Katılım oranı</div><div class="v">{{ $p($t['rate']) }}</div><div class="sub">var + geç / tüm yoklama kaydı</div></td>
    <td><div class="l">Yoklama kaydı</div><div class="v">{{ number_format($t['records'], 0, ',', '.') }}</div><div class="sub">{{ $t['students'] }} öğrenci</div></td>
    <td><div class="l">Gelmedi</div><div class="v neg">{{ number_format($t['absent'], 0, ',', '.') }}</div><div class="sub">geç {{ $t['late'] }} · izinli {{ $t['excused'] }} · raporlu {{ $t['medical'] }}</div></td>
    <td><div class="l">Ders</div><div class="v">{{ $t['sessions'] }}</div><div class="sub">yoklama alınan {{ $t['taken_sessions'] }} · eksik {{ $t['missing_sessions'] }} · iptal {{ $t['cancelled_sessions'] }}</div></td>
  </tr>
</table>

@if(count($r['by_class']))
<h2>Sınıf bazında</h2>
<table class="grid">
  <thead><tr><th class="l">Sınıf</th><th>Öğrenci</th><th>Kayıt</th><th>Var</th><th>Yok</th><th>Geç</th><th>İzinli</th><th>Raporlu</th><th>Katılım</th></tr></thead>
  <tbody>
  @foreach($r['by_class'] as $c)
    <tr><td class="l">{{ $c['name'] }}</td><td>{{ $c['students'] }}</td><td>{{ $c['records'] }}</td><td>{{ $c['present'] }}</td><td class="{{ $c['absent'] ? 'neg' : '' }}">{{ $c['absent'] }}</td><td>{{ $c['late'] }}</td><td>{{ $c['excused'] }}</td><td>{{ $c['medical'] }}</td><td>{{ $p($c['rate']) }}</td></tr>
  @endforeach
  </tbody>
</table>
@endif

<h2>Devamsızlığı olan öğrenciler</h2>
@if(count($students))
<table class="grid">
  <thead><tr><th class="l">No</th><th class="l">Ad Soyad</th><th class="l">Sınıf</th><th>Kayıt</th><th>Yok</th><th>Geç</th><th>İzinli</th><th>Raporlu</th><th>Katılım</th></tr></thead>
  <tbody>
  @foreach($students as $s)
    <tr><td class="l muted">{{ $s['student_no'] }}</td><td class="l">{{ $s['full_name'] }}</td><td class="l">{{ $s['class_names'] }}</td><td>{{ $s['records'] }}</td><td class="{{ $s['absent'] ? 'neg' : '' }}">{{ $s['absent'] }}</td><td>{{ $s['late'] }}</td><td>{{ $s['excused'] }}</td><td>{{ $s['medical'] }}</td><td>{{ $p($s['rate']) }}</td></tr>
  @endforeach
  </tbody>
</table>
@else
<p class="muted">Bu aralıkta devamsızlık kaydı yok.</p>
@endif

<div class="foot">{{ now()->format('d.m.Y H:i') }} tarihinde oluşturuldu</div>
</body>
</html>

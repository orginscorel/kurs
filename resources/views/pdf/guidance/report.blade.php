<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Rehberlik Raporu — {{ $student->full_name }}</title>
<style>
  @page { margin: 14mm 12mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 9.5pt; color: #16161d; margin: 0; }
  .brand { width: 100%; border-bottom: 2px solid #4b3fe3; padding-bottom: 8px; margin-bottom: 12px; }
  .brand td { vertical-align: middle; }
  .name { font-size: 14pt; font-weight: bold; color: #16161d; letter-spacing: -0.2px; }
  .sub { font-size: 8pt; color: #55556a; }
  .doc { text-align: right; }
  .doc .title { font-size: 11pt; font-weight: bold; color: #4b3fe3; text-transform: uppercase; letter-spacing: 1px; }
  .doc .no { font-size: 9pt; color: #16161d; margin-top: 2px; }
  h2 { font-size: 10.5pt; color: #16161d; margin: 16px 0 6px; border-bottom: 1px solid #e7e7ec; padding-bottom: 4px; }
  table.info { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  table.info td { padding: 3px 0; vertical-align: top; }
  table.info .label { color: #8e8ea0; width: 26%; font-size: 8.5pt; }
  table.grid { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
  table.grid th { text-align: left; color: #8e8ea0; font-weight: normal; border-bottom: 1px solid #e7e7ec; padding: 4px 3px; }
  table.grid td { border-bottom: 1px solid #f0f0f4; padding: 4px 3px; vertical-align: top; }
  .right { text-align: right; }
  .center { text-align: center; }
  .pct-good { color: #0c9467; font-weight: bold; }
  .pct-bad { color: #d6344b; font-weight: bold; }
  .pct-mid { color: #e0791f; font-weight: bold; }
  .foot { position: fixed; bottom: -6mm; left: 0; right: 0; font-size: 7pt; color: #8e8ea0; text-align: center; }
  .empty { color: #8e8ea0; font-style: italic; }
  .kvkk { margin-top: 14px; font-size: 7.5pt; color: #8e8ea0; border-top: 1px solid #e7e7ec; padding-top: 6px; }
</style>
</head>
<body>

<table class="brand">
  <tr>
    <td>
      @if ($institution['logo_data'])
        <img src="{{ $institution['logo_data'] }}" style="height: 34px; margin-bottom: 4px;"><br>
      @endif
      <div class="name">{{ $institution['name'] }}</div>
      <div class="sub">{{ collect([$institution['address'], $institution['phone'], $institution['email']])->filter()->join(' · ') }}</div>
    </td>
    <td class="doc">
      <div class="title">Rehberlik Raporu</div>
      <div class="no">Öğrenci No {{ $student->student_no }}</div>
      <div class="sub">{{ $generatedAt->format('d.m.Y H:i') }}</div>
    </td>
  </tr>
</table>

<table class="info">
  <tr><td class="label">Öğrenci</td><td><strong>{{ $student->full_name }}</strong></td></tr>
  <tr><td class="label">Okul / Sınıf</td><td>{{ $student->school_name ?? '—' }} @if($student->school_grade) · {{ $student->school_grade }}. sınıf @endif</td></tr>
  <tr><td class="label">Hedef</td><td>{{ collect([$student->target_university, $student->target_department])->filter()->join(' — ') ?: '—' }}</td></tr>
</table>

<h2>Hedefler ve Gerçekleşen (Son 3 Sınav Ortalaması)</h2>
@if ($goals->isEmpty())
  <p class="empty">Tanımlı hedef bulunmuyor.</p>
@else
  @foreach ($goals as $row)
    @php($g = $row['goal'])
    @php($p = $row['progress'])
    <table class="info" style="margin-top: 8px;">
      <tr><td class="label">Hedef</td><td><strong>{{ collect([$g->university, $g->department])->filter()->join(' — ') ?: 'Genel hedef' }}</strong>
        @if($g->target_rank) · Hedef sıralama: {{ number_format($g->target_rank, 0, ',', '.') }} @endif
        @unless($g->is_active) <span class="sub">(pasif)</span> @endunless
      </td></tr>
    </table>
    <table class="grid">
      <thead><tr><th>Bölüm</th><th class="right">Hedef net</th><th class="right">Gerçekleşen</th><th class="right">İlerleme</th><th class="right">Fark</th></tr></thead>
      <tbody>
        <tr>
          <td>TYT</td>
          <td class="right">{{ $p['tyt']['target'] !== null ? number_format($p['tyt']['target'], 2, ',', '.') : '—' }}</td>
          <td class="right">{{ $p['tyt']['actual'] !== null ? number_format($p['tyt']['actual'], 2, ',', '.') : '—' }}</td>
          <td class="right @if($p['tyt']['pct'] !== null) {{ $p['tyt']['pct'] >= 90 ? 'pct-good' : ($p['tyt']['pct'] >= 70 ? 'pct-mid' : 'pct-bad') }} @endif">{{ $p['tyt']['pct'] !== null ? '%'.number_format($p['tyt']['pct'], 0) : '—' }}</td>
          <td class="right">{{ $p['tyt']['diff'] !== null ? ($p['tyt']['diff'] >= 0 ? '+' : '').number_format($p['tyt']['diff'], 2, ',', '.') : '—' }}</td>
        </tr>
        <tr>
          <td>AYT</td>
          <td class="right">{{ $p['ayt']['target'] !== null ? number_format($p['ayt']['target'], 2, ',', '.') : '—' }}</td>
          <td class="right">{{ $p['ayt']['actual'] !== null ? number_format($p['ayt']['actual'], 2, ',', '.') : '—' }}</td>
          <td class="right @if($p['ayt']['pct'] !== null) {{ $p['ayt']['pct'] >= 90 ? 'pct-good' : ($p['ayt']['pct'] >= 70 ? 'pct-mid' : 'pct-bad') }} @endif">{{ $p['ayt']['pct'] !== null ? '%'.number_format($p['ayt']['pct'], 0) : '—' }}</td>
          <td class="right">{{ $p['ayt']['diff'] !== null ? ($p['ayt']['diff'] >= 0 ? '+' : '').number_format($p['ayt']['diff'], 2, ',', '.') : '—' }}</td>
        </tr>
        @foreach ($p['subjects'] as $s)
          <tr>
            <td>{{ $s['code'] }}</td>
            <td class="right">{{ $s['target'] !== null ? number_format($s['target'], 2, ',', '.') : '—' }}</td>
            <td class="right">{{ $s['actual'] !== null ? number_format($s['actual'], 2, ',', '.') : '—' }}</td>
            <td class="right @if($s['pct'] !== null) {{ $s['pct'] >= 90 ? 'pct-good' : ($s['pct'] >= 70 ? 'pct-mid' : 'pct-bad') }} @endif">{{ $s['pct'] !== null ? '%'.number_format($s['pct'], 0) : '—' }}</td>
            <td class="right">{{ $s['diff'] !== null ? ($s['diff'] >= 0 ? '+' : '').number_format($s['diff'], 2, ',', '.') : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endforeach
@endif

<h2>Görüşme Özeti (son {{ $meetings->count() }} görüşme)</h2>
@if ($meetings->isEmpty())
  <p class="empty">Kayıtlı görüşme bulunmuyor.</p>
@else
  <table class="grid">
    <thead><tr><th style="width:13%">Tarih</th><th style="width:12%">Tür</th><th>Özet ve hedef</th><th class="center" style="width:11%">Motivasyon</th><th class="center" style="width:11%">Çalışma düzeni</th></tr></thead>
    <tbody>
      @foreach ($meetings as $m)
        <tr>
          <td>{{ $m->met_at->format('d.m.Y') }}</td>
          <td>{{ \App\Models\GuidanceMeeting::KINDS[$m->kind] ?? $m->kind }}</td>
          <td>{{ $m->summary }}@if($m->goal) <br><span class="sub">Hedef: {{ $m->goal }}</span>@endif</td>
          <td class="center">{{ $m->motivation ?? '—' }}/5</td>
          <td class="center">{{ $m->study_discipline ?? '—' }}/5</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

<div class="kvkk">Bu rapor {{ $student->full_name }} öğrencisine ait rehberlik kayıtlarının özetidir; gizli rehberlik notları bu belgeye dahil edilmemiştir. KVKK kapsamında yalnızca yetkili personelle paylaşılmalıdır.</div>

<div class="foot">{{ $institution['name'] }} — {{ $generatedAt->format('d.m.Y H:i') }} tarihinde oluşturuldu</div>

</body>
</html>

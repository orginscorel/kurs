<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Disiplin kurulu karar tutanağı — {{ $meeting->meeting_no }}</title>
@include('pdf.discipline._style')
</head>
<body>
@include('pdf.discipline._footer')
@include('pdf.discipline._header', ['docLabel' => 'DİSİPLİN KURULU', 'boxTitle' => 'KARAR TUTANAĞI', 'boxRows' => [
  'Toplantı no' => $meeting->meeting_no, 'Tarih' => $meeting->scheduled_at->format('d.m.Y H:i'), 'Durum' => $statusLabel,
]])

<h1>DİSİPLİN KURULU KARAR TUTANAĞI</h1>

<table class="kimlik">
  <tr><td class="e">TOPLANTI</td><td class="v">{{ $meeting->title }}</td><td class="e">YER</td><td class="v">{{ $meeting->location ?: '—' }}</td></tr>
  <tr><td class="e">HAZIR BULUNAN</td><td class="v" colspan="3">{{ collect($meeting->members ?? [])->where('present', true)->map(fn ($m) => $m['name'].' ('.($roles[$m['role']] ?? $m['role']).')')->implode(', ') ?: '—' }}</td></tr>
  @php($absent = collect($meeting->members ?? [])->where('present', false))
  @if($absent->isNotEmpty())
  <tr><td class="e">KATILMAYAN</td><td class="v" colspan="3">{{ $absent->pluck('name')->implode(', ') }}</td></tr>
  @endif
</table>

<h2>GÜNDEM VE KARARLAR</h2>
<table class="tablo">
  <thead><tr><th style="width: 7mm;">#</th><th>Olay / öğrenci</th><th>Görüşülen</th><th style="width: 22mm;">Oy (K/R/Ç)</th><th style="width: 24mm;">Sonuç</th></tr></thead>
  <tbody>
  @forelse($items as $i => $it)
    <tr>
      <td class="c">{{ $i + 1 }}</td>
      <td><b>{{ $it['incident_no'] }}</b> · {{ $it['occurred'] }}<br>{{ $it['student'] ?: $it['students'] }}<br><span class="muted">{{ $it['behaviors'] }}</span></td>
      <td>{{ $it['sanction'] ?: 'Genel görüşme' }}@if($it['period'])<br><span class="muted">{{ $it['period'] }}</span>@endif @if($it['decision'])<br><span class="metin">{{ $it['decision'] }}</span>@endif</td>
      <td class="c">{{ $it['votes'] }}</td>
      <td class="b {{ $it['result'] === 'accepted' ? 'navy' : ($it['result'] === 'rejected' ? 'kirmizi' : 'muted') }}">{{ $it['result_label'] }}</td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted c">Gündem maddesi yok.</td></tr>
  @endforelse
  </tbody>
</table>

@if($meeting->notes)
<h2>TOPLANTI NOTLARI</h2>
<div class="kutu metin">{{ $meeting->notes }}</div>
@endif

<p class="metin" style="margin-top: 4mm;">Yukarıdaki kararlar toplantıya katılan üyelerin oylarıyla alınmış ve tutanak altına alınmıştır. Kararlar öğrenci ve velisine yazılı olarak bildirilir; bildirimden itibaren 5 gün içinde yazılı itiraz edilebilir.</p>

<table class="imza">
  <tr>
  @foreach(collect($meeting->members ?? [])->where('present', true)->take(6) as $m)
    <td><div class="cizgi-imza">{{ $roles[$m['role']] ?? '' }}<br>{{ $m['name'] }}</div></td>
    @if($loop->iteration % 3 === 0 && ! $loop->last)</tr><tr>@endif
  @endforeach
  </tr>
</table>
</body>
</html>

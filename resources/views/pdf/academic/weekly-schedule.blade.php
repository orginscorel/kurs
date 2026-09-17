<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $viewLabel }} — {{ $title }}</title>
<style>
  @page { margin: 11mm 10mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 8pt; color: #1b1b22; margin: 0; }
  .brand { width: 100%; border-bottom: 1px solid #1b1b22; padding-bottom: 6px; margin-bottom: 10px; }
  .brand td { vertical-align: bottom; }
  .inst { font-size: 12pt; font-weight: bold; letter-spacing: -0.2px; }
  .muted { color: #6b6b78; font-size: 7.5pt; }
  .doc { text-align: right; }
  .doc .kind { font-size: 7.5pt; color: #6b6b78; text-transform: uppercase; letter-spacing: 1.2px; }
  .doc .name { font-size: 13pt; font-weight: bold; margin-top: 1px; }
  table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.grid th { font-weight: normal; color: #6b6b78; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .6px; padding: 5px 4px; border-bottom: 1px solid #1b1b22; text-align: left; }
  table.grid td { border-bottom: 1px solid #e4e4ea; padding: 4px; vertical-align: top; height: 34px; }
  table.grid td.time { width: 62px; color: #3b3b46; font-size: 7.5pt; white-space: nowrap; border-right: 1px solid #e4e4ea; }
  .cell { border-left: 2px solid #9a9aa8; padding: 1px 0 1px 5px; margin-bottom: 2px; }
  .cell .s { font-weight: bold; font-size: 8pt; }
  .cell .l { color: #3b3b46; font-size: 7pt; }
  .cell .r { color: #6b6b78; font-size: 6.8pt; }
  .summary { margin-top: 10px; font-size: 7.5pt; color: #3b3b46; }
  .summary span { margin-right: 12px; }
  .empty { padding: 30px 0; text-align: center; color: #6b6b78; }
  .foot { position: fixed; bottom: -5mm; left: 0; right: 0; font-size: 6.8pt; color: #8e8e9a; text-align: center; }
</style>
</head>
<body>

<table class="brand">
  <tr>
    <td>
      @if ($institution['logo_data'])
        <img src="{{ $institution['logo_data'] }}" style="height: 28px; margin-bottom: 3px;"><br>
      @endif
      <div class="inst">{{ $institution['name'] ?? 'Erbaa Bilgi Eğitim' }}</div>
      <div class="muted">{{ collect([$institution['address'] ?? null, $institution['phone'] ?? null])->filter()->join(' · ') }}</div>
    </td>
    <td class="doc">
      <div class="kind">{{ $viewLabel }}</div>
      <div class="name">{{ $title }}</div>
      <div class="muted">{{ $subtitle }}</div>
      <div class="muted">Hafta: {{ $weekStart->format('d.m.Y') }} – {{ $weekEnd->format('d.m.Y') }}</div>
    </td>
  </tr>
</table>

@if ($periods->isEmpty())
  <div class="empty">Bu hafta için tanımlı ders yok.</div>
@else
<table class="grid">
  <thead>
    <tr>
      <th style="width: 62px;">Saat</th>
      @foreach ($weekdays as $wd)
        <th>{{ $dayNames[$wd] }} <span style="text-transform:none; letter-spacing:0;">{{ $weekStart->addDays($wd - 1)->format('d.m') }}</span></th>
      @endforeach
    </tr>
  </thead>
  <tbody>
    @foreach ($periods as $period)
      <tr>
        <td class="time">{{ $period }}</td>
        @foreach ($weekdays as $wd)
          <td>
            @foreach ($grid[$period][$wd] ?? [] as $cell)
              <div class="cell">
                <div class="s">{{ $cell['subject'] }}</div>
                @if ($cell['line'])<div class="l">{{ $cell['line'] }}</div>@endif
                @if ($cell['room'])<div class="r">{{ $cell['room'] }}</div>@endif
              </div>
            @endforeach
          </td>
        @endforeach
      </tr>
    @endforeach
  </tbody>
</table>

<div class="summary">
  <strong>Haftalık {{ $lessonCount }} ders saati</strong> —
  @foreach ($totals as $name => $count)
    <span>{{ $name }}: {{ $count }}</span>
  @endforeach
</div>
@endif

<div class="foot">{{ $institution['name'] ?? 'Erbaa Bilgi Eğitim' }} · {{ $generatedAt->format('d.m.Y H:i') }} tarihinde oluşturuldu · Program değişikliklerini takvim uygulamanızdan takip edebilirsiniz.</div>

</body>
</html>

<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Sözleşme {{ $contract->contract_no }}</title>
<style>
  @page { margin: 16mm 16mm 18mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 9.5pt; color: #16161d; line-height: 1.45; margin: 0; }
  .brand { width: 100%; border-bottom: 2px solid #4b3fe3; padding-bottom: 8px; margin-bottom: 14px; }
  .brand td { vertical-align: middle; }
  .name { font-size: 14pt; font-weight: bold; }
  .sub, .muted { font-size: 8pt; color: #55556a; }
  .doc { text-align: right; }
  .doc .no { font-size: 10pt; font-weight: bold; color: #4b3fe3; }
  h1 { font-size: 13pt; margin: 0 0 2px; }
  h2 { font-size: 10pt; margin: 14px 0 6px; color: #3a2fc2; border-bottom: 1px solid #e7e7ec; padding-bottom: 3px; }
  .meta { color: #55556a; font-size: 8.5pt; margin: 0 0 6px; }
  table.kv { width: 100%; border-collapse: collapse; }
  table.kv td { padding: 3px 0; vertical-align: top; }
  table.kv .k { width: 30%; color: #8e8ea0; }
  table.grid { width: 100%; border-collapse: collapse; font-size: 9pt; }
  table.grid th { text-align: left; color: #8e8ea0; font-weight: normal; border-bottom: 1px solid #e7e7ec; padding: 4px; }
  table.grid td { border-bottom: 1px solid #f0f0f4; padding: 4px; }
  table.grid .r { text-align: right; }
  table.grid tr.total td { font-weight: bold; border-top: 1px solid #d6d6de; }
  ol.terms { padding-left: 16px; margin: 0; }
  ol.terms li { margin-bottom: 4px; }
  table.signs { width: 100%; margin-top: 34px; }
  table.signs td { width: 50%; text-align: center; padding-top: 34px; font-size: 8.5pt; color: #55556a; }
  table.signs .line { border-top: 1px solid #d6d6de; margin: 0 20px; padding-top: 4px; }
  table.signs strong { color: #16161d; }
  .status { display: inline-block; font-size: 8pt; padding: 2px 6px; border-radius: 4px; }
  .signed { background: #e6f6ef; color: #0c9467; }
  .draft { background: #fdf3e2; color: #c47a0a; }
  .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7pt; color: #8e8ea0; text-align: center; }
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
      @if (!empty($institution['footer_line']))
        <div class="sub">{{ $institution['footer_line'] }}</div>
      @endif
    </td>
    <td class="doc">
      <div class="no">{{ $contract->contract_no }}</div>
      @if ($contract->signed_at)
        <span class="status signed">İmzalandı · {{ $contract->signed_at->format('d.m.Y') }}</span>
      @else
        <span class="status draft">Taslak — imzalanmadı</span>
      @endif
    </td>
  </tr>
</table>

{!! $contract->body_snapshot !!}

<div class="foot">{{ $institution['name'] }}@if (!empty($institution['footer_line'])) · {{ $institution['footer_line'] }}@endif · {{ $contract->contract_no }} · {{ $contract->signed_at ? 'İmza anındaki metnin değiştirilemez kopyasıdır' : 'Taslak' }}</div>
</body>
</html>

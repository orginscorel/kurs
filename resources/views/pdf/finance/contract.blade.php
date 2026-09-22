<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Sözleşme {{ $contract->contract_no }}</title>
<style>
  /* Resmî sözleşme düzeni: renksiz (siyah-beyaz), serif gövde, adil (justify) hizalama, ince siyah çizgiler. */
  @page { margin: 20mm 18mm 22mm; }
  * { font-family: "DejaVu Serif", serif; }
  body { font-size: 10pt; color: #111; line-height: 1.5; margin: 0; text-align: justify; }

  .brand { width: 100%; text-align: center; border-bottom: 1.5px solid #111; padding-bottom: 8px; margin-bottom: 2px; }
  .brand img { height: 40px; margin-bottom: 5px; }
  .name { font-size: 15pt; font-weight: bold; letter-spacing: .3px; }
  .sub { font-size: 8pt; color: #333; font-family: "DejaVu Sans", sans-serif; margin-top: 2px; }
  .docline { width: 100%; border-collapse: collapse; margin: 4px 0 6px; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #333; }
  .docline .no { font-weight: bold; color: #111; }
  .docline .st { text-align: right; }

  h1 { font-size: 13pt; margin: 14px 0 4px; text-align: center; font-weight: bold; letter-spacing: .4px; }
  h2 { font-size: 10.5pt; margin: 15px 0 6px; color: #111; border-bottom: 1px solid #111; padding-bottom: 3px; font-weight: bold; }
  .meta, p.intro { font-size: 9.5pt; margin: 0 0 8px; }
  p.intro { text-indent: 0; }

  table.kv { width: 100%; border-collapse: collapse; }
  table.kv td { padding: 3px 2px; vertical-align: top; }
  table.kv .k { width: 34%; color: #333; }

  table.grid { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
  table.grid th { text-align: left; color: #111; font-weight: bold; border-bottom: 1px solid #111; padding: 5px 4px; }
  table.grid td { border-bottom: 1px solid #cfcfcf; padding: 5px 4px; }
  table.grid .r { text-align: right; }
  table.grid tr.total td { font-weight: bold; border-top: 1.5px solid #111; border-bottom: none; }

  ol.terms { padding-left: 18px; margin: 0; text-align: justify; }
  ol.terms li { margin-bottom: 5px; }

  table.signs { width: 100%; margin-top: 44px; }
  table.signs td { width: 50%; text-align: center; padding: 0 14px; font-size: 9pt; color: #111; vertical-align: top; }
  table.signs .line { border-top: 1px solid #111; margin: 46px 14px 0; padding-top: 4px; }
  table.signs strong { color: #111; }

  .muted { color: #444; font-family: "DejaVu Sans", sans-serif; font-size: 8pt; }
  .status { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #111; }
  .foot { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7pt; color: #666; text-align: center; font-family: "DejaVu Sans", sans-serif; }
</style>
</head>
<body>
<div class="brand">
  @if ($institution['logo_data'])
    <img src="{{ $institution['logo_data'] }}"><br>
  @endif
  <span class="name">{{ $institution['name'] }}</span>
  <div class="sub">{{ collect([$institution['address'], $institution['phone'], $institution['email']])->filter()->join(' · ') }}</div>
  @if (!empty($institution['footer_line']))
    <div class="sub">{{ $institution['footer_line'] }}</div>
  @endif
</div>

<table class="docline">
  <tr>
    <td>Sözleşme No: <span class="no">{{ $contract->contract_no }}</span></td>
    <td class="st">
      @if ($contract->signed_at)
        <span class="status">İmza tarihi: {{ $contract->signed_at->format('d.m.Y') }}</span>
      @else
        <span class="status">TASLAK — imzalanmamıştır</span>
      @endif
    </td>
  </tr>
</table>

{!! $contract->body_snapshot !!}

<div class="foot">{{ $institution['name'] }}@if (!empty($institution['footer_line'])) · {{ $institution['footer_line'] }}@endif · Sözleşme No {{ $contract->contract_no }} · {{ $contract->signed_at ? 'İmza anındaki metnin değiştirilemez örneğidir.' : 'Taslak — imzalanmamıştır.' }}</div>
</body>
</html>

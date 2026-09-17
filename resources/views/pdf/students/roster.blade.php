<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@php($att = $mode === 'attendance')
<style>
  @page { margin: 12mm 10mm 14mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 8pt; color: #16161d; margin: 0; }
  .head { width: 100%; border-collapse: collapse; border-bottom: 2px solid #13233f; margin-bottom: 6px; }
  .head td { vertical-align: middle; padding: 0 0 5px; }
  .logo { width: 13mm; }
  .logo img { max-height: 12mm; max-width: 12mm; }
  .inst { font-size: 11.5pt; font-weight: bold; color: #13233f; }
  .sub { font-size: 7pt; color: #5b6275; }
  .doc { text-align: right; }
  .doc .t { font-size: 11pt; font-weight: bold; color: #13233f; letter-spacing: .3px; }
  .cls { width: 100%; border-collapse: collapse; margin: 8px 0 4px; }
  .cls td { background: #13233f; color: #fff; padding: 4px 7px; font-size: 9pt; font-weight: bold; }
  .cls td.r { text-align: right; font-weight: normal; font-size: 7.5pt; }
  .fill { width: 100%; border-collapse: collapse; margin-bottom: 4px; font-size: 7.5pt; color: #5b6275; }
  .fill td { padding: 2px 0; }
  .fill span { display: inline-block; border-bottom: 1px solid #9aa1b2; min-width: 42mm; }
  table.grid { width: 100%; border-collapse: collapse; }
  table.grid th { background: #e9edf5; color: #13233f; font-size: 7pt; font-weight: bold; text-align: left; padding: 4px 4px; border: 1px solid #c9cfdb; }
  table.grid td { border: 1px solid #d5dae4; padding: {{ $att ? '5px' : '3.5px' }} 4px; vertical-align: middle; }
  table.grid tr.z td { background: #f6f7fb; }
  .no { width: 7mm; text-align: center; color: #5b6275; }
  .sn { width: 17mm; font-family: "DejaVu Sans Mono", monospace; font-size: 7.5pt; }
  .nm { font-weight: bold; }
  .ph { white-space: nowrap; font-size: 7.5pt; }
  .gd { font-size: 7.5pt; }
  .rel { color: #5b6275; font-size: 6.5pt; }
  .box { width: {{ $att ? max(7, min(14, (int) floor(92 / max(1, count($columns))))) : 0 }}mm; text-align: center; }
  th.box { text-align: center; }
  .sig { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 7.5pt; color: #5b6275; }
  .sig td { width: 33%; padding-top: 18px; text-align: center; }
  .sig span { display: block; border-top: 1px solid #9aa1b2; margin: 0 8mm; padding-top: 2px; }
  .brk { page-break-after: always; }
  .foot { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 6.5pt; color: #8a90a2; }
  .foot .p:after { content: "Sayfa " counter(page); }
  .empty { padding: 20px; text-align: center; color: #8a90a2; }
</style>
</head>
<body>
<div class="foot"><table style="width:100%"><tr>
  <td>{{ $institution['name'] ?? '' }} · {{ $title }} · {{ $date }}</td>
  <td style="text-align:right"><span class="p"></span></td>
</tr></table></div>


@forelse($groups as $className => $list)
  @if($loop->first || $pagePerClass)
  <table class="head">
    <tr>
      @if(!empty($institution['logo_data']))<td class="logo"><img src="{{ $institution['logo_data'] }}" alt=""></td>@endif
      <td>
        <div class="inst">{{ $institution['name'] ?? 'Kurum' }}</div>
        <div class="sub">{{ $institution['address'] ?? '' }}{{ !empty($institution['phone']) ? ' · '.$institution['phone'] : '' }}</div>
      </td>
      <td class="doc">
        <div class="t">{{ mb_strtoupper($title, 'UTF-8') }}</div>
        <div class="sub">{{ $term ? $term.' · ' : '' }}{{ $date }}@if(!$pagePerClass) · {{ $total }} öğrenci · {{ count($groups) }} sınıf @endif</div>
      </td>
    </tr>
  </table>
  @endif

  <table class="cls"><tr>
    <td>{{ $className }}</td>
    <td class="r">{{ count($list) }} öğrenci</td>
  </tr></table>

  @if($att)
  <table class="fill"><tr>
    <td>Ders: <span>&nbsp;</span></td>
    <td>Öğretmen: <span>&nbsp;</span></td>
    <td>Tarih / hafta: <span>&nbsp;</span></td>
  </tr></table>
  @endif

  <table class="grid">
    <thead>
      <tr>
        <th class="no">#</th>
        <th class="sn">Öğr. No</th>
        <th>Adı Soyadı</th>
        <th>Telefon</th>
        <th>Veli Adı Soyadı</th>
        <th>Veli Telefonu</th>
        @foreach($columns as $c)<th class="box">{{ $c }}</th>@endforeach
      </tr>
    </thead>
    <tbody>
    @foreach($list->values() as $i => $r)
      <tr class="{{ $i % 2 ? 'z' : '' }}">
        <td class="no">{{ $i + 1 }}</td>
        <td class="sn">{{ $r['student_no'] }}</td>
        <td class="nm">{{ $r['full_name'] }}</td>
        <td class="ph">{{ $r['phone'] ?: '—' }}</td>
        <td class="gd">{{ $r['guardian_name'] ?: '—' }}@if($r['relationship']) <span class="rel">({{ $r['relationship'] }})</span>@endif</td>
        <td class="ph">{{ $r['guardian_phone'] ?: '—' }}</td>
        @foreach($columns as $c)<td class="box">&nbsp;</td>@endforeach
      </tr>
    @endforeach
    </tbody>
  </table>

  @if($att && ($pagePerClass || $loop->last))
  <table class="sig"><tr>
    <td><span>Ders öğretmeni</span></td>
    <td><span>Sınıf rehber öğretmeni</span></td>
    <td><span>Müdür yardımcısı</span></td>
  </tr></table>
  @endif

  @if($pagePerClass && !$loop->last)<div class="brk"></div>@endif
@empty
  <table class="head"><tr><td><div class="inst">{{ $institution['name'] ?? 'Kurum' }}</div></td><td class="doc"><div class="t">{{ mb_strtoupper($title, 'UTF-8') }}</div></td></tr></table>
  <div class="empty">Seçilen ölçütlere uyan öğrenci yok.</div>
@endforelse
</body>
</html>

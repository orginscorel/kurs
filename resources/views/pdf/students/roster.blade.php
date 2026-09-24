<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@php
  $att = $mode === 'attendance';
  $flat = collect($groups)->flatMap(fn ($list) => $list)->values();
  $perCol = $att ? 35 : 38;                 // A4 dikey, sütun başına satır (tek sayfaya tam sığsın; yan yana 2 blok tek tabloda)
  $perPage = $perCol * 2;
  $pages = $flat->isEmpty() ? collect([collect()]) : $flat->chunk($perPage)->values();
  $col = function ($slice) use ($perCol) { $a = $slice->values()->all(); while (count($a) < $perCol) { $a[] = null; } return $a; };
  $up = fn ($v) => $v === null || $v === '' ? '' : mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], (string) $v), 'UTF-8');
@endphp
<style>
  @page { margin: 8mm 8mm 10mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 8.5pt; color: #000; margin: 0; }
  .hd { width: 100%; border-collapse: collapse; margin-bottom: 2.5mm; }
  .hd td { vertical-align: bottom; }
  .hd .n { font-size: 11pt; font-weight: bold; }
  .hd .t { font-size: 10.5pt; font-weight: bold; }
  .hd .s { font-size: 7.5pt; }
  .datefld { font-size: 8pt; margin-bottom: 2mm; }
  .datefld span { display: inline-block; border-bottom: 1px solid #000; min-width: 34mm; }
  table.grid { width: 100%; border-collapse: collapse; }
  table.grid th, table.grid td { border: 0.6pt solid #000; padding: 1.3mm 1.6mm; font-size: 8.5pt; line-height: 1.02; }
  table.grid th { font-size: 7.5pt; font-weight: bold; text-align: center; }
  td.cg, th.cg { width: {{ $att ? '15mm' : '19mm' }}; font-size: 7pt; white-space: nowrap; }
  td.mk, th.mk { width: {{ $att ? '28mm' : '10mm' }}; }
  td.sp, th.sp { width: 4mm; border-top: none; border-bottom: none; border-left: none; border-right: none; padding: 0; }
  .brk { page-break-after: always; }
  .foot { position: fixed; bottom: -7mm; left: 0; right: 0; font-size: 6.5pt; text-align: center; }
  .foot .p:after { content: "Sayfa " counter(page); }
</style>
</head>
<body>
<div class="foot"><span class="p"></span></div>

@foreach($pages as $page)
  @php
    $left = $col($page->slice(0, $perCol));
    $right = $col($page->slice($perCol, $perCol));
  @endphp

  <table class="hd"><tr>
    <td>
      <div class="n">{{ $institution['name'] ?? 'Kurum' }}</div>
      <div class="s">{{ $term ? $term.' · ' : '' }}{{ $date }}@if($total) · {{ $total }} öğrenci @endif</div>
    </td>
    <td style="text-align:right"><div class="t">{{ $up($title) }}</div></td>
  </tr></table>

  @if($att)
    <div class="datefld">Tarih: <span>&nbsp;</span> &nbsp;&nbsp; Ders: <span>&nbsp;</span> &nbsp;&nbsp; Öğretmen: <span>&nbsp;</span></div>
  @endif

  <table class="grid">
    <thead><tr>
      <th class="cg">Grup</th><th>Ad</th><th>Soyad</th><th class="mk">{{ $att ? 'İşaret / Mazeret' : 'İşaret' }}</th>
      <th class="sp"></th>
      <th class="cg">Grup</th><th>Ad</th><th>Soyad</th><th class="mk">{{ $att ? 'İşaret / Mazeret' : 'İşaret' }}</th>
    </tr></thead>
    <tbody>
    @for($i = 0; $i < $perCol; $i++)
      @php($l = $left[$i])
      @php($r = $right[$i])
      <tr>
        <td class="cg">{{ $up($l['class_name'] ?? '') }}</td>
        <td>{{ $up($l['first_name'] ?? '') }}</td>
        <td>{{ $up($l['last_name'] ?? '') }}</td>
        <td class="mk">&nbsp;</td>
        <td class="sp"></td>
        <td class="cg">{{ $up($r['class_name'] ?? '') }}</td>
        <td>{{ $up($r['first_name'] ?? '') }}</td>
        <td>{{ $up($r['last_name'] ?? '') }}</td>
        <td class="mk">&nbsp;</td>
      </tr>
    @endfor
    </tbody>
  </table>

  @if(!$loop->last)<div class="brk"></div>@endif
@endforeach
</body>
</html>

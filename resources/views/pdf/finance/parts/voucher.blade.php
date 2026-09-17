@php
    $cur = $institution['currency_symbol'] ?? '₺';
    $m = fn ($v) => \App\Support\Money::format($v).' '.$cur;
@endphp
@if (!empty($v['voided']))
  <div class="note neg">Bu işlem {{ $v['voided'] }} tarihinde iptal edilmiştir.@if (!empty($v['void_reason'])) Gerekçe: {{ $v['void_reason'] }}@endif</div>
@endif
<table class="box">
  <tr>
    <td class="cell" style="width:50%"><div class="lbl">İşlem türü</div><div class="val">{{ $v['kind'] }}</div></td>
    <td class="cell"><div class="lbl">İşlem tarihi</div><div class="val">{{ $v['date'] }}</div></td>
  </tr>
  <tr>
    <td class="cell"><div class="lbl">{{ $v['from_label'] ?? 'Hesap' }}</div><div>{{ $v['from'] ?? '—' }}</div></td>
    <td class="cell"><div class="lbl">{{ $v['to_label'] ?? 'Karşı taraf' }}</div><div>{{ $v['to'] ?? '—' }}</div></td>
  </tr>
  @if (!empty($v['rows']))
    @foreach (array_chunk($v['rows'], 2, true) as $pair)
      <tr>@foreach ($pair as $label => $value)<td class="cell"><div class="lbl">{{ $label }}</div><div>{{ $value }}</div></td>@endforeach @if (count($pair) === 1)<td class="cell"></td>@endif</tr>
    @endforeach
  @endif
</table>
<table class="totals" style="width:60%;margin-left:40%">
  <tr class="grand"><td>{{ $v['direction_label'] }}</td><td class="r">{{ $m($v['amount']) }}</td></tr>
</table>
<div class="words"><strong>Yalnız:</strong> {{ $v['words'] }}</div>
@if (!empty($v['description']))<div class="note"><strong>Açıklama:</strong> {{ $v['description'] }}</div>@endif
@if (!empty($v['journal']))<div class="note small">Muhasebe fişi: {{ $v['journal'] }}</div>@endif
<table class="sign"><tr>
  <td><div class="line">Düzenleyen<br><strong class="navy">{{ $v['by'] ?? '—' }}</strong></div></td>
  <td><div class="line">{{ $v['counter_sign'] ?? 'Teslim eden / alan' }}<br><strong class="navy">{{ $v['counter_name'] ?? ' ' }}</strong></div></td>
</tr></table>

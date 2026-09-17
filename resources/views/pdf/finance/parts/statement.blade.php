@php
    $cur = $institution['currency_symbol'] ?? '₺';
    $m = fn ($v) => \App\Support\Money::format($v).' '.$cur;
@endphp
<table class="box"><tr>
  <td class="cell" style="width:55%"><div class="lbl">Hesap sahibi</div><div class="val">{{ $holder['name'] }}</div><div class="muted small">{{ $holder['sub'] }}</div>@if (!empty($holder['address']))<div class="small">{{ $holder['address'] }}</div>@endif</td>
  <td class="cell">
    <table width="100%">
      <tr><td class="lbl">Bakiye (borç)</td><td class="r"><strong class="{{ bccomp($s['totals']['balance'], '0', 2) > 0 ? 'neg' : 'pos' }}">{{ $m($s['totals']['balance']) }}</strong></td></tr>
      <tr><td class="lbl">Vadesi geçen</td><td class="r">{{ $m($s['totals']['overdue']) }}</td></tr>
      <tr><td class="lbl">Vadesi gelmemiş</td><td class="r">{{ $m($s['totals']['not_due']) }}</td></tr>
      @if (bccomp($s['totals']['credit_balance'], '0', 2) > 0)<tr><td class="lbl">Kullanılabilir avans</td><td class="r">{{ $m($s['totals']['credit_balance']) }}</td></tr>@endif
    </table>
  </td>
</tr></table>
<table class="grid">
  <thead><tr><th>Tarih</th>@if ($multi)<th>Öğrenci</th>@endif<th>Belge</th><th>Açıklama</th><th class="r">Borç</th><th class="r">Alacak</th><th class="r">Bakiye</th></tr></thead>
  <tbody>
    @if ($from)
      <tr><td>{{ $from->format('d.m.Y') }}</td>@if ($multi)<td></td>@endif<td></td><td><strong>Devreden bakiye</strong></td><td></td><td></td><td class="r"><strong>{{ $m($s['opening']) }}</strong></td></tr>
    @endif
    @foreach ($s['rows'] as $i => $r)
      <tr class="{{ $i % 2 ? 'alt' : '' }}">
        <td>{{ \Carbon\Carbon::parse($r['date'])->format('d.m.Y') }}</td>
        @if ($multi)<td>{{ $r['student'] }}</td>@endif
        <td>{{ $r['ref'] }}</td>
        <td>{{ $r['description'] }}@if ($r['type'] === 'installment' && $r['date'] > $today) <span class="muted small">(vadesi gelmedi)</span>@endif</td>
        <td class="r">{{ bccomp($r['debit'], '0', 2) ? $m($r['debit']) : '' }}</td>
        <td class="r">{{ bccomp($r['credit'], '0', 2) ? $m($r['credit']) : '' }}</td>
        <td class="r">{{ $m($r['balance']) }}</td>
      </tr>
    @endforeach
  </tbody>
  <tfoot><tr><td colspan="{{ $multi ? 4 : 3 }}">Toplam</td><td class="r">{{ $m($s['totals']['debit']) }}</td><td class="r">{{ $m($s['totals']['credit']) }}</td><td class="r">{{ $m($s['totals']['balance']) }}</td></tr></tfoot>
</table>
<div class="note small">Borç: ödeme planı taksitleri (vade tarihinde) ve iadeler. Alacak: geçerli tahsilatlar. İptal edilen işlemler ekstreye yansımaz. Bakiye vadesi gelmemiş taksitleri de içerir.</div>

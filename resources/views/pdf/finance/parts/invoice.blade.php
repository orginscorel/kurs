@php
    $cur = $institution['currency_symbol'] ?? '₺';
    $m = fn ($v) => \App\Support\Money::format($v).' '.$cur;
@endphp
<table class="box">
  <tr>
    <td class="cell" style="width:58%">
      <div class="lbl">Sayın</div>
      <div class="val">{{ $invoice->buyer_name }}</div>
      @if ($invoice->buyer_address)<div>{{ $invoice->buyer_address }}</div>@endif
      <div class="muted small">
        {{ strlen((string) $taxId) === 10 ? 'VKN' : 'TCKN' }}: {{ $taxId ?: '—' }}@if ($invoice->buyer_tax_office) · V.D.: {{ $invoice->buyer_tax_office }}@endif
        @if ($invoice->buyer_phone) · Tel: {{ $invoice->buyer_phone }}@endif
        @if ($invoice->buyer_email) · {{ $invoice->buyer_email }}@endif
      </div>
    </td>
    <td class="cell">
      <table width="100%">
        <tr><td class="lbl">Fatura no</td><td class="r"><strong>{{ $invoice->invoice_no ?? '—' }}</strong></td></tr>
        <tr><td class="lbl">Tarih</td><td class="r">{{ $invoice->issue_date->format('d.m.Y') }}</td></tr>
        <tr><td class="lbl">Senaryo / tip</td><td class="r">{{ \App\Models\Invoice::DOCUMENT_TYPES[$invoice->document_type] ?? '' }} · {{ $invoice->kind === 'return' ? 'İADE' : 'SATIŞ' }}</td></tr>
        @if ($invoice->ettn)<tr><td class="lbl">ETTN</td><td class="r small">{{ $invoice->ettn }}</td></tr>@endif
        @if ($invoice->related)<tr><td class="lbl">İlgili fatura</td><td class="r">{{ $invoice->related->invoice_no }} ({{ $invoice->related->issue_date->format('d.m.Y') }})</td></tr>@endif
        @if ($studentLine)<tr><td class="lbl">Öğrenci</td><td class="r">{{ $studentLine }}</td></tr>@endif
      </table>
    </td>
  </tr>
</table>

<table class="grid" style="table-layout:fixed">
  <thead><tr>
    <th style="width:4%">#</th><th style="width:{{ $hasWithholding ? 29 : 37 }}%">Mal / hizmet</th><th class="r" style="width:8%">Miktar</th><th class="r" style="width:11%">Birim fiyat</th><th class="r" style="width:8%">İskonto</th><th class="r" style="width:9%">KDV</th>
    @if ($hasWithholding)<th class="r" style="width:8%">Tevkifat</th>@endif
    <th class="r" style="width:11%">Matrah</th><th class="r" style="width:12%">Tutar</th>
  </tr></thead>
  <tbody>
  @foreach ($invoice->lines as $i => $l)
    <tr class="{{ $i % 2 ? 'alt' : '' }}">
      <td>{{ $l->sequence }}</td>
      <td>{{ $l->description }}</td>
      <td class="r">{{ rtrim(rtrim(number_format((float) $l->quantity, 3, ',', '.'), '0'), ',') }} {{ $l->unit }}</td>
      <td class="r">{{ $m($l->unit_price) }}</td>
      <td class="r">{{ bccomp((string) $l->discount_amount, '0', 2) > 0 ? $m($l->discount_amount) : '—' }}</td>
      <td class="r">%{{ rtrim(rtrim((string) $l->vat_rate, '0'), '.') }}<br><span class="muted small">{{ $m($l->vat_amount) }}</span></td>
      @if ($hasWithholding)<td class="r">{{ $l->withholding_tenths ? $l->withholding_tenths.'/10' : '—' }}<br><span class="muted small">{{ $m($l->withholding_amount) }}</span></td>@endif
      <td class="r">{{ $m($l->net_amount) }}</td>
      <td class="r"><strong>{{ $m($l->total_amount) }}</strong></td>
    </tr>
  @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td>Mal / hizmet toplamı</td><td class="r">{{ $m($invoice->gross_total) }}</td></tr>
  @if (bccomp((string) $invoice->discount_total, '0', 2) > 0)<tr><td>Toplam iskonto</td><td class="r">− {{ $m($invoice->discount_total) }}</td></tr>@endif
  <tr><td>KDV matrahı</td><td class="r">{{ $m($invoice->net_total) }}</td></tr>
  @foreach ($breakdown as $b)
    <tr><td>Hesaplanan KDV (%{{ rtrim(rtrim($b['rate'], '0'), '.') }})</td><td class="r">{{ $m($b['vat']) }}</td></tr>
  @endforeach
  <tr><td>Vergiler dahil toplam</td><td class="r">{{ $m($invoice->grand_total) }}</td></tr>
  @if ($hasWithholding)<tr><td>Tevkif edilen KDV</td><td class="r">− {{ $m($invoice->withholding_total) }}</td></tr>@endif
  <tr class="grand"><td>ÖDENECEK TUTAR</td><td class="r">{{ $m($invoice->payable_total) }}</td></tr>
</table>

<div class="words"><strong>Yalnız:</strong> {{ $amountWords }}</div>

@if ($payments->isNotEmpty())
  <div class="note"><strong>Bu faturaya mahsup edilen tahsilatlar:</strong>
    {{ $payments->map(fn ($p) => $p->receipt_no.' ('.$p->paid_at->format('d.m.Y').', '.$m($p->pivot->amount).')')->join(' · ') }}</div>
@endif
@if ($invoice->notes)<div class="note">{{ $invoice->notes }}</div>@endif
@if ($invoice->status === 'cancelled')
  <div class="note neg">Bu fatura {{ $invoice->cancelled_at?->format('d.m.Y H:i') }} tarihinde iptal edilmiştir. Gerekçe: {{ $invoice->cancel_reason }}</div>
@endif
<div class="note small">{{ $integratorNote }}</div>

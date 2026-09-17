@php
    $cur = $institution['currency_symbol'] ?? '₺';
@endphp
<table class="box">
  <tr>
    <td class="cell" style="width:50%"><div class="lbl">Öğrenci</div><div class="val">{{ $refund->student?->full_name }}</div><div class="muted small">No {{ $refund->student?->student_no }}</div></td>
    <td class="cell"><div class="lbl">İade alan</div><div class="val">{{ $refund->payee_name ?: '—' }}</div></td>
  </tr>
  <tr>
    <td class="cell"><div class="lbl">İlgili tahsilat</div><div>{{ $refund->payment?->receipt_no }} · {{ $refund->payment?->paid_at?->format('d.m.Y') }} · {{ \App\Support\Money::format($refund->payment?->amount) }} {{ $cur }}</div></td>
    <td class="cell"><div class="lbl">Yöntem / hesap</div><div>{{ \App\Models\Payment::METHODS[$refund->method] ?? $refund->method }} · {{ $refund->account?->name }}@if ($refund->reference) · Ref: {{ $refund->reference }}@endif</div></td>
  </tr>
</table>
<table class="totals" style="width:60%;margin-left:40%">
  <tr><td>Avanstan (fazla ödeme) iade</td><td class="r">{{ \App\Support\Money::format($refund->from_credit) }} {{ $cur }}</td></tr>
  <tr><td>Taksitlerden geri alınan</td><td class="r">{{ \App\Support\Money::format($refund->from_installments) }} {{ $cur }}</td></tr>
  <tr class="grand"><td>İADE EDİLEN TUTAR</td><td class="r">{{ \App\Support\Money::format($refund->amount) }} {{ $cur }}</td></tr>
</table>
<div class="words"><strong>Yalnız:</strong> {{ $amountWords }}</div>
@if ($refund->allocations->isNotEmpty())
  <table class="grid">
    <thead><tr><th>Taksit</th><th>Vade</th><th class="r">Geri alınan</th></tr></thead>
    <tbody>
    @foreach ($refund->allocations as $a)
      <tr><td>{{ $a->installment?->sequence }}. taksit</td><td>{{ $a->installment?->due_date?->format('d.m.Y') }}</td><td class="r">{{ \App\Support\Money::format($a->amount) }} {{ $cur }}</td></tr>
    @endforeach
    </tbody>
  </table>
  <div class="note small">Taksitlerden geri alınan tutar kadar öğrencinin borcu yeniden açılmıştır.</div>
@endif
<div class="note"><strong>Gerekçe:</strong> {{ $refund->reason }}</div>
@if ($refund->voided_at)<div class="note neg">Bu iade {{ $refund->voided_at->format('d.m.Y H:i') }} tarihinde iptal edilmiştir. Gerekçe: {{ $refund->void_reason }}</div>@endif
<table class="sign"><tr>
  <td><div class="line">İadeyi yapan<br><strong class="navy">{{ $refund->creator?->name ?? '—' }}</strong></div></td>
  <td><div class="line">Teslim alan<br><strong class="navy">{{ $refund->payee_name ?: ' ' }}</strong></div></td>
</tr></table>

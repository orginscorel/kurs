@php
    $cur = $institution['currency_symbol'] ?? '₺';
    $m = fn ($v) => \App\Support\Money::format($v).' '.$cur;
@endphp
@if ($payment->voided_at)
  <div class="note neg">Bu makbuz {{ $payment->voided_at->format('d.m.Y H:i') }} tarihinde {{ $voider ?? 'yetkili' }} tarafından iptal edilmiştir. Gerekçe: {{ $payment->void_reason }}</div>
@endif
<table class="box">
  <tr>
    <td class="cell" style="width:55%"><div class="lbl">Öğrenci</div><div class="val">{{ $payment->student?->full_name }}</div><div class="muted small">No {{ $payment->student?->student_no }}</div></td>
    <td class="cell"><div class="lbl">Ödeyen</div><div class="val">{{ $payer }}</div></td>
  </tr>
  <tr>
    <td class="cell"><div class="lbl">Kayıt</div><div>{{ $payment->enrollment ? $payment->enrollment->enrollment_no.' · '.$payment->enrollment->program?->name.' · '.$payment->enrollment->term?->name : '—' }}</div></td>
    <td class="cell"><div class="lbl">Ödeme yöntemi / hesap</div><div>{{ $methodLabel }} · {{ $payment->account?->name }}@if ($payment->reference) · Ref: {{ $payment->reference }}@endif</div></td>
  </tr>
</table>
<table class="totals" style="width:60%;margin-left:40%">
  <tr class="grand"><td>TAHSİL EDİLEN</td><td class="r">{{ $m($payment->amount) }}</td></tr>
</table>
<div class="words"><strong>Yalnız:</strong> {{ $amountWords }}</div>
@if ($payment->allocations->isNotEmpty())
  <table class="grid">
    <thead><tr><th>Taksit</th><th>Vade</th><th class="r">Taksit tutarı</th><th class="r">Bu ödemeyle</th></tr></thead>
    <tbody>
      @foreach ($payment->allocations->sortBy(fn ($a) => $a->installment?->due_date) as $i => $a)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">
          <td>{{ $a->installment?->sequence }}. taksit</td>
          <td>{{ $a->installment?->due_date?->format('d.m.Y') }}</td>
          <td class="r">{{ $m($a->installment?->amount) }}</td>
          <td class="r"><strong>{{ $m($a->amount) }}</strong></td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif
@if ($payment->note)<div class="note">Not: {{ $payment->note }}</div>@endif
<table class="sign"><tr>
  <td><div class="line">Tahsil eden<br><strong class="navy">{{ $payment->receiver?->name ?? '—' }}</strong></div></td>
  <td><div class="line">Ödeyen<br><strong class="navy">{{ $payer }}</strong></div></td>
</tr></table>

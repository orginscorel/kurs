<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Makbuz {{ $payment->receipt_no }}</title>
<style>
  @page { margin: 14mm 12mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 9.5pt; color: #16161d; margin: 0; }
  .brand { width: 100%; border-bottom: 2px solid #4b3fe3; padding-bottom: 8px; margin-bottom: 12px; }
  .brand td { vertical-align: middle; }
  .name { font-size: 14pt; font-weight: bold; color: #16161d; letter-spacing: -0.2px; }
  .sub { font-size: 8pt; color: #55556a; }
  .doc { text-align: right; }
  .doc .title { font-size: 11pt; font-weight: bold; color: #4b3fe3; text-transform: uppercase; letter-spacing: 1px; }
  .doc .no { font-size: 9pt; color: #16161d; margin-top: 2px; }
  table.info { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  table.info td { padding: 4px 0; vertical-align: top; }
  table.info .label { color: #8e8ea0; width: 32%; font-size: 8.5pt; }
  .amount-box { border: 1px solid #e7e7ec; background: #f6f6f8; border-radius: 6px; padding: 10px 12px; margin: 8px 0 12px; }
  .amount { font-size: 18pt; font-weight: bold; letter-spacing: -0.3px; }
  .words { font-size: 8.5pt; color: #55556a; margin-top: 2px; }
  table.alloc { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
  table.alloc th { text-align: left; color: #8e8ea0; font-weight: normal; border-bottom: 1px solid #e7e7ec; padding: 4px 2px; }
  table.alloc td { border-bottom: 1px solid #f0f0f4; padding: 4px 2px; }
  .right { text-align: right; }
  .sign { width: 100%; margin-top: 26px; }
  .sign td { width: 50%; text-align: center; font-size: 8.5pt; color: #55556a; padding-top: 28px; }
  .sign .line { border-top: 1px solid #d6d6de; margin: 0 18px; padding-top: 4px; }
  .foot { position: fixed; bottom: -6mm; left: 0; right: 0; font-size: 7pt; color: #8e8ea0; text-align: center; }
  .void { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-size: 46pt; color: #d6344b; opacity: 0.18; transform: rotate(-24deg); font-weight: bold; }
  .void-note { border: 1px solid #d6344b; color: #d6344b; padding: 6px 8px; border-radius: 4px; font-size: 8.5pt; margin-bottom: 8px; }
</style>
</head>
<body>
@if ($payment->voided_at)
  <div class="void">İPTAL EDİLDİ</div>
@endif

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
      <div class="title">Tahsilat Makbuzu</div>
      <div class="no">{{ $payment->receipt_no }}</div>
      <div class="sub">{{ $payment->paid_at->format('d.m.Y H:i') }}</div>
    </td>
  </tr>
</table>

@if ($payment->voided_at)
  <div class="void-note">Bu makbuz {{ $payment->voided_at->format('d.m.Y H:i') }} tarihinde {{ $voider ?? 'yetkili' }} tarafından iptal edilmiştir. Gerekçe: {{ $payment->void_reason }}</div>
@endif

<table class="info">
  <tr><td class="label">Öğrenci</td><td><strong>{{ $payment->student?->full_name }}</strong> <span class="sub">No {{ $payment->student?->student_no }}</span></td></tr>
  <tr><td class="label">Ödeyen</td><td>{{ $payer }}</td></tr>
  @if ($payment->enrollment)
    <tr><td class="label">Kayıt</td><td>{{ $payment->enrollment->enrollment_no }} · {{ $payment->enrollment->program?->name }} · {{ $payment->enrollment->term?->name }}</td></tr>
  @endif
  <tr><td class="label">Ödeme yöntemi</td><td>{{ $methodLabel }} · {{ $payment->account?->name }}@if ($payment->reference) · Ref: {{ $payment->reference }}@endif</td></tr>
</table>

<div class="amount-box">
  <div class="sub">Tahsil edilen tutar</div>
  <div class="amount">{{ $amountText }} {{ $institution['currency_symbol'] ?? '₺' }}</div>
  <div class="words">Yalnız: {{ $amountWords }}</div>
</div>

@if ($payment->allocations->isNotEmpty())
  <table class="alloc">
    <thead><tr><th>Taksit</th><th>Vade</th><th class="right">Taksit tutarı</th><th class="right">Bu ödemeyle</th></tr></thead>
    <tbody>
      @foreach ($payment->allocations->sortBy(fn ($a) => $a->installment?->due_date) as $a)
        <tr>
          <td>{{ $a->installment?->sequence }}. taksit</td>
          <td>{{ $a->installment?->due_date?->format('d.m.Y') }}</td>
          <td class="right">{{ \App\Support\Money::format($a->installment?->amount) }} {{ $institution['currency_symbol'] ?? '₺' }}</td>
          <td class="right"><strong>{{ \App\Support\Money::format($a->amount) }} {{ $institution['currency_symbol'] ?? '₺' }}</strong></td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

@if ($payment->note)
  <p class="sub" style="margin-top: 8px;">Not: {{ $payment->note }}</p>
@endif

<table class="sign">
  <tr>
    <td><div class="line">Tahsil eden<br><strong style="color:#16161d">{{ $payment->receiver?->name ?? '—' }}</strong></div></td>
    <td><div class="line">Ödeyen<br><strong style="color:#16161d">{{ $payer }}</strong></div></td>
  </tr>
</table>

<div class="foot">{{ $institution['name'] }}@if (!empty($institution['footer_line'])) · {{ $institution['footer_line'] }}@endif · Bu belge {{ $institution['generated_at'] ?? now()->format('d.m.Y H:i') }} tarihinde elektronik olarak üretilmiştir · {{ $payment->receipt_no }}</div>
</body>
</html>

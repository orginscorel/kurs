<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Finans raporu {{ $r['from'] }} – {{ $r['to'] }}</title>
@php($m = fn ($v) => \App\Support\Money::format($v))
<style>
  @page { margin: 14mm 12mm 16mm; }
  * { font-family: "DejaVu Sans", sans-serif; }
  body { font-size: 8.5pt; color: #16161d; margin: 0; }
  .brand { width: 100%; border-bottom: 2px solid #4b3fe3; padding-bottom: 6px; margin-bottom: 12px; }
  .name { font-size: 13pt; font-weight: bold; }
  .sub { font-size: 7.5pt; color: #55556a; }
  .doc { text-align: right; }
  .doc .title { font-size: 11pt; font-weight: bold; color: #4b3fe3; }
  h2 { font-size: 9.5pt; margin: 14px 0 6px; color: #3a2fc2; }
  table.kpi { width: 100%; border-collapse: separate; border-spacing: 4px; margin: 0 -4px; }
  table.kpi td { border: 1px solid #e7e7ec; border-radius: 4px; padding: 6px 8px; width: 25%; }
  table.kpi .l { font-size: 7.5pt; color: #8e8ea0; }
  table.kpi .v { font-size: 11.5pt; font-weight: bold; margin-top: 2px; }
  table.grid { width: 100%; border-collapse: collapse; }
  table.grid th { text-align: right; color: #8e8ea0; font-weight: normal; border-bottom: 1px solid #d6d6de; padding: 4px 3px; font-size: 7.5pt; }
  table.grid th:first-child, table.grid td:first-child { text-align: left; }
  table.grid td { text-align: right; border-bottom: 1px solid #f0f0f4; padding: 3px; }
  table.grid tr.total td { font-weight: bold; border-top: 1px solid #d6d6de; border-bottom: none; }
  .neg { color: #d6344b; }
  .two { width: 100%; }
  .two > tbody > tr > td { width: 50%; vertical-align: top; padding-right: 8px; }
  .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; color: #8e8ea0; text-align: center; }
</style>
</head>
<body>
<table class="brand">
  <tr>
    <td><div class="name">{{ $institution['name'] }}</div><div class="sub">{{ $institution['address'] }}</div></td>
    <td class="doc">
      <div class="title">Finans Raporu</div>
      <div class="sub">{{ \Carbon\Carbon::parse($r['from'])->format('d.m.Y') }} – {{ \Carbon\Carbon::parse($r['to'])->format('d.m.Y') }} · {{ $groupLabel }}</div>
    </td>
  </tr>
</table>

<table class="kpi">
  <tr>
    <td><div class="l">Öğrenci tahsilatı</div><div class="v">{{ $m($r['totals']['collections']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div><div class="sub">{{ $r['totals']['payment_count'] }} tahsilat</div></td>
    <td><div class="l">Toplam gelir</div><div class="v">{{ $m($r['totals']['income']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div><div class="sub">diğer gelir {{ $m($r['totals']['other_income']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div></td>
    <td><div class="l">Gider</div><div class="v">{{ $m($r['totals']['expense']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div></td>
    <td><div class="l">Net</div><div class="v {{ bccomp($r['totals']['net'], '0', 2) < 0 ? 'neg' : '' }}">{{ $m($r['totals']['net']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div></td>
  </tr>
  <tr>
    <td><div class="l">Tahsilat oranı</div><div class="v">{{ $r['totals']['collection_rate'] === null ? '—' : '%'.number_format($r['totals']['collection_rate'], 1, ',', '.') }}</div><div class="sub">dönemde vadesi gelenlerin ödenen payı</div></td>
    <td><div class="l">Toplam alacak</div><div class="v">{{ $m($r['receivables']['total']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div></td>
    <td><div class="l">Gecikmiş alacak</div><div class="v neg">{{ $m($r['receivables']['overdue']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div></td>
    <td><div class="l">Beklenen (7 / 30 gün)</div><div class="v">{{ $m($r['receivables']['expected_next_7']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div><div class="sub">30 gün: {{ $m($r['receivables']['expected_next_30']) }} {{ $institution['currency_symbol'] ?? '₺' }}</div></td>
  </tr>
</table>

<h2>Dönem dökümü</h2>
<table class="grid">
  <thead><tr><th>Dönem</th><th>Tahsilat</th><th>Diğer gelir</th><th>Gider</th><th>Net</th><th>Vadesi gelen</th><th>Oran</th></tr></thead>
  <tbody>
    @foreach ($r['rows'] as $row)
      @continue(bccomp($row['income'], '0', 2) === 0 && bccomp($row['expense'], '0', 2) === 0 && bccomp($row['due'], '0', 2) === 0)
      <tr>
        <td>{{ $row['label'] }}</td><td>{{ $m($row['collections']) }}</td><td>{{ $m($row['other_income']) }}</td><td>{{ $m($row['expense']) }}</td>
        <td class="{{ bccomp($row['net'], '0', 2) < 0 ? 'neg' : '' }}">{{ $m($row['net']) }}</td><td>{{ $m($row['due']) }}</td>
        <td>{{ $row['collection_rate'] === null ? '—' : '%'.number_format($row['collection_rate'], 1, ',', '.') }}</td>
      </tr>
    @endforeach
    <tr class="total">
      <td>Toplam</td><td>{{ $m($r['totals']['collections']) }}</td><td>{{ $m($r['totals']['other_income']) }}</td><td>{{ $m($r['totals']['expense']) }}</td>
      <td>{{ $m($r['totals']['net']) }}</td><td>{{ $m($r['totals']['due']) }}</td>
      <td>{{ $r['totals']['collection_rate'] === null ? '—' : '%'.number_format($r['totals']['collection_rate'], 1, ',', '.') }}</td>
    </tr>
  </tbody>
</table>

<table class="two">
  <tr>
    <td>
      <h2>Tahsilat yöntemleri</h2>
      <table class="grid">
        <thead><tr><th>Yöntem</th><th>Adet</th><th>Tutar</th></tr></thead>
        <tbody>
          @forelse ($r['by_method'] as $row)
            <tr><td>{{ $row['label'] }}</td><td>{{ $row['count'] }}</td><td>{{ $m($row['amount']) }}</td></tr>
          @empty
            <tr><td colspan="3">Tahsilat yok</td></tr>
          @endforelse
        </tbody>
      </table>
      <h2>Alacak yaşlandırma</h2>
      <table class="grid">
        <thead><tr><th>Kova</th><th>Taksit</th><th>Tutar</th></tr></thead>
        <tbody>
          @foreach ($r['receivables']['aging'] as $b)
            <tr><td>{{ $b['label'] }}</td><td>{{ $b['count'] }}</td><td>{{ $m($b['amount']) }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </td>
    <td>
      <h2>Gelir kalemleri</h2>
      <table class="grid">
        <thead><tr><th>Kategori</th><th>Tutar</th></tr></thead>
        <tbody>
          @foreach ($r['income_by_category'] as $c)
            <tr><td>{{ $c['name'] }}</td><td>{{ $m($c['amount']) }}</td></tr>
          @endforeach
        </tbody>
      </table>
      <h2>Gider kalemleri</h2>
      <table class="grid">
        <thead><tr><th>Kategori</th><th>Tutar</th></tr></thead>
        <tbody>
          @forelse ($r['expense_by_category'] as $c)
            <tr><td>{{ $c['name'] }}</td><td>{{ $m($c['amount']) }}</td></tr>
          @empty
            <tr><td colspan="2">Gider yok</td></tr>
          @endforelse
        </tbody>
      </table>
      @if ($r['voided_payments']['count'] > 0)
        <p class="sub" style="margin-top:8px">Dönemde {{ $r['voided_payments']['count'] }} tahsilat ({{ $m($r['voided_payments']['amount']) }} {{ $institution['currency_symbol'] ?? '₺' }}) iptal edildi; toplamlara dahil değildir.</p>
      @endif
    </td>
  </tr>
</table>

<div class="foot">{{ $institution['name'] }}@if (!empty($institution['footer_line'])) · {{ $institution['footer_line'] }}@endif · Rapor {{ $institution['generated_at'] ?? now()->format('d.m.Y H:i') }} tarihinde üretildi · İptal edilmiş kayıtlar toplamlara dahil değildir</div>
</body>
</html>

@php
    $cur = $institution['currency_symbol'] ?? '₺';
    $fmt = function ($v, $type) use ($cur) {
        if ($v === null || $v === '') {
            return '—';
        }
        return match ($type) {
            'money' => \App\Support\Money::format($v).' '.$cur,
            'percent' => '%'.str_replace('.', ',', (string) $v),
            'number' => is_numeric($v) ? str_replace('.', ',', (string) $v) : $v,
            default => $v,
        };
    };
@endphp
<p class="muted" style="margin:8px 0 0">{{ $r['description'] ?? '' }}</p>
@if (!empty($r['kpis']))
  <table class="kpis"><tr>
    @foreach ($r['kpis'] as $k)
      <td><div class="k">{{ $k['label'] }}</div><div class="v {{ ($k['tone'] ?? null) === 'danger' ? 'neg' : '' }}">{{ $fmt($k['value'], $k['type']) }}</div></td>
    @endforeach
  </tr></table>
@endif
@foreach ($r['tables'] as $t)
  <h3 class="sec">{{ $t['title'] }}</h3>
  <table class="grid" style="font-size: {{ count($t['columns']) > 7 ? '7.2pt' : '8.2pt' }}">
    <thead><tr>@foreach ($t['columns'] as $c)<th class="{{ $c['type'] === 'text' ? '' : 'r' }}">{{ $c['label'] }}</th>@endforeach</tr></thead>
    <tbody>
      @forelse ($t['rows'] as $i => $row)
        <tr class="{{ $i % 2 ? 'alt' : '' }}">@foreach ($t['columns'] as $c)
          <td class="{{ $c['type'] === 'text' ? '' : 'r' }} {{ $c['type'] === 'money' && is_string($row[$c['key']] ?? null) && str_starts_with($row[$c['key']], '-') ? 'neg' : '' }}">{{ $fmt($row[$c['key']] ?? null, $c['type']) }}</td>
        @endforeach</tr>
      @empty
        <tr><td colspan="{{ count($t['columns']) }}" class="muted c">Bu aralıkta kayıt yok.</td></tr>
      @endforelse
    </tbody>
    @if (!empty($t['totals']))
      <tfoot><tr>@foreach ($t['columns'] as $c)<td class="{{ $c['type'] === 'text' ? '' : 'r' }}">{{ array_key_exists($c['key'], $t['totals']) ? $fmt($t['totals'][$c['key']], $c['type']) : '' }}</td>@endforeach</tr></tfoot>
    @endif
  </table>
@endforeach
@if (!empty($r['notes']))
  <div class="note">@foreach ($r['notes'] as $n)• {{ $n }}<br>@endforeach</div>
@endif

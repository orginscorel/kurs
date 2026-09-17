{{-- Kurumsal lacivert belge başlığı. Değişkenler: $institution, $h = [title, no, meta] --}}
<table class="head">
  <tr>
    <td>
      @if (!empty($institution['logo_data']))
        <table><tr><td class="logo" style="padding:4px"><img src="{{ $institution['logo_data'] }}" style="height: 30px;"></td><td style="padding-left:10px">
      @endif
      <div class="inst">{{ $institution['name'] }}</div>
      <div class="isub">{{ collect([$institution['address'] ?? null, $institution['phone'] ?? null, $institution['email'] ?? null])->filter()->join(' · ') }}</div>
      @if (!empty($institution['tax_office']) || !empty($institution['tax_number']))
        <div class="isub">Vergi dairesi: {{ $institution['tax_office'] ?? '—' }} · VKN: {{ $institution['tax_number'] ?? '—' }}</div>
      @endif
      @if (!empty($institution['logo_data']))
        </td></tr></table>
      @endif
    </td>
    <td class="doc">
      <div class="dtitle">{{ $h['title'] }}</div>
      <div class="dno">{{ $h['no'] ?? '' }}</div>
      <div class="isub">{{ $h['meta'] ?? '' }}</div>
    </td>
  </tr>
</table>
<div class="strip"></div>

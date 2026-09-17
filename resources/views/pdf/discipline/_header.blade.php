@php
  $kisaltma = collect(preg_split('/\s+/u', trim($institution['name'] ?? '')))->filter()->take(3)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
@endphp
<table class="ust">
  <tr>
    <td style="width: 18mm;">
      @if(!empty($institution['logo_data']))
        <img class="logo" src="{{ $institution['logo_data'] }}" alt="">
      @else
        <table class="amblem"><tr><td>{{ $kisaltma }}</td></tr></table>
      @endif
    </td>
    <td style="padding-left: 3mm;">
      <div class="kurum">{{ $institution['name'] ?? '' }}</div>
      <div class="belge">{{ $docLabel }}</div>
    </td>
    <td style="width: 58mm;">
      <table class="nokutu">
        <tr><td class="baslik" colspan="2">{{ $boxTitle }}</td></tr>
        @foreach($boxRows as $k => $v)
          <tr><td class="e">{{ $k }}</td><td class="b r">{{ $v }}</td></tr>
        @endforeach
      </table>
    </td>
  </tr>
</table>
<div class="cizgi"></div>

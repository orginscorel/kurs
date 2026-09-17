{{--
  Kurumsal finans belgesi (tek ya da toplu). Değişkenler:
  $institution, $title, $docs = [['part' => 'invoice', 'data' => [...], 'header' => [title,no,meta], 'stamp' => ['text' => 'İPTAL', 'tone' => 'red'|'gray'|null]], ...]
  Her belge yeni sayfada başlar.
--}}
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
@include('pdf.finance._styles')
</head>
<body>
<table class="foot" width="100%"><tr>
  <td>{{ $institution['name'] }}@if (!empty($institution['footer_line'])) · {{ $institution['footer_line'] }}@endif · {{ $institution['generated_at'] ?? now()->format('d.m.Y H:i') }} tarihinde üretildi · {{ $title }}</td>
  <td class="r"><span class="pg"></span></td>
</tr></table>
@foreach ($docs as $i => $d)
  <div class="doc {{ $i < count($docs) - 1 ? 'break' : '' }}">
    @if (!empty($d['stamp']))<div class="stamp {{ $d['stamp']['tone'] ?? 'red' }}">{{ $d['stamp']['text'] }}</div>@endif
    @include('pdf.finance._header', ['h' => $d['header']])
    @include('pdf.finance.parts.'.$d['part'], $d['data'] + ['institution' => $institution])
  </div>
@endforeach
</body>
</html>

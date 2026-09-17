{{-- Senet (bono) basımı. perPage: 1 = A5 yatay, 2/3 = A4 dikey (kesim çizgili). Metin hukukçu/muhasebeci teyidine tabidir. --}}
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Senetler</title>
@include('pdf.finance._styles')
<style>
  @page { margin: {{ $perPage === 1 ? '9mm 10mm' : '9mm 11mm' }}; }
  .note-wrap { height: {{ $perPage === 1 ? 'auto' : ($perPage === 2 ? '128mm' : '86mm') }}; overflow: hidden; }
  .note-card .stampx { position: absolute; top: 34%; left: 0; right: 0; text-align: center; font-size: 30pt; font-weight: bold; opacity: 0.16; transform: rotate(-14deg); color: #b42335; }
  .note-card .stampx.copy { color: #13233f; }
  .note-card .body { font-size: {{ $perPage === 3 ? '7.9pt' : '8.8pt' }}; }
</style>
</head>
<body>
@foreach ($items->chunk($perPage) as $pi => $page)
  <div style="{{ $pi < ceil($items->count() / $perPage) - 1 ? 'page-break-after: always;' : '' }}">
  @foreach ($page as $k => $it)
    @php($n = $it['n'])
    <div class="note-wrap">
    <div class="note-card">
      @if ($it['stamp'])<div class="stampx {{ $it['stamp'] === 'SURETİDİR' ? 'copy' : '' }}">{{ $it['stamp'] }}</div>@endif
      <table width="100%"><tr>
        <td style="vertical-align: middle">
          <span class="ttl">SENET (BONO)</span><br>
          <span class="muted small">Emre muharrer senet · {{ $institution['name'] }}</span>
        </td>
        <td class="r" style="vertical-align: middle">
          <table style="margin-left:auto"><tr>
            <td class="small muted" style="padding-right:6px">Ödeme günü (vade)</td><td class="amountbox">{{ $n->due_date->format('d.m.Y') }}</td>
            <td class="small muted" style="padding:0 6px 0 12px">Türk Lirası</td><td class="amountbox">{{ $it['amount'] }} ₺</td>
          </tr></table>
          <div class="small" style="margin-top:3px">Seri/Sıra No: <strong>{{ $n->note_no }}</strong></div>
        </td>
      </tr></table>

      <div class="body">
        İşbu bono (emre muharrer senet) mukabilinde <strong>{{ $n->due_date->format('d.m.Y') }}</strong> tarihinde
        Sayın <strong>{{ $n->payee_name }}</strong> veya emrühavalesine yukarıda yazılı
        <strong>yalnız {{ $it['words'] }}</strong> kayıtsız şartsız ödeyeceğim.
        Bedeli {{ $s['consideration'] }} alınmıştır.
        @if ($s['acceleration'])
          İşbu bono vadesinde ödenmediği takdirde, aynı borç ilişkisi için düzenlenmiş ve vadesi gelmemiş diğer bonoların da muaccel olacağını;
        @endif
        uyuşmazlık halinde <strong>{{ $s['court'] }}</strong> mahkemeleri ve icra dairelerinin yetkili olduğunu kabul ederim.
      </div>

      <table width="100%" class="grid2 small"><tr>
        <td style="width:50%">Ödeme yeri: <strong>{{ $n->payment_place ?: '....................................' }}</strong></td>
        <td>Düzenleme yeri ve tarihi: <strong>{{ $n->issue_place ?: '....................' }}, {{ $n->issue_date->format('d.m.Y') }}</strong></td>
      </tr></table>

      <table width="100%" class="sig" style="margin-top:4px"><tr>
        <td style="width:50%">
          <strong class="navy">BORÇLU (DÜZENLEYEN)</strong><br>
          Adı soyadı: {{ $n->debtor_name ?: '........................................................' }}<br>
          T.C. kimlik no: {{ $it['tax_id'] ?: '..........................................' }}<br>
          Adres: {{ $n->debtor_address ?: '................................................................................' }}<br>
          <span class="muted">İmza:</span><br><br>
        </td>
        <td>
          <strong class="navy">KEFİL (varsa)</strong><br>
          Adı soyadı: ........................................................<br>
          T.C. kimlik no: ..........................................<br>
          Adres: ................................................................................<br>
          <span class="muted">İmza:</span><br><br>
        </td>
      </tr></table>
      <div class="small muted" style="margin-top:3px">
        {{ $n->student?->full_name }} (no {{ $n->student?->student_no }}) · Kayıt {{ $n->enrollment?->enrollment_no }}{{ $n->enrollment?->program ? ' · '.$n->enrollment->program->name : '' }} · Taksit {{ $it['seq'] }}
      </div>
    </div>
    </div>
    @if ($perPage > 1 && ! $loop->last)<div class="note-cut"></div>@endif
  @endforeach
  </div>
@endforeach
</body>
</html>

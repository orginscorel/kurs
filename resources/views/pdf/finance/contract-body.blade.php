{{-- Sözleşme metni. İmza anında HTML olarak body_snapshot'a kopyalanır; bu şablonun sonraki değişiklikleri imzalı sözleşmeleri etkilemez. --}}
<div class="contract">
  <h1>Eğitim Hizmeti Kayıt Sözleşmesi</h1>
  <p class="meta">Kayıt no: <strong>{{ $enrollment->enrollment_no }}</strong> · Düzenlenme: {{ $signedAt ? $signedAt->format('d.m.Y') : ($institution['generated_date'] ?? now()->format('d.m.Y')) }}</p>

  <h2>1. Taraflar</h2>
  <table class="kv">
    <tr><td class="k">Kurum</td><td>{{ $institution['name'] }}{{ $institution['address'] ? ' — '.$institution['address'] : '' }}</td></tr>
    <tr><td class="k">Öğrenci</td><td>{{ $student?->full_name }} (No {{ $student?->student_no }}){{ $student?->school_name ? ' · '.$student->school_name : '' }}</td></tr>
    <tr><td class="k">Veli / ödeme sorumlusu</td><td>{{ $guardian ? trim($guardian->first_name.' '.$guardian->last_name) : '—' }}{{ $guardian?->phone ? ' · '.$guardian->phone : '' }}</td></tr>
  </table>

  <h2>2. Eğitim hizmeti</h2>
  <table class="kv">
    <tr><td class="k">Program</td><td>{{ $enrollment->program?->name }}</td></tr>
    <tr><td class="k">Dönem</td><td>{{ $enrollment->term?->name }}{{ $enrollment->term ? ' ('.$enrollment->term->starts_on?->format('d.m.Y').' – '.$enrollment->term->ends_on?->format('d.m.Y').')' : '' }}</td></tr>
    @if ($enrollment->package)
      <tr><td class="k">Eğitim paketi</td><td>{{ $enrollment->package->name }}{{ $enrollment->package->includes ? ' — '.$enrollment->package->includes : '' }}</td></tr>
    @endif
    @if ($enrollment->classGroup)
      <tr><td class="k">Sınıf</td><td>{{ $enrollment->classGroup->name }}</td></tr>
    @endif
    <tr><td class="k">Kayıt tarihi</td><td>{{ $enrollment->enrolled_on?->format('d.m.Y') }}</td></tr>
  </table>

  <h2>3. Ücret</h2>
  <table class="kv">
    <tr><td class="k">Liste fiyatı</td><td>{{ $money($enrollment->list_price) }} {{ $institution['currency_symbol'] ?? '₺' }}</td></tr>
    @if ((float) $enrollment->discount_amount > 0)
      <tr><td class="k">İndirim</td><td>−{{ $money($enrollment->discount_amount) }} {{ $institution['currency_symbol'] ?? '₺' }}{{ $enrollment->discount_reason ? ' ('.$enrollment->discount_reason.')' : '' }}</td></tr>
    @endif
    @if ((float) $enrollment->scholarship_amount > 0)
      <tr><td class="k">Burs</td><td>−{{ $money($enrollment->scholarship_amount) }} {{ $institution['currency_symbol'] ?? '₺' }}{{ $enrollment->scholarship_reason ? ' ('.$enrollment->scholarship_reason.')' : '' }}</td></tr>
    @endif
    <tr><td class="k"><strong>Net eğitim bedeli</strong></td><td><strong>{{ $money($enrollment->net_price) }} {{ $institution['currency_symbol'] ?? '₺' }}</strong> <span class="muted">({{ $netWords }})</span></td></tr>
  </table>

  <h2>4. Ödeme planı</h2>
  @if ($installments->isEmpty())
    <p>Bu kayıt için ödeme planı bulunmamaktadır.</p>
  @else
    <table class="grid">
      <thead><tr><th>#</th><th>Vade</th><th class="r">Tutar</th></tr></thead>
      <tbody>
        @foreach ($installments as $i)
          <tr><td>{{ $i->sequence }}</td><td>{{ $i->due_date->format('d.m.Y') }}</td><td class="r">{{ $money($i->amount) }} {{ $institution['currency_symbol'] ?? '₺' }}</td></tr>
        @endforeach
        <tr class="total"><td colspan="2">Toplam</td><td class="r">{{ $money($installments->reduce(fn ($s, $i) => bcadd($s, (string) $i->amount, 2), '0.00')) }} {{ $institution['currency_symbol'] ?? '₺' }}</td></tr>
      </tbody>
    </table>
  @endif

  <h2>5. Genel hükümler</h2>
  <ol class="terms">
    <li>Kurum, yukarıda belirtilen program kapsamında eğitim hizmetini dönem boyunca sunmayı taahhüt eder.</li>
    <li>Veli / ödeme sorumlusu, ödeme planındaki taksitleri vade tarihlerinde ödemeyi kabul eder. Yapılan her ödeme için numaralı tahsilat makbuzu düzenlenir.</li>
    <li>Ödeme planında yapılacak değişiklikler (vade, tutar, indirim veya burs) kurum kayıtlarına işlenir ve yazılı olarak bildirilir; toplam net bedel taraflarca aksi kararlaştırılmadıkça değişmez.</li>
    <li>Kayıt dondurma, ayrılma ve ücret iadesi talepleri kurum yönetimine yazılı olarak yapılır ve yürürlükteki mevzuat çerçevesinde değerlendirilir.</li>
    <li>Öğrenci ve veliye ait kişisel veriler 6698 sayılı KVKK kapsamında, yalnızca eğitim hizmetinin yürütülmesi amacıyla işlenir.</li>
  </ol>

  <table class="signs">
    <tr>
      <td>
        <div class="line">Kurum yetkilisi<br><strong>{{ $institution['name'] }}</strong></div>
      </td>
      <td>
        <div class="line">Veli / ödeme sorumlusu<br><strong>{{ $signedBy ?? ($guardian ? trim($guardian->first_name.' '.$guardian->last_name) : '') }}</strong>
          @if ($signedAt)<br><span class="muted">İmza tarihi: {{ $signedAt->format('d.m.Y H:i') }}</span>@endif
        </div>
      </td>
    </tr>
  </table>
</div>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Savunma istem yazısı — {{ $student->full_name }}</title>
@include('pdf.discipline._style')
</head>
<body>
@include('pdf.discipline._footer')
@include('pdf.discipline._header', ['docLabel' => 'DİSİPLİN İŞLEMLERİ', 'boxTitle' => 'SAVUNMA İSTEMİ', 'boxRows' => [
  'Olay no' => $incident->incident_no, 'Tarih' => $defense->requested_at->format('d.m.Y'), 'Son gün' => $defense->due_on->format('d.m.Y'),
]])

<h1>SAVUNMA İSTEM YAZISI</h1>

<table class="kimlik">
  <tr><td class="e">ÖĞRENCİ</td><td class="v">{{ $student->full_name }}</td><td class="e">ÖĞRENCİ NO</td><td class="v">{{ $student->student_no }}</td></tr>
  <tr><td class="e">SINIF</td><td class="v">{{ $className ?: '—' }}</td><td class="e">OLAY TARİHİ</td><td class="v">{{ $incident->occurred_at->format('d.m.Y H:i') }}</td></tr>
  <tr><td class="e">YER</td><td class="v">{{ $incident->location ?: '—' }}</td><td class="e">DERS / ÖĞRETMEN</td><td class="v">{{ trim(($incident->subject?->name ?? '').' '.($incident->teacher?->full_name ? '· '.$incident->teacher->full_name : '')) ?: '—' }}</td></tr>
  <tr><td class="e">DAVRANIŞ</td><td class="v" colspan="3">{{ $behaviors ?: '—' }}</td></tr>
</table>

<p class="metin" style="margin-top: 5mm;">
  Sayın <b>{{ $student->full_name }}</b>,<br><br>
  {{ $incident->occurred_at->format('d.m.Y') }} tarihinde yaşanan ve yukarıda özeti verilen olayla ilgili olarak kurumumuz disiplin
  işlemleri başlatılmıştır. Olayla ilgili yazılı savunmanızı en geç <b>{{ $defense->due_on->format('d.m.Y') }}</b> tarihine kadar
  kurum müdürlüğüne teslim etmeniz ya da öğrenci portalındaki "Disiplin" bölümünden yazmanız gerekmektedir.
  Belirtilen tarihe kadar savunma vermemeniz hâlinde savunma hakkınızdan vazgeçmiş sayılacağınızı ve karar
  dosyadaki bilgilere göre verileceğini bilgilerinize sunarız.
</p>

@if($incident->description)
<h2>OLAYIN ÖZETİ</h2>
<div class="kutu metin">{{ $incident->description }}</div>
@endif

@if($defense->request_note)
<h2>AÇIKLAMA</h2>
<div class="kutu metin">{{ $defense->request_note }}</div>
@endif

<h2>ÖĞRENCİ SAVUNMASI</h2>
<div class="kutu" style="min-height: 50mm;">@if($defense->statement)<span class="metin">{{ $defense->statement }}</span>@endif</div>

<table class="imza">
  <tr>
    <td><div class="cizgi-imza">Tebliğ eden<br>{{ $requester ?: '' }}</div></td>
    <td><div class="cizgi-imza">Tebliğ alan öğrenci<br>{{ $student->full_name }}</div></td>
    <td><div class="cizgi-imza">Veli<br>{{ $guardianName ?: '' }}</div></td>
  </tr>
</table>
</body>
</html>

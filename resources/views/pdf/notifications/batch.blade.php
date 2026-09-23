<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Bildirim önizleme — {{ $eventLabel }}</title>
<style>
  /* Resmî önizleme: renksiz, serif gövde, ince siyah çizgiler. text-transform:uppercase KULLANMA (Türkçe İ bozulur). */
  @page { margin: 18mm 16mm 20mm; }
  * { font-family: "DejaVu Serif", serif; }
  body { font-size: 10pt; color: #111; line-height: 1.45; margin: 0; }
  .brand { width: 100%; text-align: center; border-bottom: 1.5px solid #111; padding-bottom: 8px; margin-bottom: 6px; }
  .name { font-size: 14pt; font-weight: bold; }
  .sub { font-size: 8pt; color: #333; font-family: "DejaVu Sans", sans-serif; margin-top: 2px; }
  h1 { font-size: 13pt; margin: 10px 0 2px; text-align: center; font-weight: bold; }
  .meta { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #333; text-align: center; margin-bottom: 10px; }
  h2 { font-size: 11pt; margin: 16px 0 6px; border-bottom: 1px solid #111; padding-bottom: 3px; font-weight: bold; }
  .msg { border: 1px solid #cfcfcf; padding: 7px 9px; margin: 0 0 7px; }
  .msg .to { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #333; margin-bottom: 3px; }
  .msg .body { font-size: 9.5pt; white-space: pre-wrap; }
  .empty { color: #666; font-size: 9pt; font-style: italic; }
  .foot { margin-top: 16px; font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #555; border-top: 1px solid #cfcfcf; padding-top: 6px; }
</style>
</head>
<body>
  <div class="brand">
    <div class="name">Erbaa Bilgi Eğitim</div>
    <div class="sub">Bildirim gönderim önizlemesi</div>
  </div>

  <h1>{{ $eventLabel }}</h1>
  <div class="meta">
    {{ $batch->title }} · Toplam {{ $batch->total }} mesaj · Durum: {{ \App\Models\NotificationBatch::STATUSES[$batch->status] ?? $batch->status }} · {{ $batch->created_at?->format('d.m.Y H:i') }}
  </div>

  @foreach ($grouped as $audience => $messages)
    <h2>{{ $audienceLabels[$audience] ?? $audience }} ({{ $messages->count() }})</h2>
    @forelse ($messages as $m)
      <div class="msg">
        <div class="to">Alıcı: {{ $m->to ?: '—' }}</div>
        <div class="body">{{ $m->body }}</div>
      </div>
    @empty
      <div class="empty">Bu kitle için mesaj yok.</div>
    @endforelse
  @endforeach

  <div class="foot">
    Bu belge yalnızca önizlemedir. Mesajlar ancak yönetici onayından sonra gönderilir.
    Bağlı bir WhatsApp entegrasyonu yoksa mesajlar simülasyon olarak işaretlenir ve gerçek gönderim yapılmaz.
  </div>
</body>
</html>

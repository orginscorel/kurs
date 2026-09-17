<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Abonelik — {{ $institution }}</title>
<style>
  :root { --bg:#eef0f2; --surface:#fff; --ink:#1d2433; --ink2:#4b5567; --line:#dfe3ec; --primary:#1f3b63; --success:#1f7a4d; --danger:#b42318; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; background:var(--bg); color:var(--ink);
         font-family: Inter, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
  main { width:100%; max-width:440px; background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:28px 24px; }
  .brand { font-size:13px; font-weight:600; color:var(--primary); letter-spacing:.01em; margin-bottom:14px; }
  h1 { font-size:20px; line-height:1.35; margin:0 0 8px; }
  p { font-size:14.5px; line-height:1.6; color:var(--ink2); margin:0 0 14px; }
  .addr { font-weight:600; color:var(--ink); }
  button { width:100%; height:44px; border:0; border-radius:6px; background:var(--primary); color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { filter:brightness(1.08); }
  .ok { color:var(--success); } .err { color:var(--danger); }
  small { display:block; margin-top:14px; font-size:12.5px; color:var(--ink2); }
</style>
</head>
<body>
<main>
  <div class="brand">{{ $institution }}</div>
  @if ($state === 'invalid')
    <h1 class="err">Bağlantı geçersiz</h1>
    <p>Bu abonelik bağlantısı geçersiz ya da eksik kopyalanmış. Lütfen e-postadaki bağlantıyı yeniden açın ya da kurumla iletişime geçin.</p>
  @elseif ($state === 'done')
    <h1 class="ok">Aboneliğiniz sonlandırıldı</h1>
    <p><span class="addr">{{ $address }}</span> adresine {{ $institution }} tarafından toplu {{ $channel === 'sms' ? 'SMS' : 'e-posta' }} gönderilmeyecek.</p>
    <small>Öğrenci kaydınızla ilgili zorunlu bilgilendirmeler (ör. ödeme veya yoklama bildirimi) bu tercihten etkilenmeyebilir.</small>
  @else
    <h1>Toplu iletileri almak istemiyor musunuz?</h1>
    <p><span class="addr">{{ $address }}</span> adresini {{ $institution }} duyuru ve kampanya listesinden çıkarmak için aşağıdaki düğmeye basın.</p>
    <form method="post" action="{{ $action }}">
      <button type="submit">Abonelikten çık</button>
    </form>
  @endif
</main>
</body>
</html>

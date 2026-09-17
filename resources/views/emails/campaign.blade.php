<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $subject }}</title>
<style>
  @media only screen and (max-width: 620px) {
    .container { width: 100% !important; }
    .px { padding-left: 20px !important; padding-right: 20px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#eef0f2;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2433;-webkit-text-size-adjust:100%">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0f2">
  <tr>
    <td align="center" style="padding:24px 12px">
      <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #dfe3ec;border-radius:8px;overflow:hidden">
        <tr>
          <td class="px" style="background:#1f3b63;padding:18px 32px">
            @if ($institution['logo'])
              <img src="{{ $institution['logo'] }}" alt="{{ $institution['name'] }}" height="40" style="display:block;height:40px;max-width:220px;border:0">
            @else
              <span style="display:block;color:#ffffff;font-size:18px;font-weight:600;letter-spacing:-0.01em">{{ $institution['name'] }}</span>
            @endif
          </td>
        </tr>
        <tr>
          <td class="px" style="padding:28px 32px 8px 32px">
            <h1 style="margin:0 0 16px 0;font-size:20px;line-height:1.35;font-weight:600;color:#1d2433">{{ $subject }}</h1>
            @foreach ($paragraphs as $p)
              <p style="margin:0 0 14px 0;font-size:15px;line-height:1.65;color:#2f3a4d">{!! $p !!}</p>
            @endforeach
          </td>
        </tr>
        <tr>
          <td class="px" style="padding:8px 32px 24px 32px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #dfe3ec">
              <tr>
                <td style="padding-top:14px;font-size:12.5px;line-height:1.6;color:#5b6577">
                  <strong style="color:#1d2433">{{ $institution['name'] }}</strong>
                  @if ($institution['address'])<br>{{ $institution['address'] }}@endif
                  @if ($institution['phone'])<br>Tel: {{ $institution['phone'] }}@endif
                  @if ($institution['email'])<br>{{ $institution['email'] }}@endif
                  @if ($institution['website'])<br>{{ $institution['website'] }}@endif
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
      @if ($unsubscribeUrl)
      <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px">
        <tr>
          <td class="px" style="padding:14px 32px;font-size:12px;line-height:1.6;color:#6b7385;text-align:center">
            Bu e-postayı {{ $institution['name'] }} ile paylaştığınız iletişim bilgisi nedeniyle aldınız.
            {{ $commercial ? 'Ticari elektronik ileti almak istemiyorsanız' : 'Bu tür toplu e-postaları almak istemiyorsanız' }}
            <a href="{{ $unsubscribeUrl }}" style="color:#1f3b63;text-decoration:underline">abonelikten çıkabilirsiniz</a>.
          </td>
        </tr>
      </table>
      @endif
    </td>
  </tr>
</table>
</body>
</html>

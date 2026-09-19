<?php

namespace App\Services\Devices\Drivers\Yt33;

/**
 * YT33 ile ilgili YALNIZ doğrulanmış/kullanıcı tarafından bilinen değerler. Paket alanları (başlık, komut
 * kimlikleri, uzunluk alanı) BURAYA doğrulanmış yakalama olmadan yazılmaz.
 */
final class Types
{
    /** Cihaz menüsündeki "Machine ID / Device ID" fabrika değeri; kullanıcı değiştirebilir (protokol sabiti DEĞİL). */
    public const DEFAULT_MACHINE_ID = 1;

    /** İletişim şifresi fabrika değeri (0 = kapalı). Cihazın web arayüzü (admin) şifresi DEĞİLDİR. */
    public const DEFAULT_COMM_KEY = '0';

    /** Cihazın PC bağlantı portu (kullanıcının cihazında gözlendi: TCP 5005 açık). */
    public const DEFAULT_PULL_PORT = 5005;

    /** Cihazın push hedef portu (cihaz web arayüzünde gözlendi: 7005). */
    public const DEFAULT_PUSH_PORT = 7005;

    public const CONNECTION_PULL = 'pull';   // "LAN / TCP Pull": köprü cihaza bağlanır

    public const CONNECTION_PUSH = 'push';   // "Server / Push": cihaz köprüye bağlanır

    public const WAITING = 'Protokol verisi bekleniyor';
}

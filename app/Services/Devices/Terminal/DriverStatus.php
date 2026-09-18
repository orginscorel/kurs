<?php

namespace App\Services\Devices\Terminal;

/**
 * Sürücü çağrısının tiplenmiş sonucu. Protokolü bilinmeyen/yazılmamış metotlar SAHTE VERİ DÖNDÜRMEZ;
 * `ProtocolNotImplemented` ya da `Unsupported` döner ve ekran bunu olduğu gibi söyler.
 */
enum DriverStatus: string
{
    case Ok = 'ok';
    /** Soket hiç açılmadı (ağ, güvenlik duvarı, macOS Yerel Ağ izni). */
    case NetworkError = 'network_error';
    /** Soket açıldı ama cihaz beklenen protokolle yanıt vermedi / bozuk yanıt verdi. */
    case ProtocolError = 'protocol_error';
    /** Cihaz iletişim şifresini reddetti. */
    case AuthError = 'auth_error';
    /** Bu sürücü için protokol henüz doğrulanmadı / yazılmadı (bilinçli olarak tahmin edilmez). */
    case ProtocolNotImplemented = 'protocol_not_implemented';
    /** Bu sürücü bu işlemi hiç desteklemez (ör. genel TCP sürücüsünde kullanıcı listesi). */
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Başarılı',
            self::NetworkError => 'Ağ hatası',
            self::ProtocolError => 'Protokol yanıt vermedi',
            self::AuthError => 'İletişim şifresi reddedildi',
            self::ProtocolNotImplemented => 'Protokol henüz doğrulanmadı',
            self::Unsupported => 'Desteklenmiyor',
        };
    }
}

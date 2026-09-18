<?php

namespace App\Services\Devices\Network;

/**
 * Soket düzeyi hata sınıflandırması — işletim sisteminin errno değerini TÜRKÇE, sürücüden bağımsız
 * bir nedene çevirir. Hiçbir metin belirli bir markaya/porta (ör. ZKTeco 4370) atıf yapmaz; hedef
 * adres ve port her zaman çağırandan gelir.
 *
 * errno değerleri işletim sistemine göre değişir (macOS/BSD ve Linux farklıdır); ikisi de tanınır,
 * ayrıca hata metni de denetlenir (PHP bazı durumlarda errno'yu 0 bırakır).
 */
enum SocketFailure: string
{
    case Refused = 'refused';
    case Timeout = 'timeout';
    case HostUnreachable = 'host_unreachable';
    case NetworkUnreachable = 'network_unreachable';
    case PermissionDenied = 'permission_denied';
    case Dns = 'dns';
    case Unknown = 'unknown';

    /** errno + hata metninden sınıf. */
    public static function classify(int $errno, string $errstr): self
    {
        $text = strtolower($errstr);

        return match (true) {
            in_array($errno, [61, 111], true) || str_contains($text, 'refused') => self::Refused,
            in_array($errno, [60, 110], true) || str_contains($text, 'timed out') || str_contains($text, 'timeout') => self::Timeout,
            in_array($errno, [65, 113, 64, 112], true) || str_contains($text, 'no route to host') || str_contains($text, 'host is down') => self::HostUnreachable,
            in_array($errno, [51, 101], true) || str_contains($text, 'network is unreachable') => self::NetworkUnreachable,
            in_array($errno, [1, 13], true) || str_contains($text, 'not permitted') || str_contains($text, 'permission denied') => self::PermissionDenied,
            str_contains($text, 'getaddrinfo') || str_contains($text, 'name or service') || str_contains($text, 'nodename') => self::Dns,
            default => self::Unknown,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Refused => 'TCP bağlantısı reddedildi',
            self::Timeout => 'TCP bağlantısı zaman aşımına uğradı',
            self::HostUnreachable => 'Cihaza ağ yolu yok (No route to host)',
            self::NetworkUnreachable => 'Ağ ulaşılamaz',
            self::PermissionDenied => 'İşletim sistemi bağlantıya izin vermedi',
            self::Dns => 'Adres çözümlenemedi',
            self::Unknown => 'Bağlantı kurulamadı',
        };
    }

    /**
     * Kullanıcıya ne yapacağı. $macLocalNetwork: bu makine macOS ve hedef özel (LAN) adres → Yerel Ağ
     * gizlilik izni olası neden olarak eklenir.
     */
    public function hint(string $target, int $port, bool $macLocalNetwork): string
    {
        $mac = 'macOS "Yerel Ağ" izni gerekli olabilir: Sistem Ayarları › Gizlilik ve Güvenlik › Yerel Ağ bölümünde "Erbaa Kurs" açık olmalı. '
            .'Terminal\'de nc ile bağlantı başarılı olup uygulamada olmuyorsa neden budur (Terminal uygulamasının izni ayrıdır). '
            .'İzni açtıktan sonra uygulamayı kapatıp yeniden açın.';

        $base = match ($this) {
            self::Refused => "Cihaz {$target} adresinde yanıt veriyor ama {$port} portunda bağlantı kabul etmiyor. Port numarasını cihaz menüsünden doğrulayın; cihaz aynı anda tek bağlantı kabul ediyorsa kendi programını kapatın.",
            self::Timeout => "{$target} adresinden süre içinde yanıt gelmedi. Cihaz açık ve aynı ağda mı? IP adresi değişmiş olabilir (DHCP kapalı, sabit IP önerilir).",
            self::HostUnreachable => "Bu bilgisayar {$target} adresine yol bulamadı. Cihaz kapalı, farklı bir ağda ya da IP adresi değişmiş olabilir.",
            self::NetworkUnreachable => 'Bu bilgisayarın bu ağa yolu yok. Wi-Fi/kablo bağlantısını ve cihazla aynı ağda (misafir ağı değil) olduğunuzu kontrol edin.',
            self::PermissionDenied => 'İşletim sistemi ya da güvenlik yazılımı bağlantıyı engelledi.',
            self::Dns => 'IP adresini sayı olarak girin (ör. 192.168.1.50).',
            self::Unknown => "{$target}:{$port} adresine bağlanılamadı. Cihazın açık ve aynı ağda olduğunu kontrol edin.",
        };

        $macRelevant = $macLocalNetwork && in_array($this, [self::HostUnreachable, self::NetworkUnreachable, self::PermissionDenied, self::Timeout, self::Unknown], true);

        return $macRelevant ? $base.' '.$mac : $base;
    }

    /** macOS Yerel Ağ izninin olası neden olup olmadığı (ekrandaki ayrı rozet için). */
    public function mayBeLocalNetworkPermission(bool $isMacAndPrivate): bool
    {
        return $isMacAndPrivate && in_array($this, [self::HostUnreachable, self::NetworkUnreachable, self::PermissionDenied, self::Timeout, self::Unknown], true);
    }
}

<?php

namespace App\Services\Devices\Discovery;

/**
 * BU MAKİNE HANGİ YEREL AĞDA? — tarama için aday adresleri çıkarır.
 *
 * Kurumun terminali yerel ağdadır (192.168.x.x gibi). Web sunucusunun böyle bir ağı YOKTUR
 * (tek genel IP, /32). Bu sınıf bunu ayırt eder; tarama ekranı sunucuda açıldığında kullanıcıya
 * "bu bilgisayar kurumun ağında değil" diye açıkça söyleyebilsin diye.
 *
 * Dış ağa hiç çıkılmaz: yalnız işletim sisteminin arayüz listesi okunur.
 */
final class NetworkProbe
{
    /** Yalnız bu özel ağ blokları taranır; genel IP'ler ASLA taranmaz (internet taraması yapmayız). */
    private const PRIVATE_BLOCKS = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['169.254.0.0', 16],   // APIPA: cihaz DHCP alamamışsa buraya düşer
    ];

    /**
     * Bu makinenin yerel ağ arayüzleri.
     *
     * @return list<array{arayuz:string, ip:string, maske_bit:int, agi:string, taranabilir:bool, adres_sayisi:int}>
     */
    public function interfaces(): array
    {
        $found = [];

        foreach ($this->rawAddresses() as [$name, $ip, $bits]) {
            if ($ip === '127.0.0.1' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }

            // /24'ten geniş maskeyi /24'e daraltırız: 65 bin adres taramak anlamsızdır.
            $scanBits = max($bits, 24);
            $private = $this->isPrivate($ip);
            $count = $bits >= 31 ? 0 : (2 ** (32 - $scanBits)) - 2;

            $found[] = [
                'arayuz' => $name,
                'ip' => $ip,
                'maske_bit' => $bits,
                'agi' => $this->networkLabel($ip, $scanBits),
                // /31 ve /32 tek adrestir → taranacak komşu yok (web sunucusu tipik olarak böyledir)
                'taranabilir' => $private && $bits < 31,
                'adres_sayisi' => $private && $bits < 31 ? $count : 0,
            ];
        }

        return $found;
    }

    /** Taranabilir ağ var mı? Yoksa ekran "bu bilgisayar kurumun yerel ağında değil" der. */
    public function hasScannableNetwork(): bool
    {
        foreach ($this->interfaces() as $iface) {
            if ($iface['taranabilir']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bir ağ etiketinden ("192.168.1.0/24") taranacak adresleri üretir.
     * Ağ ve yayın adresi atlanır. En çok $max adres döner (kazara /16 taraması olmaz).
     *
     * @return list<string>
     */
    public function hostsOf(string $cidr, int $max = 1024): array
    {
        [$base, $bits] = array_pad(explode('/', $cidr, 2), 2, '24');
        $bits = (int) $bits;

        if (! filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 16 || $bits > 30) {
            return [];
        }

        if (! $this->isPrivate($base)) {
            return [];   // genel IP bloğu taranmaz
        }

        $start = ip2long($base) & (0xFFFFFFFF << (32 - $bits));
        $size = 2 ** (32 - $bits);
        $hosts = [];

        for ($i = 1; $i < $size - 1 && count($hosts) < $max; $i++) {
            $hosts[] = long2ip($start + $i);
        }

        return $hosts;
    }

    /** Varsayılan olarak taranacak ağlar (taranabilir arayüzlerin /24'leri, yinelenenler atılır). */
    public function defaultNetworks(): array
    {
        $nets = [];

        foreach ($this->interfaces() as $iface) {
            if ($iface['taranabilir'] && ! in_array($iface['agi'], $nets, true)) {
                $nets[] = $iface['agi'];
            }
        }

        return $nets;
    }

    /** Bir ağın yayın (broadcast) adresi — UDP keşif paketi buraya gider. */
    public function broadcastOf(string $cidr): ?string
    {
        [$base, $bits] = array_pad(explode('/', $cidr, 2), 2, '24');
        $bits = (int) $bits;

        if (! filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 16 || $bits > 30) {
            return null;
        }

        $mask = 0xFFFFFFFF << (32 - $bits);

        return long2ip((ip2long($base) & $mask) | (~$mask & 0xFFFFFFFF));
    }

    public function isPrivate(string $ip): bool
    {
        $value = ip2long($ip);

        if ($value === false) {
            return false;
        }

        foreach (self::PRIVATE_BLOCKS as [$block, $bits]) {
            $mask = 0xFFFFFFFF << (32 - $bits);
            if ((($value & $mask) & 0xFFFFFFFF) === ((ip2long($block) & $mask) & 0xFFFFFFFF)) {
                return true;
            }
        }

        return false;
    }

    private function networkLabel(string $ip, int $bits): string
    {
        $mask = 0xFFFFFFFF << (32 - $bits);

        return long2ip(ip2long($ip) & $mask).'/'.$bits;
    }

    /**
     * İşletim sisteminden IPv4 arayüzleri. Önce PHP'nin kendi yolu (net_get_interfaces),
     * yoksa `ip -4 -o addr` / `ifconfig` çıktısı ayrıştırılır (macOS'ta ifconfig vardır).
     *
     * @return list<array{0:string, 1:string, 2:int}> [arayüz, ip, maske biti]
     */
    private function rawAddresses(): array
    {
        $rows = [];

        if (function_exists('net_get_interfaces') && is_array($list = @net_get_interfaces())) {
            foreach ($list as $name => $iface) {
                foreach ($iface['unicast'] ?? [] as $unicast) {
                    if (($unicast['family'] ?? null) === AF_INET && isset($unicast['address'])) {
                        $rows[] = [(string) $name, (string) $unicast['address'], $this->bitsFromMask($unicast['netmask'] ?? '255.255.255.0')];
                    }
                }
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        return $this->parseCommandOutput();
    }

    /** @return list<array{0:string, 1:string, 2:int}> */
    private function parseCommandOutput(): array
    {
        $rows = [];
        $output = $this->run('ip -4 -o addr show 2>/dev/null') ?: $this->run('ifconfig 2>/dev/null');

        if ($output === null) {
            return $rows;
        }

        // Linux: "2: eth0    inet 192.168.1.20/24 brd …"
        if (preg_match_all('/^\s*\d+:\s*(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/m', $output, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $rows[] = [$row[1], $row[2], (int) $row[3]];
            }
        }

        // macOS/BSD: "inet 192.168.1.20 netmask 0xffffff00 broadcast …"
        if ($rows === [] && preg_match_all('/inet (\d+\.\d+\.\d+\.\d+) netmask (0x[0-9a-fA-F]{8}|\d+\.\d+\.\d+\.\d+)/', $output, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $mask = str_starts_with($row[2], '0x') ? long2ip((int) hexdec($row[2])) : $row[2];
                $rows[] = ['en0', $row[1], $this->bitsFromMask($mask)];
            }
        }

        return $rows;
    }

    private function run(string $command): ?string
    {
        if (! function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }

        $output = @shell_exec($command);

        return is_string($output) && trim($output) !== '' ? $output : null;
    }

    private function bitsFromMask(string $mask): int
    {
        $long = ip2long($mask);

        if ($long === false) {
            return 24;
        }

        return substr_count(decbin($long & 0xFFFFFFFF), '1');
    }
}

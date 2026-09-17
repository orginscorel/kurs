<?php

namespace App\Sync;

use Illuminate\Support\Str;

/**
 * Eşitlemenin çalışma anı bağlamı (istek/komut başına tek örnek).
 *
 *  - applying: uzak değişiklik uygulanıyor → ChangeRecorder yazmaz (uygulayıcı kendisi kaydeder), yerel engeller devre dışı.
 *  - origin: sunucuda cihazdan gelen değişikliği uygularken kaynak cihaz.
 *  - capture: yerel düğümde finans komutu yürütülürken oluşan uuid/numaralar toplanır (komut paketine girer).
 *  - presets: sunucuda komut yeniden yürütülürken aynı uuid ve belge numaraları sırayla verilir.
 */
class SyncContext
{
    private int $applying = 0;

    public ?int $originDeviceId = null;

    public ?string $originCommand = null;

    /** @var list<array{uuids: array<string, list<string>>, numbers: array<string, list<string>>, touched: array<string, list<string>>}> */
    private array $captures = [];

    /** @var array<string, list<string>> tablo => sıradaki uuid'ler */
    private array $presetUuids = [];

    /** @var array<string, list<string>> sayaç adı => sıradaki numaralar */
    private array $presetNumbers = [];

    private int $suppressed = 0;

    /** @var list<string> komut yeniden yürütülürken eksiye düşen kasalar (mutabakat notu) */
    private array $negativeCash = [];

    public function isApplying(): bool
    {
        return $this->applying > 0;
    }

    /**
     * Sunucuda cihaz komutu yeniden yürütülüyor: para fiilen hareket etti; nakit kasa sunucudaki
     * bakiyeye göre eksiye düşse de işlem reddedilmez, mutabakat kuyruğuna not düşülür.
     */
    public function replayingDeviceCommand(): bool
    {
        return $this->originCommand !== null && $this->originCommand !== '' && config('kurs.node') !== 'local';
    }

    public function noteNegativeCash(string $accountName, string $balance): void
    {
        $this->negativeCash[] = sprintf('"%s" kasası bu işlemle %s TL bakiyeye düştü (çevrimdışı işlem sunucuda yürütülürken nakit yetersizdi; kasa sayımı yapın).', $accountName, $balance);
    }

    /** @return list<string> */
    public function takeNegativeCashNotes(): array
    {
        $out = array_values(array_unique($this->negativeCash));
        $this->negativeCash = [];

        return $out;
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed > 0;
    }

    /** Uzak değişiklik uygularken: kayıt ve yerel engeller kapalı. */
    public function applying(callable $fn, ?int $originDeviceId = null): mixed
    {
        $this->applying++;
        $prevOrigin = $this->originDeviceId;
        $this->originDeviceId = $originDeviceId ?? $prevOrigin;
        try {
            return $fn();
        } finally {
            $this->applying--;
            $this->originDeviceId = $prevOrigin;
        }
    }

    /** Değişiklik günlüğüne hiç yazmadan çalıştır (türetilmiş veri, yeniden hesaplama). */
    public function withoutRecording(callable $fn): mixed
    {
        $this->suppressed++;
        try {
            return $fn();
        } finally {
            $this->suppressed--;
        }
    }

    public function isCapturing(): bool
    {
        return $this->captures !== [];
    }

    /**
     * Yerel komut yürütmesi: oluşan uuid ve belge numaraları toplanır.
     *
     * @return array{0: mixed, 1: array{uuids: array<string, list<string>>, numbers: array<string, list<string>>, touched: array<string, list<string>>}}
     */
    public function capture(callable $fn): array
    {
        $this->captures[] = ['uuids' => [], 'numbers' => [], 'touched' => []];
        try {
            $result = $fn();
            $captured = end($this->captures);

            return [$result, $captured];
        } finally {
            array_pop($this->captures);
        }
    }

    /**
     * Sunucuda komut yeniden yürütmesi: verilen uuid/numaralar sırayla kullanılır.
     *
     * @param array<string, list<string>> $uuids
     * @param array<string, list<string>> $numbers
     * @return array{0: mixed, 1: array<string, list<string>>} sonuç + kullanılmayan uuid'ler
     */
    public function withPresets(array $uuids, array $numbers, callable $fn): array
    {
        $prevU = $this->presetUuids;
        $prevN = $this->presetNumbers;
        $this->presetUuids = array_map(fn ($l) => array_values(array_filter((array) $l, 'is_string')), $uuids);
        $this->presetNumbers = array_map(fn ($l) => array_values(array_map('strval', (array) $l)), $numbers);
        try {
            $result = $fn();
            $unused = array_filter($this->presetUuids, fn ($l) => $l !== []);

            return [$result, $unused];
        } finally {
            $this->presetUuids = $prevU;
            $this->presetNumbers = $prevN;
        }
    }

    /** Yeni satır uuid'i: önce hazır liste, yoksa UUIDv7. Yerel yakalamada kaydedilir. */
    public function nextUuid(string $table): string
    {
        if (! empty($this->presetUuids[$table])) {
            $uuid = array_shift($this->presetUuids[$table]);
        } else {
            $uuid = (string) Str::uuid7();
        }
        $this->remember('uuids', $table, $uuid);

        return $uuid;
    }

    /** Sunucuda yeniden yürütmede cihazın verdiği numara (varsa). */
    public function takePresetNumber(string $name): ?string
    {
        if (! empty($this->presetNumbers[$name])) {
            return array_shift($this->presetNumbers[$name]);
        }

        return null;
    }

    public function hasPresets(): bool
    {
        return $this->presetUuids !== [] || $this->presetNumbers !== [];
    }

    public function rememberNumber(string $name, string $value): void
    {
        $this->remember('numbers', $name, $value);
    }

    /** DB::table ile eklenmiş (olaysız) satırlara sonradan verilen uuid de komut paketine girer. */
    public function rememberUuid(string $table, string $uuid): void
    {
        $this->remember('uuids', $table, $uuid);
    }

    /** Komut sırasında değiştirilen (var olan) satır: sunucu reddederse yerelde geri yüklenir. */
    public function rememberTouched(string $table, string $uuid): void
    {
        if ($this->captures === []) {
            return;
        }
        $i = array_key_last($this->captures);
        if (! in_array($uuid, $this->captures[$i]['touched'][$table] ?? [], true)
            && ! in_array($uuid, $this->captures[$i]['uuids'][$table] ?? [], true)) {
            $this->captures[$i]['touched'][$table][] = $uuid;
        }
    }

    private function remember(string $bucket, string $key, string $value): void
    {
        if ($this->captures === []) {
            return;
        }
        $i = array_key_last($this->captures);
        $this->captures[$i][$bucket][$key][] = $value;
    }
}

<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * KVKK: hassas alan yardımcıları (TC kimlik, telefon).
 */
class Sensitive
{
    /** TC kimlik numarası algoritma doğrulaması (11 hane + iki kontrol hanesi). */
    public static function isValidNationalId(?string $value): bool
    {
        if (! is_string($value) || ! preg_match('/^[1-9]\d{10}$/', $value)) {
            return false;
        }

        $d = array_map('intval', str_split($value));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];

        if ((($odd * 7) - $even) % 10 !== $d[9]) {
            return false;
        }

        return array_sum(array_slice($d, 0, 10)) % 10 === $d[10];
    }

    /** @return array{national_id_encrypted: ?string, national_id_hash: ?string, national_id_last4: ?string} */
    public static function nationalIdColumns(?string $value): array
    {
        $value = $value ? preg_replace('/\D/', '', $value) : null;

        if (! $value) {
            return ['national_id_encrypted' => null, 'national_id_hash' => null, 'national_id_last4' => null];
        }

        return [
            'national_id_encrypted' => self::encryptString($value),
            'national_id_hash' => self::hash($value),
            'national_id_last4' => substr($value, -4),
        ];
    }

    /** Anahtarlı özet: veritabanı sızsa bile TC numaraları sözlük saldırısıyla çözülemez. */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $value), self::dataKey());
    }

    /**
     * Kurum veri anahtarı (KURS_DATA_KEY; yoksa APP_KEY). Yerel kurulum bu anahtarı eşleştirmede mühürlü
     * paketle alır; anahtarsız yerel kurulumda TC girilemez (sunucudaki özetle uyuşmaz).
     */
    public static function dataKey(): string
    {
        $key = (string) (config('kurs.data_key') ?: '');
        if ($key === '') {
            if (config('kurs.node') === 'local') {
                throw new \App\Exceptions\BusinessRuleException('Bu kurulumda kurum veri anahtarı yok; TC kimlik numarası çevrimdışı girilemez.', 'data_key_missing', [], 409);
            }
            $key = (string) config('app.key');
        }

        return $key;
    }

    private static ?Encrypter $dataEncrypter = null;

    private static function encrypter(): ?Encrypter
    {
        $key = (string) (config('kurs.data_key') ?: '');
        if ($key === '') {
            return null;
        }
        if (self::$dataEncrypter === null || self::$dataEncrypterKey !== $key) {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
            self::$dataEncrypter = new Encrypter($raw, (string) config('app.cipher', 'AES-256-CBC'));
            self::$dataEncrypterKey = $key;
        }

        return self::$dataEncrypter;
    }

    private static ?string $dataEncrypterKey = null;

    public static function encryptString(string $value): string
    {
        self::dataKey();   // yerelde anahtarsızsa açık hata

        return self::encrypter()?->encryptString($value) ?? Crypt::encryptString($value);
    }

    public static function decrypt(?string $encrypted): ?string
    {
        return self::decryptDetailed($encrypted)['value'];
    }

    /**
     * Çözülen değer + hangi anahtarla çözüldüğü ('data' = kurum veri anahtarı, 'app' = APP_KEY).
     * Veri anahtarı tanımlıyken eski (APP_KEY ile şifrelenmiş) kayıtlar da okunur (geçiş dönemi).
     *
     * @return array{value: ?string, key: 'data'|'app'|null}
     */
    public static function decryptDetailed(?string $encrypted): array
    {
        if (! $encrypted) {
            return ['value' => null, 'key' => null];
        }
        if ($enc = self::encrypter()) {
            try {
                return ['value' => $enc->decryptString($encrypted), 'key' => 'data'];
            } catch (\Throwable) {
                // eski kayıt APP_KEY ile şifrelenmiş olabilir
            }
        }
        try {
            return ['value' => Crypt::decryptString($encrypted), 'key' => 'app'];
        } catch (\Throwable) {
            return ['value' => null, 'key' => null];
        }
    }

    /** Kurum veri anahtarı tanımlı mı (KURS_DATA_KEY ya da yerelde eşleştirmeyle alınan). */
    public static function hasDataKey(): bool
    {
        return (string) (config('kurs.data_key') ?: '') !== '';
    }

    /** Anahtarın kısa parmak izi (anahtarın kendisi değil): cihaz/sunucu anahtar uyumu için. */
    public static function dataKeyFingerprint(): ?string
    {
        return self::hasDataKey() ? substr(hash('sha256', 'kurs-data-key|'.config('kurs.data_key')), 0, 12) : null;
    }

    /**
     * Arama / tekillik denetimi için olası özetler. Veri anahtarı tanımlı ama eski kayıtlar henüz
     * taşınmamışsa (kurs:data-key migrate) APP_KEY ile alınmış özet de aranır.
     *
     * @return list<string>
     */
    public static function hashes(string $value): array
    {
        $digits = preg_replace('/\D/', '', $value);
        $out = [self::hash($digits)];
        if (self::hasDataKey() && config('kurs.node') !== 'local' && (string) config('app.key') !== '') {
            $out[] = self::legacyHash($digits);
        }

        return array_values(array_unique($out));
    }

    /** APP_KEY ile alınmış (veri anahtarı öncesi) özet. */
    public static function legacyHash(string $value): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $value), (string) config('app.key'));
    }

    /** Taşıma komutu: değeri yalnız kurum veri anahtarıyla şifreler (anahtar yoksa hata). */
    public static function encryptWithDataKey(string $value): string
    {
        $enc = self::encrypter() ?? throw new \RuntimeException('KURS_DATA_KEY tanımlı değil.');

        return $enc->encryptString($value);
    }

    public static function maskNationalId(?string $last4): ?string
    {
        return $last4 ? '*******'.$last4 : null;
    }

    public static function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return strlen($phone) > 4 ? str_repeat('•', max(0, strlen($phone) - 4)).substr($phone, -4) : $phone;
    }

    /** Türkiye telefonunu E.164'e çevirir: "0532 111 22 33" → "905321112233". */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '90'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '5')) {
            $digits = '90'.$digits;
        }

        return strlen($digits) >= 10 ? $digits : null;
    }
}

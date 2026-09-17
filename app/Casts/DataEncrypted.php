<?php

namespace App\Casts;

use App\Support\Sensitive;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Kurum veri anahtarıyla (KURS_DATA_KEY; tanımlı değilse APP_KEY) şifreli metin sütunu.
 * Laravel'in 'encrypted' dönüşümüyle aynı biçimi üretir (encryptString) ve geçiş döneminde
 * APP_KEY ile şifrelenmiş eski değerleri de okur. Yerel kurulum bu anahtarı eşleştirmede alır;
 * böylece fatura alıcı vergi no / senet borçlu TCKN cihazda da çözülebilir (docs/SYNC.md › Güvenlik).
 */
class DataEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Sensitive::decrypt((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Sensitive::encryptString((string) $value);
    }
}

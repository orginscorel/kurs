<?php

namespace App\Services\Imports;

/**
 * Bir içe aktarma türünün (öğrenci, öğretmen) şablonu, satır doğrulaması ve kayıt oluşturması.
 *
 * Doğrulama saftır: veritabanı sorguları `$lookup` geri çağrısıyla dışarıdan verilir
 * (birim testlerde sahte sözlük, canlıda DB). Dosya içi yinelenenler `$seen` ile izlenir.
 */
abstract class RowImporter
{
    /** @var callable(string $kind, string $value): ?string  mevcut kaydın etiketi ya da null */
    protected $lookup;

    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? fn () => null;
    }

    abstract public function entity(): string;

    abstract public function label(): string;

    /** @return string yetki anahtarı */
    abstract public function permission(): string;

    /**
     * @return array<string, array{label: string, required?: bool, example?: string, hint?: string, aliases?: list<string>, width?: int}>
     */
    abstract public function columns(): array;

    /**
     * @param  array<string, mixed>  $raw  sütun anahtarı => hücre değeri
     * @param  array<string, array<string, int>>  $seen  dosya içi yinelenen izleme (tür => değer => ilk satır)
     * @return array{data: array<string, mixed>, errors: list<string>, warnings: list<string>, title: string, subtitle: ?string}
     */
    abstract public function validate(array $raw, int $rowNumber, array &$seen): array;

    /**
     * Doğrulanmış satırı kaydeder.
     *
     * @return array{id: int, title: string, note?: ?string, secret?: ?string}
     */
    abstract public function import(array $data): array;

    /**
     * Başlık satırını sütun anahtarlarına eşler.
     *
     * @param  list<mixed>  $headers
     * @return array{map: array<int, string>, missing: list<string>, unknown: list<string>}
     */
    public function mapHeaders(array $headers): array
    {
        $lookup = [];
        foreach ($this->columns() as $key => $col) {
            foreach ([$col['label'], ...($col['aliases'] ?? [])] as $alias) {
                $lookup[ImportValue::fold($alias)] = $key;
            }
        }

        $map = [];
        $unknown = [];
        foreach ($headers as $i => $h) {
            $text = ImportValue::text($h);
            if ($text === null) {
                continue;
            }
            $key = $lookup[ImportValue::fold($text)] ?? null;
            if ($key !== null && ! in_array($key, $map, true)) {
                $map[$i] = $key;
            } else {
                $unknown[] = $text;
            }
        }

        $missing = [];
        foreach ($this->columns() as $key => $col) {
            if (! empty($col['required']) && ! in_array($key, $map, true)) {
                $missing[] = $col['label'];
            }
        }

        return ['map' => $map, 'missing' => $missing, 'unknown' => $unknown];
    }

    protected function existing(string $kind, string $value): ?string
    {
        return ($this->lookup)($kind, $value);
    }

    /** Dosya içinde aynı değer daha önce geçtiyse ilk satır numarasını döner. */
    protected function seenBefore(array &$seen, string $kind, ?string $value, int $row): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (isset($seen[$kind][$value])) {
            return $seen[$kind][$value];
        }
        $seen[$kind][$value] = $row;

        return null;
    }

    /**
     * Zorunlu/biçim kontrolü yardımcıları: `$errors` dizisine Türkçe mesaj ekler.
     */
    protected function required(array $raw, string $key, array &$errors, ?callable $parse = null): mixed
    {
        $label = $this->columns()[$key]['label'];
        $value = $parse ? $parse($raw[$key] ?? null) : ImportValue::text($raw[$key] ?? null);
        if ($value === null || $value === '') {
            $errors[] = "{$label} boş olamaz.";

            return null;
        }

        return $value;
    }

    protected function maxLen(?string $value, int $max, string $label, array &$errors): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $errors[] = "{$label} en fazla {$max} karakter olabilir.";
        }

        return $value;
    }
}

<?php

namespace App\Sync;

/**
 * Kayıt defterindeki bir tablonun eşitleme tanımı (değişmez).
 */
final class SyncTable
{
    /**
     * @param list<string> $key
     * @param list<string> $exclude
     * @param array<string, string> $sensitive sütun => gereken yetki
     * @param array<string, string|null> $fk sütun => tablo | 'morph:<tip sütunu>' | null (referans değil)
     * @param array<string, mixed> $fill eşitlenmeyen (exclude) ama boş bırakılamayan sütunlara alıcı düğümde
     *                                   yeni satır eklenirken verilecek değer; '@random64' = rastgele 64 hane (tekil sütunlar)
     * @param string|null $since tablo eşitlemeye SONRADAN katıldıysa işaret (ör. '2026-09-18'): eşleşmiş yerel
     *                           kurulum bu tabloyu bir kez ayrıca anlık görüntüyle çeker (LocalSyncEngine::lateTables)
     */
    public function __construct(
        public readonly string $table,
        public readonly string $kind,
        public readonly array $key = [],
        public readonly array $exclude = [],
        public readonly array $sensitive = [],
        public readonly ?string $permission = null,
        public readonly string $branch = 'auto',
        public readonly array $fk = [],
        public readonly ?string $filter = null,
        public readonly string $direction = 'both',
        public readonly ?string $reason = null,
        public readonly array $fill = [],
        public readonly ?string $since = null,
    ) {}

    public static function fromArray(string $table, array $def): self
    {
        return new self(
            table: $table,
            kind: $def['kind'],
            key: $def['key'] ?? [],
            exclude: $def['exclude'] ?? [],
            sensitive: $def['sensitive'] ?? [],
            permission: $def['permission'] ?? null,
            branch: $def['branch'] ?? 'auto',
            fk: $def['fk'] ?? [],
            filter: $def['filter'] ?? null,
            direction: $def['direction'] ?? match ($def['kind']) {
                SyncRegistry::SERVER, SyncRegistry::LEDGER => 'down',
                SyncRegistry::UPLOAD => 'up',
                default => 'both',
            },
            reason: $def['reason'] ?? null,
            fill: $def['fill'] ?? [],
            since: $def['since'] ?? null,
        );
    }

    public function isSynced(): bool
    {
        return in_array($this->kind, [...SyncRegistry::UUID_KINDS, SyncRegistry::PIVOT], true);
    }

    public function hasUuid(): bool
    {
        return in_array($this->kind, SyncRegistry::UUID_KINDS, true);
    }

    public function isPivot(): bool
    {
        return $this->kind === SyncRegistry::PIVOT;
    }

    public function isLedger(): bool
    {
        return $this->kind === SyncRegistry::LEDGER;
    }

    /** Yerel düğüm bu tablodaki satır değişikliğini yukarı gönderebilir mi */
    public function isPushable(): bool
    {
        return in_array($this->kind, SyncRegistry::PUSHABLE_KINDS, true) && $this->direction !== 'down';
    }

    /** Sunucu bu tablodaki değişikliği cihazlara indirir mi */
    public function isPullable(): bool
    {
        return $this->isSynced() && $this->direction !== 'up';
    }

    /** Sunucu-otoriteli (aşağı yönde yerel değeri koşulsuz ezer) */
    public function isServerAuthoritative(): bool
    {
        return in_array($this->kind, [SyncRegistry::SERVER, SyncRegistry::LEDGER], true);
    }

    public function excludes(string $column): bool
    {
        return in_array($column, $this->exclude, true);
    }

    /**
     * Yeni satır eklenirken eşitlenmeyen zorunlu sütunların düğüme özel değerleri (tel üzerinde hiç gelmezler).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function withFill(array $row): array
    {
        foreach ($this->fill as $column => $value) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                continue;
            }
            $row[$column] = $value === '@random64' ? bin2hex(random_bytes(32)) : $value;
        }

        return $row;
    }
}

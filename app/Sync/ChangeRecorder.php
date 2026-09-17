<?php

namespace App\Sync;

use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Değişiklik günlüğü yazıcısı. Model dosyalarına dokunmadan tüm Eloquent modellerini genel
 * olay dinleyicisiyle izler (eloquent.created/updated/deleted: *). DB::table ile yapılan olaysız
 * yazmaları Sweeper yakalar.
 *
 * Sunucu: indirilebilir tablolardaki her değişiklik yazılır (kaynak: web ya da cihaz).
 * Yerel düğüm: yalnız yukarı itilebilen tablolar yazılır; finans (ledger ve finans türevleri)
 * komut dışında değiştirilemez (çevrimdışı desteklenmeyen işlem → kullanıcıya açık mesaj).
 */
class ChangeRecorder
{
    private ?bool $ready = null;

    /** Yerelde komut dışında yazılamayan (finans) sunucu tabloları */
    public const LOCAL_GUARDED = [
        'installments', 'payment_allocations', 'refund_allocations', 'invoice_lines', 'invoice_payments',
        'account_transactions', 'journal_entries', 'journal_lines', 'stock_movements', 'payment_card_details',
        'finance_accounts', 'finance_categories', 'collection_notes', 'reconciliation_marks', 'accounting_periods',
        'ledger_accounts', 'ledger_mappings', 'contracts',
    ];

    /** Yerelde users tablosunda değişebilen (düğüme özel) sütunlar; gerisi sunucu-otoriteli (parola, rol, durum). */
    public const LOCAL_USER_COLUMNS = ['last_login_at', 'last_login_ip', 'remember_token', 'updated_at'];

    public function __construct(
        private readonly SyncContext $context,
        private readonly SyncSchema $schema,
        private readonly RowCodec $codec,
    ) {}

    public static function register(): void
    {
        Event::listen('eloquent.creating: *', fn (string $e, array $d) => app(self::class)->creating($d[0]));
        Event::listen('eloquent.updating: *', fn (string $e, array $d) => app(self::class)->updating($d[0]));
        Event::listen('eloquent.deleting: *', fn (string $e, array $d) => app(self::class)->guard($d[0], true));
        Event::listen('eloquent.created: *', fn (string $e, array $d) => app(self::class)->record($d[0], 'insert'));
        Event::listen('eloquent.updated: *', fn (string $e, array $d) => app(self::class)->record($d[0], 'update'));
        Event::listen('eloquent.deleted: *', fn (string $e, array $d) => app(self::class)->deleted($d[0]));
        Event::listen('eloquent.restored: *', fn (string $e, array $d) => app(self::class)->record($d[0], 'restore'));
    }

    public function enabled(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }
        if (! config('sync.record', true)) {
            return $this->ready = false;
        }
        try {
            return $this->ready = Schema::hasTable('sync_changes');
        } catch (\Throwable) {
            return $this->ready = false;
        }
    }

    public function reset(): void
    {
        $this->ready = null;
    }

    public static function isLocalNode(): bool
    {
        return config('kurs.node') === 'local';
    }

    /** Yeni satıra uuid + (model tutmuyorsa) updated_at ver. */
    public function creating(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }
        $table = $model->getTable();
        $def = SyncRegistry::get($table);
        if (! $def || ! $def->hasUuid()) {
            return;
        }
        $this->guard($model);
        if ($this->schema->hasUuid($table) && empty($model->getAttributes()['uuid'] ?? null)) {
            $model->setAttribute('uuid', $this->context->nextUuid($table));
        }
        $this->touchUpdatedAt($model, $table);
    }

    public function updating(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }
        $table = $model->getTable();
        $def = SyncRegistry::get($table);
        if (! $def || ! $def->hasUuid()) {
            return;
        }
        $this->guard($model);
        $this->touchUpdatedAt($model, $table);
    }

    private function touchUpdatedAt(Model $model, string $table): void
    {
        $maintained = $model->usesTimestamps() && $model->getUpdatedAtColumn() === 'updated_at';
        if (! $maintained && $this->schema->hasColumn($table, 'updated_at')) {
            $model->setAttribute('updated_at', $model->freshTimestampString());
        }
    }

    /** Yerel düğüm: finans tablolarına komut dışında yazma yok. */
    public function guard(Model $model, bool $deleting = false): void
    {
        if (! self::isLocalNode() || $this->context->isApplying() || ! $this->enabled()) {
            return;
        }
        if ($model->getTable() === SyncRegistry::usersTable()) {
            // Personel hesapları sunucu-otoriteli: yerel değişiklik sunucuya gitmez → sessizce kaybolmasın.
            // Portal hesapları (öğrenci/veli) cihaza hiç inmez; yerelde servislerin açtığı kopya düğüme özeldir,
            // sunucu kendi hesabını ServerHooks ile açar.
            $type = $model->getAttribute('user_type') ?? $model->getOriginal('user_type');
            if (! in_array($type ?? 'staff', SyncFilters::STAFF_TYPES, true)) {
                return;
            }
            if ($deleting || ! $model->exists || array_diff(array_keys($model->getDirty()), self::LOCAL_USER_COLUMNS) !== []) {
                throw new BusinessRuleException(
                    'Kullanıcı hesabı ve parola değişiklikleri çevrimdışı kurulumda yapılamıyor. İnternet bağlantısı varken yapın (kendi parolanızı Profil ekranından değiştirebilirsiniz; bağlantı varsa sunucuda güncellenir).',
                    'offline_not_supported', ['table' => 'users'], 409,
                );
            }

            return;
        }
        if ($this->context->isCapturing()) {
            return;
        }
        $def = SyncRegistry::get($model->getTable());
        if ($def && ($def->isLedger() || in_array($def->table, self::LOCAL_GUARDED, true))) {
            throw new BusinessRuleException(
                'Bu finans işlemi çevrimdışı kurulumda yapılamıyor. İnternet bağlantısı varken web panelinden yapın.',
                'offline_not_supported', ['table' => $def->table], 409,
            );
        }
    }

    public function deleted(Model $model): void
    {
        $softDeleting = in_array(SoftDeletes::class, class_uses_recursive($model), true)
            && ! $model->isForceDeleting();
        $this->record($model, $softDeleting ? 'softdelete' : 'delete');
    }

    public function record(Model $model, string $event): void
    {
        if (! $this->enabled() || $this->context->isApplying() || $this->context->isSuppressed()) {
            return;
        }
        $table = $model->getTable();
        $def = SyncRegistry::get($table);
        if (! $def || ! $def->hasUuid() || ! $this->schema->hasUuid($table)) {
            return;
        }

        if (self::isLocalNode()) {
            // Komut yürütmesinde satırlar komutla temsil edilir; itilemeyen tablolar yerelde kalır.
            if ($this->context->isCapturing()) {
                if ($event !== 'insert' && ! empty($model->getAttributes()['uuid'])) {
                    $this->context->rememberTouched($table, $model->getAttributes()['uuid']);
                }
                // Komutla temsil edilen satır: süpürücü ayrıca göndermesin
                $this->refreshHash($def, $model, $event);

                return;
            }
            if (! $def->isPushable()) {
                return;
            }
        } elseif ($def->direction === 'up') {
            return;   // yalnız yukarı tablolar (denetim) sunucuda günlüğe girmez
        }

        if (! SyncFilters::allows($def, $model->getAttributes())) {
            return;
        }

        $attrs = $model->getAttributes();
        $uuid = $attrs['uuid'] ?? null;
        if (! $uuid) {
            // uuid'siz eski satır (henüz doldurulmamış): şimdi ver
            $uuid = $this->codec->uuidFor($table, $model->getKey());
            if (! $uuid) {
                return;
            }
        }

        switch ($event) {
            case 'insert':
                $op = 'insert';
                $fields = $this->codec->encode($table, $attrs);
                break;
            case 'update':
                $changes = array_diff_key($model->getChanges(), array_flip(['updated_at']));
                if ($changes === []) {
                    return;
                }
                $op = 'update';
                $changed = array_intersect_key($attrs, $changes);
                if (isset($attrs['updated_at'])) {
                    $changed['updated_at'] = $attrs['updated_at'];
                }
                $fields = $this->encodeWithMorph($table, $changed, $attrs);
                if ($fields === [] || array_keys($fields) === ['updated_at']) {
                    return;
                }
                break;
            case 'softdelete':
            case 'restore':
                $op = 'update';
                $fields = ['deleted_at' => RowCodec::normalize($attrs['deleted_at'] ?? null)];
                if (isset($attrs['updated_at'])) {
                    $fields['updated_at'] = RowCodec::normalize($attrs['updated_at']);
                }
                break;
            default:
                $op = 'delete';
                $fields = null;
        }

        $this->write($table, $uuid, $op, $fields, $this->branchFor($def, $attrs, $model));
        $this->refreshHash($def, $model, $event);
    }

    /** Günlüğe yazılan satırın güncel özeti: süpürücü yalnız olaysız yazmaları yakalasın. */
    private function refreshHash(SyncTable $def, Model $model, string $event): void
    {
        $sweeper = app(Sweeper::class);
        if ($event === 'delete') {
            $sweeper->forget($def, (string) $model->getKey());
        } else {
            $sweeper->refresh($def, ['id' => $model->getKey()]);
        }
    }

    /** Güncellemede morph referansı çözülebilsin diye tip sütunu da bağlamda tutulur. */
    private function encodeWithMorph(string $table, array $changed, array $attrs): array
    {
        $refs = $this->schema->refs($table);
        $encoded = $this->codec->encode($table, $changed + array_intersect_key($attrs, array_flip(
            array_map(fn ($r) => substr($r, 6), array_filter($refs, fn ($r) => str_starts_with($r, 'morph:')))
        )));

        return array_intersect_key($encoded, $changed);
    }

    /**
     * Günlüğe tek değişiklik yazar.
     *
     * @param array<string, mixed>|null $fields
     */
    public function write(string $table, string $rowUuid, string $op, ?array $fields, ?int $branchId, array $extra = []): int
    {
        $isLocal = self::isLocalNode();
        $row = array_merge([
            'change_uuid' => (string) Str::uuid7(),
            'branch_id' => $branchId,
            'table_name' => $table,
            'row_uuid' => $rowUuid,
            'op' => $op,
            'fields' => $fields === null ? null : json_encode($fields, JSON_UNESCAPED_UNICODE),
            'source' => $isLocal ? 'local' : ($this->context->originCommand ? 'command' : ($this->context->originDeviceId ? 'device' : 'web')),
            'command_uuid' => $isLocal ? null : $this->context->originCommand,
            'origin_device_id' => $isLocal ? null : $this->context->originDeviceId,
            'user_id' => Auth::id(),
            'created_at' => now()->format('Y-m-d H:i:s.v'),
        ], $extra);

        $id = (int) DB::table('sync_changes')->insertGetId($row);

        if ($op === 'delete' && ! $isLocal) {
            DB::table('sync_tombstones')->insert([
                'branch_id' => $branchId, 'table_name' => $table, 'row_uuid' => $rowUuid,
                'change_id' => $id, 'deleted_at' => now(),
            ]);
        }

        return $id;
    }

    /** Değişikliğin şubesi: satırın kendi şubesi > üst kayıt > istek bağlamı > ana şube. */
    public function branchFor(SyncTable $def, array $attrs, ?Model $model = null): ?int
    {
        return app(BranchResolver::class)->resolve($def, $attrs);
    }
}

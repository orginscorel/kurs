<?php

namespace App\Sync\Server;

use App\Models\User;
use App\Sync\Models\SyncDevice;
use App\Sync\Sweeper;
use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cihaza imleçten sonraki değişiklikleri verir (şube + yetki kapsamı süzülür).
 *
 * İmleç güvenliği: otomatik artan kimlikler commit sırasıyla gelmeyebilir. Arada boşluk varsa ve
 * boşluktan sonraki satır çok yeniyse (sürmekte olan transaction olabilir) sayfa orada kesilir;
 * eski boşluklar geri alınmış işlem sayılıp geçilir.
 */
class PullService
{
    public const GAP_SECONDS = 30;

    public function __construct(
        private readonly Sweeper $sweeper,
        private readonly SyncSchema $schema,
    ) {}

    /**
     * @return array{changes: list<array<string, mixed>>, cursor: int, more: bool, server_time: string, resnapshot?: bool}
     */
    public function pull(SyncDevice $device, User $user, int $cursor, int $limit): array
    {
        $limit = max(1, min((int) config('sync.pull_limit', 500), $limit));
        $this->sweepIfDue();

        // Günlük budanmışsa (kurs:sync-prune) eski imleçli cihaz yeniden anlık görüntü almalı
        $prunedBefore = (int) DB::table('sync_state')->where('key', 'pruned_before')->value('value');
        if ($cursor > 0 && $cursor < $prunedBefore) {
            return ['changes' => [], 'cursor' => $cursor, 'more' => false, 'server_time' => now()->toIso8601String(), 'resnapshot' => true];
        }

        $access = $this->access($user);
        // Şube süzgeci PHP'de: boşluk denetimi tüm kimlikleri görmeli (başka şubenin kaydı boşluk değildir)
        $rows = DB::table('sync_changes')
            ->where('id', '>', $cursor)
            ->orderBy('id')->limit($limit + 1)
            ->get();

        $young = CarbonImmutable::now()->subSeconds(self::GAP_SECONDS);
        $expected = $cursor + 1;
        $last = $cursor;
        $out = [];
        $more = $rows->count() > $limit;

        foreach ($rows->take($limit) as $r) {
            if ((int) $r->id !== $expected && CarbonImmutable::parse($r->created_at)->greaterThan($young)) {
                $more = true;   // boşluk yeni: sonraki çekmede tekrar bakılır
                break;
            }
            $expected = (int) $r->id + 1;
            $last = (int) $r->id;

            if ($r->branch_id !== null && (int) $r->branch_id !== (int) $device->branch_id) {
                continue;
            }
            if ((int) $r->origin_device_id === (int) $device->id && ! $r->echo) {
                continue;   // cihazın kendi değişikliği (aynen uygulandı)
            }
            $def = SyncRegistry::get($r->table_name);
            if (! $def || ! $def->isPullable() || $r->op === 'command') {
                continue;
            }
            if ($def->permission && ! $access($def->permission)) {
                continue;
            }
            $fields = $r->fields === null ? null : (array) json_decode($r->fields, true);
            if ($fields !== null) {
                foreach ($def->exclude as $col) {
                    unset($fields[$col]);
                }
                foreach ($def->sensitive as $col => $perm) {
                    if (! $access($perm)) {
                        unset($fields[$col]);
                    }
                }
            }
            $out[] = [
                'seq' => (int) $r->id,
                'id' => $r->change_uuid,
                'table' => $r->table_name,
                'row' => $r->row_uuid,
                'op' => $r->op,
                'fields' => $fields,
                'kind' => $def->kind,
                'source' => $r->source,
                'at' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
            ];
        }

        $device->forceFill([
            'last_pull_at' => now(), 'last_seen_at' => now(), 'last_cursor' => max((int) $device->last_cursor, $last),
        ])->save();

        return ['changes' => $out, 'cursor' => $last, 'more' => $more, 'server_time' => now()->toIso8601String()];
    }

    /** DB::table yazmaları çekmeden önce günlüğe girsin (en sık dakikada bir, hızlı kip). */
    public function sweepIfDue(): void
    {
        $every = (int) config('sync.sweep_before_pull_seconds', 60);
        if ($every <= 0) {
            return;
        }
        $lock = Cache::lock('sync:sweep', 120);
        if (! Cache::add('sync:sweep:recent', 1, $every) || ! $lock->get()) {
            return;
        }
        try {
            $this->sweeper->sweep();
        } finally {
            $lock->release();
        }
    }

    /** @return callable(string): bool */
    public function access(User $user): callable
    {
        $isSuper = $user->hasRole('super-admin');
        $perms = $isSuper ? null : array_flip($user->getAllPermissions()->pluck('name')->all());

        return fn (string $perm) => $isSuper || isset($perms[$perm]);
    }
}

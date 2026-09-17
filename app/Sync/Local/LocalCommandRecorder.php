<?php

namespace App\Sync\Local;

use App\Support\BranchContext;
use App\Sync\ChangeRecorder;
use App\Sync\Commands\CommandCodec;
use App\Sync\Commands\CommandRegistry;
use App\Sync\RowCodec;
use App\Sync\SyncContext;
use App\Sync\SyncNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Yerel düğüm: finans servis çağrısını yürütür ve aynı transaction içinde KOMUT olarak günlüğe yazar.
 * Paket: komut adı + uuid'li argümanlar + oluşan satırların uuid'leri (tablo başına sırayla) + verilen
 * belge numaraları + değişen satırlar. Sunucu aynı servisi aynı uuid/numaralarla yeniden yürütür.
 */
class LocalCommandRecorder
{
    public function __construct(
        private readonly SyncContext $context,
        private readonly CommandCodec $codec,
        private readonly ChangeRecorder $recorder,
        private readonly RowCodec $rows,
    ) {}

    /**
     * @template T
     * @param callable(): T $fn
     * @param (callable(array $args, array $captured, mixed $result): ?array{0: array, 1: array})|null $finalize
     *        paketi son haline getirir (ör. senet: yeni senetleri taksit bazında argümana taşı)
     * @return T
     */
    public function run(string $name, array $args, callable $fn, ?callable $finalize = null): mixed
    {
        if ($this->context->isCapturing() || $this->context->isApplying() || ! $this->recorder->enabled()) {
            return $fn();
        }
        $def = CommandRegistry::get($name) ?? throw new \LogicException("Tanımsız eşitleme komutu: $name");
        $encodedArgs = $this->codec->encode($args, $def['spec']);

        return DB::transaction(function () use ($name, $def, $encodedArgs, $fn, $finalize) {
            $before = $this->codec->maxIds();
            [$result, $captured] = $this->context->capture(function () use ($fn, $before) {
                $result = $fn();
                $this->codec->assignMissingUuids($before);

                return $result;
            });
            $captured['uuids'] = array_intersect_key($captured['uuids'], array_flip(CommandRegistry::produces($name)));
            $captured['numbers'] = array_intersect_key($captured['numbers'], array_flip([...SyncNumbers::DEVICE_DOCUMENTS, ...SyncNumbers::LEASABLE]));
            $args = $encodedArgs;
            if ($finalize) {
                $final = $finalize($args, $captured, $result);
                if ($final === null) {
                    return $result;   // gönderilecek bir şey oluşmadı
                }
                [$args, $captured] = $final;
            }

            [$rootUuid, $branchId] = $this->root($def['root'], $result, $captured);
            $this->recorder->write($def['root'], $rootUuid, 'command', [
                'name' => $name,
                'args' => $args,
                'uuids' => $captured['uuids'],
                'numbers' => $captured['numbers'],
                'touched' => $captured['touched'],
            ], $branchId);

            return $result;
        });
    }

    /**
     * Komutun olaysız (toplu sorgu) değiştireceği satırlar: sunucu reddederse sunucudan geri yüklensin.
     * Komut gövdesi içinde (run'a verilen fonksiyonda) çağrılır.
     *
     * @param iterable<int> $ids
     */
    public function touch(string $table, iterable $ids): void
    {
        if (! $this->context->isCapturing()) {
            return;
        }
        foreach ($ids as $id) {
            if ($uuid = $this->rows->uuidFor($table, (int) $id)) {
                $this->context->rememberTouched($table, $uuid);
            }
        }
    }

    /** @return array{0: string, 1: ?int} */
    private function root(string $table, mixed $result, array $captured): array
    {
        $model = $result instanceof Model ? $result : null;
        if (! $model && is_iterable($result)) {
            foreach ($result as $item) {
                if ($item instanceof Model) {
                    $model = $item;
                    break;
                }
            }
        }
        if ($model) {
            $uuid = $model->getAttribute('uuid') ?: DB::table($model->getTable())->where('id', $model->getKey())->value('uuid');
            if ($uuid) {
                return [(string) $uuid, $model->getAttribute('branch_id') ?? app(BranchContext::class)->id()];
            }
        }

        return [(string) ($captured['uuids'][$table][0] ?? $captured['touched'][$table][0] ?? Str::uuid7()), app(BranchContext::class)->id()];
    }
}

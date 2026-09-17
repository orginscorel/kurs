<?php

namespace App\Sync;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Belge numaraları çevrimdışı cihazda ÇAKIŞMAZ:
 *
 *  - Önekli belge numaraları (makbuz, kayıt, sözleşme, senet, iade, disiplin…): yerel düğüm kendi cihaz
 *    kodunu ekler → MKB-D2-2026-000123. Sunucu komutu yeniden yürütürken cihazın verdiği numarayı AYNEN
 *    kullanır (basılı makbuz numarası değişmez).
 *  - Yalın sayaçlar (öğrenci no — aynı zamanda portal kullanıcı adı): sunucudan önceden ayrılmış blok.
 *    Sunucu sayacı bloğun sonuna ilerletir; iki taraf aynı sayıyı veremez.
 *  - Yasal belge (e-Arşiv/e-Fatura, 'invoice:*'): GİB sıra numarası kesintisiz ve kronolojik olmalı ve
 *    entegratörde çevrimiçi kesilir → yerelde numara VERİLMEZ (fatura çevrimiçi işlemdir).
 */
final class SyncNumbers
{
    /** Yerelde numara verilmeyen (yalnız sunucu) sayaçlar */
    private const SERVER_ONLY_PREFIXES = ['invoice'];

    /**
     * Cihazın verdiği numaranın sunucuda AYNEN korunduğu belge sayaçları (basılı/teslim edilen belgeler).
     * Yevmiye (journal) gibi muhasebe sayaçları sunucuda kendi sırasıyla verilir; yereldeki geçici numara çekmede düzelir.
     */
    public const DEVICE_DOCUMENTS = ['receipt', 'enrollment', 'contract', 'refund', 'promissory_note',
        'discipline_incident', 'discipline_positive', 'discipline_sanction', 'discipline_board'];

    /** Blokla dağıtılan yalın sayaçlar ve ilk değer hesabı */
    public const LEASABLE = ['student_no'];

    /**
     * @return array{value?: string, name?: string, prefix?: string}
     */
    public static function forDocument(string $name, string $prefix): array
    {
        $ctx = app(SyncContext::class);
        if ($ctx->hasPresets() && ($preset = $ctx->takePresetNumber($name)) !== null) {
            return ['value' => $preset];
        }
        if (config('kurs.node') !== 'local' || $ctx->isApplying()) {
            return ['name' => $name, 'prefix' => $prefix];
        }
        foreach (self::SERVER_ONLY_PREFIXES as $p) {
            if (str_starts_with($name, $p)) {
                throw new BusinessRuleException('Fatura numarası yalnız çevrimiçi verilebilir. İnternet bağlantısı varken web panelinden kesin.', 'offline_not_supported', [], 409);
            }
        }
        $code = self::deviceCode();

        return ['name' => $name.'@'.$code, 'prefix' => $prefix.'-'.$code];
    }

    public static function forNumber(string $name, ?int $branchId): ?int
    {
        $ctx = app(SyncContext::class);
        if ($ctx->hasPresets() && ($preset = $ctx->takePresetNumber($name)) !== null) {
            return (int) $preset;
        }
        if (config('kurs.node') !== 'local' || $ctx->isApplying()) {
            return null;
        }

        return self::takeFromBlock($name, $branchId);
    }

    /** Yerel komut yakalaması: verilen numara pakete girer. */
    public static function issued(string $name, string $value): void
    {
        if (config('kurs.node') === 'local') {
            app(SyncContext::class)->rememberNumber($name, $value);
        }
    }

    public static function deviceCode(): string
    {
        $code = (string) config('sync.device_code', '');
        if ($code === '' && \Illuminate\Support\Facades\Schema::hasTable('sync_state')) {
            $code = (string) DB::table('sync_state')->where('key', 'device_code')->value('value');
        }
        if ($code === '') {
            throw new BusinessRuleException('Bu kurulum henüz sunucuyla eşleştirilmedi; belge numarası verilemiyor.', 'device_not_paired', [], 409);
        }

        return preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    }

    /** Yerel: ayrılmış bloktan sıradaki sayı. */
    public static function takeFromBlock(string $name, ?int $branchId): int
    {
        return DB::transaction(function () use ($name, $branchId) {
            $block = DB::table('sync_number_blocks')->where('name', $name)
                ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->whereColumn('next', '<=', 'end')->orderBy('id')->lockForUpdate()->first();
            if (! $block) {
                throw new BusinessRuleException('Çevrimdışı numara aralığı bitti. İnternete bağlanıp eşitleme yapın.', 'number_block_exhausted', ['name' => $name], 409);
            }
            DB::table('sync_number_blocks')->where('id', $block->id)->update(['next' => $block->next + 1, 'updated_at' => now()]);

            return (int) $block->next;
        });
    }

    /** Yerel: bu sayaçta kalan numara adedi. */
    public static function remaining(string $name): int
    {
        return (int) DB::table('sync_number_blocks')->where('name', $name)->whereColumn('next', '<=', 'end')
            ->selectRaw('COALESCE(SUM('.DB::getQueryGrammar()->wrap('end').' - '.DB::getQueryGrammar()->wrap('next').' + 1), 0) AS r')->value('r');
    }

    /**
     * Sunucu: cihaza blok ayır. Sayaç satırı kilitlenir ve bloğun sonuna ilerletilir.
     *
     * @return array{name: string, start: int, end: int}
     */
    public static function lease(int $branchId, int $deviceId, string $name, int $size): array
    {
        if (! in_array($name, self::LEASABLE, true)) {
            throw new BusinessRuleException('Bu sayaç için blok ayrılamaz.', 'not_leasable', [], 422);
        }
        $size = max(1, min(500, $size));

        return DB::transaction(function () use ($branchId, $deviceId, $name, $size) {
            $row = DB::table('sequences')->where('branch_id', $branchId)->where('name', $name)->where('year', 0)->lockForUpdate()->first();
            if (! $row) {
                DB::table('sequences')->insertOrIgnore(['branch_id' => $branchId, 'name' => $name, 'year' => 0, 'last_value' => self::initialValue($name, $branchId) - 1]);
                $row = DB::table('sequences')->where('branch_id', $branchId)->where('name', $name)->where('year', 0)->lockForUpdate()->first();
            }
            $start = (int) $row->last_value + 1;
            $end = $start + $size - 1;
            DB::table('sequences')->where('id', $row->id)->update(['last_value' => $end]);
            DB::table('sync_number_blocks')->insert([
                'branch_id' => $branchId, 'device_id' => $deviceId, 'name' => $name,
                'start' => $start, 'end' => $end, 'next' => $start, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['name' => $name, 'start' => $start, 'end' => $end];
        });
    }

    /** StudentService ile aynı başlangıç: yıl + 001 ya da mevcut en büyük numaradan sonrası. */
    private static function initialValue(string $name, int $branchId): int
    {
        if ($name === 'student_no') {
            $max = 0;
            foreach (DB::table('students')->where('branch_id', $branchId)->pluck('student_no') as $no) {
                if (ctype_digit((string) $no)) {
                    $max = max($max, (int) $no);
                }
            }

            return max((int) (now()->year.'001'), $max + 1);
        }

        return 1;
    }
}

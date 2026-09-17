<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Yevmiye fişi yazıcı. Kurallar:
 *  - Her fişte borç toplamı = alacak toplamı (bcmath; ayrıca model kancası ve MariaDB CHECK kısıtı).
 *  - Satır tek taraflıdır (borç YA DA alacak), tutarlar kuruşa yuvarlanmış ve pozitiftir; sıfır satır yazılmaz.
 *  - Kaynak olayı (source_type, source_id, source_event) başına tek fiş: tekrar çağrı mevcut fişi döndürür.
 *  - Kapalı döneme fiş yazılamaz (PeriodLock).
 *  - Fiş değiştirilmez; düzeltme ters fiştir (reverseSource).
 */
class JournalService
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly PeriodLock $periods,
    ) {}

    /**
     * @param list<array{code:string, debit?:string, credit?:string, description?:?string, partner_type?:?string, partner_id?:?int, partner_name?:?string, finance_account_id?:?int}> $lines
     */
    public function post(int $branchId, \DateTimeInterface|string $date, string $description, ?string $sourceType, ?int $sourceId, string $event, array $lines, ?int $reversalOfId = null): JournalEntry
    {
        $date = CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date)->startOfDay();

        if ($sourceType !== null) {
            $existing = JournalEntry::query()->withoutGlobalScopes()->where('branch_id', $branchId)
                ->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_event', $event)->first();
            if ($existing) {
                return $existing;
            }
        }

        $normalized = self::normalizeLines($lines);
        [$debit, $credit] = self::totals($normalized);
        if (bccomp($debit, $credit, 2) !== 0) {
            throw new BusinessRuleException(sprintf('Yevmiye fişi dengesiz (borç %s, alacak %s).', $debit, $credit), 'journal_unbalanced');
        }
        if (! Dec::positive($debit)) {
            throw new BusinessRuleException('Tutarı sıfır olan fiş yazılamaz.', 'journal_empty');
        }

        $accounts = $this->chart->accounts($branchId);
        foreach ($normalized as $l) {
            if (! isset($accounts[$l['code']])) {
                throw new BusinessRuleException("{$l['code']} kodlu hesap hesap planında yok. Muhasebe > Hesap planı ekranından ekleyin.", 'ledger_code_missing');
            }
        }

        $this->periods->assertOpen($branchId, $date);

        $run = function () use ($branchId, $date, $description, $sourceType, $sourceId, $event, $normalized, $debit, $credit, $reversalOfId) {
            $entry = JournalEntry::query()->create([
                'branch_id' => $branchId,
                'entry_no' => Sequence::next('journal', 'YEV', $branchId),
                'entry_date' => $date->toDateString(),
                'description' => mb_substr($description, 0, 300),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_event' => $event,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'reversal_of_id' => $reversalOfId,
                'created_by' => Auth::id(),
            ]);

            foreach ($normalized as $l) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'branch_id' => $branchId,
                    'entry_date' => $date->toDateString(),
                    'ledger_code' => $l['code'],
                    'debit' => $l['debit'],
                    'credit' => $l['credit'],
                    'description' => $l['description'] !== null ? mb_substr($l['description'], 0, 300) : null,
                    'partner_type' => $l['partner_type'],
                    'partner_id' => $l['partner_id'],
                    'partner_name' => $l['partner_name'] !== null ? mb_substr($l['partner_name'], 0, 160) : null,
                    'finance_account_id' => $l['finance_account_id'],
                ]);
            }

            return $entry;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    /**
     * Bir kaynağın ters çevrilmemiş tüm fişlerini TEK ters fişle kapatır (satırlar yer değiştirir).
     * Tekrar çağrılırsa (aynı olay) mevcut ters fiş döner.
     */
    public function reverseSource(int $branchId, string $sourceType, int $sourceId, string $event, \DateTimeInterface|string $date, string $description): ?JournalEntry
    {
        $existing = JournalEntry::query()->withoutGlobalScopes()->where('branch_id', $branchId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_event', $event)->first();
        if ($existing) {
            return $existing;
        }

        $entries = JournalEntry::query()->withoutGlobalScopes()->where('branch_id', $branchId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->whereNull('reversed_at')->whereNull('reversal_of_id')->lockForUpdate()->get();
        if ($entries->isEmpty()) {
            return null;
        }

        $lines = [];
        foreach (JournalLine::query()->whereIn('journal_entry_id', $entries->pluck('id'))->orderBy('id')->get() as $l) {
            $lines[] = [
                'code' => $l->ledger_code,
                'debit' => (string) $l->credit,
                'credit' => (string) $l->debit,
                'description' => 'Ters kayıt: '.($l->description ?? ''),
                'partner_type' => $l->partner_type, 'partner_id' => $l->partner_id, 'partner_name' => $l->partner_name,
                'finance_account_id' => $l->finance_account_id,
            ];
        }

        $reversal = $this->post($branchId, $date, $description, $sourceType, $sourceId, $event, $lines, $entries->first()->id);
        foreach ($entries as $e) {
            $e->forceFill(['reversed_at' => now()])->save();
        }

        return $reversal;
    }

    /** @return list<array{code:string, debit:string, credit:string, description:?string, partner_type:?string, partner_id:?int, partner_name:?string, finance_account_id:?int}> */
    public static function normalizeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $d = Dec::round(Dec::norm($l['debit'] ?? '0'));
            $c = Dec::round(Dec::norm($l['credit'] ?? '0'));
            // Negatif tutar karşı tarafa yazılır; aynı satırda iki taraf netleştirilir.
            $net = bcsub($d, $c, 2);
            if (Dec::isZero($net)) {
                continue;
            }
            $out[] = [
                'code' => (string) $l['code'],
                'debit' => bccomp($net, '0', 2) > 0 ? $net : '0.00',
                'credit' => bccomp($net, '0', 2) < 0 ? bcmul($net, '-1', 2) : '0.00',
                'description' => $l['description'] ?? null,
                'partner_type' => $l['partner_type'] ?? null,
                'partner_id' => $l['partner_id'] ?? null,
                'partner_name' => $l['partner_name'] ?? null,
                'finance_account_id' => $l['finance_account_id'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array{0:string, 1:string} */
    public static function totals(array $normalized): array
    {
        $d = '0.00';
        $c = '0.00';
        foreach ($normalized as $l) {
            $d = bcadd($d, $l['debit'], 2);
            $c = bcadd($c, $l['credit'], 2);
        }

        return [$d, $c];
    }
}

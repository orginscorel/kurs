<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Gelir / gider kaydı. Kayıt + hesap hareketi tek transaction. Kayıt değiştirilemez; düzeltme = iptal (ters kayıt) + yeni kayıt.
 */
class FinanceEntryService
{
    public const DIRECTIONS = ['income' => 'Gelir', 'expense' => 'Gider'];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @param array{direction:string, finance_category_id:int, finance_account_id:int, amount:string, entry_date:string,
     *   description:string, counterparty?:?string, document_no?:?string} $data
     */
    public function record(array $data): FinanceEntry
    {
        $amount = Money::of($data['amount']);
        if (! Money::isPositive($amount)) {
            throw new BusinessRuleException('Tutar sıfırdan büyük olmalı.', 'invalid_amount');
        }
        if (! isset(self::DIRECTIONS[$data['direction']])) {
            throw new BusinessRuleException('Geçersiz kayıt türü.', 'invalid_direction');
        }

        $category = FinanceCategory::query()->findOrFail($data['finance_category_id']);
        if ($category->direction !== $data['direction']) {
            throw new BusinessRuleException('Seçilen kategori bu kayıt türüne ait değil.', 'category_direction_mismatch');
        }
        if ($category->code === 'student_payment') {
            throw new BusinessRuleException('Öğrenci ödemeleri "Tahsilat al" ekranından kaydedilir.', 'student_payment_category');
        }
        $account = FinanceAccount::query()->findOrFail($data['finance_account_id']);
        $date = CarbonImmutable::parse($data['entry_date']);
        if ($date->gt(CarbonImmutable::today())) {
            throw new BusinessRuleException('İleri tarihli gelir/gider kaydedilemez.', 'future_date');
        }

        return DB::transaction(function () use ($data, $amount, $category, $account, $date) {
            $entry = FinanceEntry::query()->create([
                'direction' => $data['direction'],
                'finance_category_id' => $category->id,
                'finance_account_id' => $account->id,
                'amount' => $amount,
                'entry_date' => $date->toDateString(),
                'description' => mb_substr(trim($data['description']), 0, 500),
                'counterparty' => $data['counterparty'] ?? null,
                'document_no' => $data['document_no'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $occurred = $date->isToday() ? CarbonImmutable::now() : $date->setTime(12, 0);
            $this->ledger->post(
                $account->id,
                $data['direction'] === 'income' ? $amount : bcmul($amount, '-1', 2),
                $entry,
                self::DIRECTIONS[$data['direction']].": {$category->name} — ".mb_substr($entry->description, 0, 200),
                $occurred,
            );

            Audit::log("finance_entry.{$data['direction']}_created", sprintf(
                '%s hesabına %s TL %s kaydetti (%s: %s).',
                $account->name, Money::format($amount), mb_strtolower(self::DIRECTIONS[$data['direction']]), $category->name, $entry->description,
            ), $entry);

            return $entry;
        });
    }

    public function void(FinanceEntry $entry, string $reason): FinanceEntry
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('İptal gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }

        return DB::transaction(function () use ($entry, $reason) {
            /** @var FinanceEntry $locked */
            $locked = FinanceEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                throw new BusinessRuleException('Bu kayıt zaten iptal edilmiş.', 'already_voided');
            }
            $linked = DB::table('stock_movements')->where('finance_entry_id', $locked->id)->exists();

            $this->ledger->post(
                $locked->finance_account_id,
                $locked->direction === 'income' ? bcmul((string) $locked->amount, '-1', 2) : (string) $locked->amount,
                $locked,
                'İptal: '.mb_substr($locked->description, 0, 250),
                now(),
            );

            $locked->forceFill(['voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => mb_substr(trim($reason), 0, 300)])->save();

            Audit::log('finance_entry.voided', sprintf(
                '%s TL tutarındaki %s kaydını iptal etti (%s). Gerekçe: %s%s',
                Money::format($locked->amount), mb_strtolower(self::DIRECTIONS[$locked->direction]), $locked->description, $reason,
                $linked ? ' (Stok hareketi etkilenmez; gerekiyorsa stok düzeltmesi yapın.)' : '',
            ), $locked);

            return $locked;
        });
    }

    /** Kod ile kategori; yoksa (eski şubeler) oluşturulur. */
    public function category(string $direction, string $code, string $name): FinanceCategory
    {
        return FinanceCategory::query()->firstOrCreate(['direction' => $direction, 'code' => $code], ['name' => $name, 'is_system' => false]);
    }
}

<?php

namespace App\Services\Finance;

use App\Services\Finance\FinanceAudit;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Student;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Kitap / materyal stoğu. products.stock önbellektir; kaynak stock_movements. İkisi aynı transaction'da, ürün satırı kilitli.
 */
class InventoryService
{
    public const KINDS = ['purchase' => 'Stok girişi', 'delivery' => 'Öğrenciye teslim', 'return' => 'İade', 'adjustment' => 'Sayım düzeltmesi'];

    public function __construct(private readonly FinanceEntryService $entries) {}

    /** @param array{quantity:int, unit_price?:?string, note?:?string, expense_account_id?:?int} $data */
    public function stockIn(Product $product, array $data): StockMovement
    {
        $qty = (int) $data['quantity'];
        if ($qty < 1) {
            throw new BusinessRuleException('Adet en az 1 olmalı.', 'invalid_quantity');
        }
        $unit = isset($data['unit_price']) && $data['unit_price'] !== '' ? Money::of($data['unit_price']) : null;

        return DB::transaction(function () use ($product, $data, $qty, $unit) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $entryId = null;

            if (! empty($data['expense_account_id'])) {
                if ($unit === null || ! Money::isPositive($unit)) {
                    throw new BusinessRuleException('Gider kaydı için birim alış fiyatı girin.', 'unit_price_required');
                }
                $entryId = $this->entries->record([
                    'direction' => 'expense',
                    'finance_category_id' => $this->entries->category('expense', 'book_purchase', 'Kitap alımı')->id,
                    'finance_account_id' => (int) $data['expense_account_id'],
                    'amount' => bcmul($unit, (string) $qty, 2),
                    'entry_date' => CarbonImmutable::today()->toDateString(),
                    'description' => "{$locked->name} × {$qty} stok alımı",
                    'counterparty' => $locked->publisher,
                ])->id;
            }

            $movement = $this->move($locked, $qty, 'purchase', null, $unit, $data['note'] ?? null, $entryId);

            FinanceAudit::log('inventory.stock_in', sprintf('%s ürününe %d adet stok girişi yaptı (yeni stok %d).', $locked->name, $qty, $locked->stock), $locked);

            return $movement;
        });
    }

    /** @param array{student_id:int, quantity:int, note?:?string, charge?:bool, unit_price?:?string, income_account_id?:?int} $data */
    public function deliver(Product $product, Student $student, array $data): StockMovement
    {
        $qty = (int) $data['quantity'];
        if ($qty < 1) {
            throw new BusinessRuleException('Adet en az 1 olmalı.', 'invalid_quantity');
        }

        return DB::transaction(function () use ($product, $student, $data, $qty) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($locked->stock < $qty) {
                throw new BusinessRuleException("{$locked->name} için yeterli stok yok (stok: {$locked->stock}).", 'insufficient_stock', ['stock' => $locked->stock]);
            }

            $unit = null;
            $entryId = null;
            if (! empty($data['charge'])) {
                $unit = Money::of($data['unit_price'] ?? $locked->sale_price);
                if (! Money::isPositive($unit)) {
                    throw new BusinessRuleException('Satış için birim fiyat girin.', 'unit_price_required');
                }
                if (empty($data['income_account_id'])) {
                    throw new BusinessRuleException('Satış tutarının gireceği hesabı seçin.', 'account_required');
                }
                $entryId = $this->entries->record([
                    'direction' => 'income',
                    'finance_category_id' => $this->entries->category('income', 'book_sale', 'Kitap satışı')->id,
                    'finance_account_id' => (int) $data['income_account_id'],
                    'amount' => bcmul($unit, (string) $qty, 2),
                    'entry_date' => CarbonImmutable::today()->toDateString(),
                    'description' => "{$locked->name} × {$qty} — {$student->full_name}",
                    'counterparty' => $student->full_name,
                ])->id;
            }

            $movement = $this->move($locked, -$qty, 'delivery', $student->id, $unit, $data['note'] ?? null, $entryId);

            FinanceAudit::log('inventory.delivered', sprintf(
                '%s öğrencisine %d adet %s teslim etti%s (kalan stok %d).',
                $student->full_name, $qty, $locked->name, $entryId ? sprintf(', %s TL satış geliri kaydedildi', Money::format(bcmul($unit, (string) $qty, 2))) : '', $locked->stock,
            ), $locked);

            return $movement;
        });
    }

    public function returnFromStudent(Product $product, Student $student, int $qty, ?string $note): StockMovement
    {
        if ($qty < 1) {
            throw new BusinessRuleException('Adet en az 1 olmalı.', 'invalid_quantity');
        }

        return DB::transaction(function () use ($product, $student, $qty, $note) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $delivered = -1 * (int) StockMovement::query()->where('product_id', $locked->id)->where('student_id', $student->id)->whereIn('kind', ['delivery', 'return'])->sum('quantity');
            if ($qty > $delivered) {
                throw new BusinessRuleException("Bu öğrenciye teslim edilen net adet {$delivered}; daha fazlası iade alınamaz.", 'return_exceeds_delivery');
            }
            $movement = $this->move($locked, $qty, 'return', $student->id, null, $note, null);
            FinanceAudit::log('inventory.returned', sprintf('%s öğrencisinden %d adet %s iade aldı.', $student->full_name, $qty, $locked->name), $locked);

            return $movement;
        });
    }

    public function adjust(Product $product, int $counted, string $note): StockMovement
    {
        if ($counted < 0) {
            throw new BusinessRuleException('Sayılan adet negatif olamaz.', 'invalid_quantity');
        }
        if (mb_strlen(trim($note)) < 3) {
            throw new BusinessRuleException('Düzeltme için açıklama girin.', 'reason_required');
        }

        return DB::transaction(function () use ($product, $counted, $note) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $delta = $counted - (int) $locked->stock;
            if ($delta === 0) {
                throw new BusinessRuleException('Sayılan adet sistemdeki stokla aynı.', 'no_difference');
            }
            $old = (int) $locked->stock;
            $movement = $this->move($locked, $delta, 'adjustment', null, null, $note, null);
            FinanceAudit::log('inventory.adjusted', sprintf('%s stoğunu sayım ile %d → %d olarak düzeltti. Not: %s', $locked->name, $old, $counted, $note), $locked);

            return $movement;
        });
    }

    private function move(Product $locked, int $signedQty, string $kind, ?int $studentId, ?string $unit, ?string $note, ?int $entryId): StockMovement
    {
        $movement = new StockMovement();
        $movement->forceFill([
            'branch_id' => $locked->branch_id,
            'product_id' => $locked->id,
            'quantity' => $signedQty,
            'kind' => $kind,
            'student_id' => $studentId,
            'unit_price' => $unit,
            'finance_entry_id' => $entryId,
            'note' => $note ? mb_substr($note, 0, 300) : null,
            'created_by' => Auth::id(),
        ])->save();

        $locked->forceFill(['stock' => (int) $locked->stock + $signedQty])->save();

        return $movement;
    }
}

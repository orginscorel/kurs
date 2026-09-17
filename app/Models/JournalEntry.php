<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Yevmiye fişi. DEĞİŞTİRİLMEZ ve SİLİNMEZ; düzeltme ters fişle yapılır.
 * Yalnız "reversed_at" (ters fişi kesildi işareti) bir kez doldurulabilir.
 */
class JournalEntry extends Model
{
    use BelongsToBranch;

    public const EVENTS = [
        'payment' => 'Tahsilat', 'payment_void' => 'Tahsilat iptali',
        'entry' => 'Gelir/gider', 'entry_void' => 'Gelir/gider iptali',
        'transfer' => 'Hesaplar arası transfer', 'transfer_void' => 'Transfer iptali',
        'opening' => 'Açılış bakiyesi', 'adjustment' => 'Sayım farkı',
        'invoice' => 'Fatura', 'invoice_link' => 'Fatura-tahsilat mahsubu', 'invoice_cancel' => 'Fatura iptali',
        'refund' => 'İade', 'refund_void' => 'İade iptali', 'manual' => 'Elle fiş',
    ];

    private const MUTABLE_ONCE = ['reversed_at', 'updated_at'];

    protected $fillable = [
        'branch_id', 'entry_no', 'entry_date', 'description', 'source_type', 'source_id', 'source_event',
        'total_debit', 'total_credit', 'reversal_of_id', 'created_by',
    ];

    protected $casts = ['entry_date' => 'date', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'reversed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (JournalEntry $e) {
            if (bccomp((string) $e->total_debit, (string) $e->total_credit, 2) !== 0 || bccomp((string) $e->total_debit, '0', 2) <= 0) {
                throw new LogicException('Yevmiye fişinde borç ve alacak toplamı eşit ve sıfırdan büyük olmalıdır.');
            }
        });
        static::updating(function (JournalEntry $e) {
            if (array_diff(array_keys($e->getDirty()), self::MUTABLE_ONCE) !== [] || $e->getOriginal('reversed_at') !== null) {
                throw new LogicException('Yevmiye fişleri değiştirilemez; ters fiş kesin.');
            }
        });
        static::deleting(fn () => throw new LogicException('Yevmiye fişleri silinemez.'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

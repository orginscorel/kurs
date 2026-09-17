<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Fatura / e-Arşiv taslağı. Kesilmiş (issued) fatura DEĞİŞTİRİLMEZ: yalnız iptal alanları
 * ve entegratör durumu yazılabilir. Düzeltme = iptal ya da iade faturası.
 */
class Invoice extends Model
{
    use BelongsToBranch;

    public const STATUSES = ['draft' => 'Taslak', 'issued' => 'Kesildi', 'cancelled' => 'İptal'];

    public const KINDS = ['sales' => 'Satış', 'return' => 'İade'];

    public const DOCUMENT_TYPES = ['e_archive' => 'e-Arşiv', 'e_invoice' => 'e-Fatura', 'paper' => 'Kâğıt'];

    public const BUYER_TYPES = ['guardian' => 'Veli', 'student' => 'Öğrenci', 'institution' => 'Kurum', 'other' => 'Diğer'];

    private const MUTABLE_AFTER_ISSUE = ['status', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'integrator_status', 'ettn', 'updated_at'];

    protected $fillable = [
        'branch_id', 'kind', 'document_type', 'issue_date', 'buyer_type', 'buyer_id', 'buyer_name', 'buyer_tax_id',
        'buyer_tax_id_last4', 'buyer_tax_office', 'buyer_address', 'buyer_email', 'buyer_phone', 'student_id', 'enrollment_id',
        'related_invoice_id', 'prices_include_vat', 'notes', 'idempotency_key', 'created_by',
    ];

    protected $hidden = ['buyer_tax_id'];

    protected $casts = [
        'issue_date' => 'date', 'issued_at' => 'datetime', 'cancelled_at' => 'datetime', 'prices_include_vat' => 'boolean',
        'buyer_tax_id' => \App\Casts\DataEncrypted::class,   // kurum veri anahtarı (docs/SYNC.md)
        'gross_total' => 'decimal:2', 'discount_total' => 'decimal:2', 'net_total' => 'decimal:2', 'vat_total' => 'decimal:2',
        'withholding_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'payable_total' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $i) {
            if ($i->getOriginal('status') === 'cancelled') {
                throw new LogicException('İptal edilmiş fatura değiştirilemez.');
            }
            if ($i->getOriginal('status') === 'issued' && array_diff(array_keys($i->getDirty()), self::MUTABLE_AFTER_ISSUE) !== []) {
                throw new LogicException('Kesilmiş fatura değiştirilemez; iptal edin ya da iade faturası düzenleyin.');
            }
        });
        static::deleting(function (Invoice $i) {
            if ($i->getOriginal('status') !== 'draft') {
                throw new LogicException('Yalnız taslak fatura silinebilir.');
            }
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sequence');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'invoice_payments')->withPivot('amount')->withTimestamps();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    public function label(): string
    {
        return $this->invoice_no ?? ('Taslak #'.$this->id);
    }
}

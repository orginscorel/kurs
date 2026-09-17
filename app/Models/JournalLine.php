<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id', 'branch_id', 'entry_date', 'ledger_code', 'debit', 'credit', 'description',
        'partner_type', 'partner_id', 'partner_name', 'finance_account_id',
    ];

    protected $casts = ['entry_date' => 'date', 'debit' => 'decimal:2', 'credit' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Yevmiye satırları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Yevmiye satırları silinemez.'));
    }
}

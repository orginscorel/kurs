<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'sequence', 'description', 'quantity', 'unit', 'unit_price', 'discount_rate', 'discount_amount',
        'vat_rate', 'withholding_tenths', 'gross_amount', 'net_amount', 'vat_amount', 'withholding_amount', 'total_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_rate' => 'decimal:2', 'discount_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2', 'withholding_tenths' => 'integer', 'gross_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
    ];
}

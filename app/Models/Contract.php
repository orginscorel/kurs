<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = ['enrollment_id', 'contract_no', 'body_snapshot', 'pdf_path', 'signed_at', 'signed_by_name'];

    protected $casts = ['signed_at' => 'datetime'];

    protected static function booted(): void
    {
        // İmzalanmış sözleşme metni değiştirilemez.
        static::updating(function (Contract $c) {
            if ($c->getOriginal('signed_at') && $c->isDirty('body_snapshot')) {
                throw new \LogicException('İmzalanmış sözleşmenin metni değiştirilemez.');
            }
        });
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}

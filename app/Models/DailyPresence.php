<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPresence extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'student_id', 'date', 'first_entry_at', 'last_exit_at', 'is_inside', 'minutes_inside', 'last_event_at'];

    protected $casts = [
        'date' => 'date',
        'first_entry_at' => 'datetime',
        'last_exit_at' => 'datetime',
        'last_event_at' => 'datetime',
        'is_inside' => 'boolean',
    ];

    /**
     * Tarih HER ZAMAN 'Y-m-d' olarak yazılır.
     *
     * Laravel'in 'date' dönüşümü veritabanına 'Y-m-d H:i:s' yazar. MySQL bunu DATE sütununda
     * kırpar, ama YEREL DÜĞÜMÜN SQLite'ı metni olduğu gibi saklar; o zaman aynı günün satırı
     * `where('date', '2026-09-17')` ile bir daha BULUNAMAZ ve ikinci giriş/çıkış olayında
     * "unique constraint failed" hatası alınır. Bu mutasyon iki tarafta da aynı biçimi yazar.
     */
    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = $value === null || $value === '' ? null : CarbonImmutable::parse($value)->toDateString();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}

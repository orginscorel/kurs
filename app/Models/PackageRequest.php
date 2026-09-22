<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Öğrenci/veli portalından gelen paket ekleme/yükseltme ya da koçluk talebi.
 * Online ödeme YOKTUR; talep yalnız kayda düşer, yönetici kesinleştirir (onay/ret).
 * kind='package' → package_id dolu; kind='coaching' → koçluk add-on talebi (package_id null).
 */
class PackageRequest extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const KINDS = ['package' => 'Paket', 'coaching' => 'Koçluk'];

    public const STATUSES = ['pending' => 'Bekliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi'];

    protected $fillable = [
        'branch_id', 'student_id', 'package_id', 'kind', 'note', 'status',
        'requested_by', 'handled_by', 'handled_at', 'decision_note',
    ];

    protected $casts = ['handled_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(EducationPackage::class, 'package_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Şube izolasyonu: sorgular otomatik olarak aktif şubeye daraltılır, yeni
 * kayıtlara branch_id otomatik yazılır. Bir şubenin verisi başka şubeden
 * yanlışlıkla görünemez. Bağlam yoksa (konsol/seed) filtre uygulanmaz.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $branchId = app(BranchContext::class)->id();

            if ($branchId !== null) {
                $builder->where($builder->getModel()->getTable().'.branch_id', $branchId);
            }
        });

        static::creating(function ($model) {
            if (empty($model->branch_id)) {
                $model->branch_id = app(BranchContext::class)->id();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

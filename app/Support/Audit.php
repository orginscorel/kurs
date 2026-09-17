<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Denetim kaydı yazıcı. Açıklama insan okunur olmalı:
 * "Ayşe Demir, Ahmet Yılmaz öğrencisine 5.000,00 TL tahsilat kaydetti."
 */
class Audit
{
    public static function log(string $action, string $description, ?Model $subject = null, ?array $changes = null): void
    {
        $user = Auth::user();

        AuditLog::query()->create([
            'branch_id' => app(BranchContext::class)->id(),
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr(($user ? $user->name.', ' : 'Sistem, ').$description, 0, 500),
            'changes' => $changes,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? 'console' : mb_substr((string) Request::userAgent(), 0, 255),
        ]);
    }

    /** Model değişikliğinden yalnızca değişen alanların önce/sonra farkı. */
    public static function diff(Model $model, array $hidden = []): array
    {
        $after = collect($model->getChanges())->except(array_merge(['updated_at'], $hidden));

        return [
            'before' => $after->keys()->mapWithKeys(fn ($k) => [$k => $model->getOriginal($k)])->all(),
            'after' => $after->all(),
        ];
    }
}

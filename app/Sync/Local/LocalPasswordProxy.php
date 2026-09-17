<?php

namespace App\Sync\Local;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Sync\SyncContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Yerel kurulumda parola değişikliği: YALNIZ çevrimiçi, sunucu üzerinden.
 *
 * Neden kuyruk değil? Kuyruğa alınan bir parola değişikliği ya düz parolayı ya da mevcut parolayı cihazda
 * bekletir; çalınan/ele geçirilen bir cihazın veritabanından başka personelin web parolası değiştirilebilirdi.
 * Burada sunucu mevcut parolayı doğrular (hız sınırlı), yalnız cihaz şubesindeki etkin personel hesabı;
 * başarılıysa yeni özet hemen yerele yazılır (çevrimdışı giriş için), diğer cihazlara normal eşitlemeyle iner.
 */
class LocalPasswordProxy
{
    public function __construct(private readonly SyncClient $client, private readonly SyncContext $context) {}

    public function change(User $user, string $current, string $new): void
    {
        if (! $this->client->isPaired()) {
            throw new BusinessRuleException('Bu kurulum sunucuyla eşleştirilmemiş; parola değiştirilemez.', 'device_not_paired', [], 409);
        }
        $uuid = (string) DB::table('users')->where('id', $user->id)->value('uuid');
        try {
            $res = $this->client->changePassword($uuid, $current, $new);
        } catch (SyncHttpException $e) {
            if ($e->isOffline()) {
                throw new BusinessRuleException('İnternet bağlantısı yok. Güvenlik gereği parola yalnız çevrimiçiyken değiştirilir (cihazda bekletilmez); bağlantı gelince tekrar deneyin.', 'offline_not_supported', [], 409);
            }
            if ($e->status === 422) {
                throw ValidationException::withMessages([str_contains($e->getMessage(), 'Mevcut') ? 'current_password' : 'password' => $e->getMessage()]);
            }
            throw new BusinessRuleException($e->getMessage(), $e->errorCode ?: 'sync_error', [], $e->status >= 400 && $e->status < 500 ? $e->status : 409);
        }

        $this->context->applying(fn () => DB::table('users')->where('id', $user->id)->update([
            'password' => (string) $res['password_hash'],
            'must_change_password' => false,
            'password_changed_at' => now(),
            'updated_at' => now(),
        ]));
    }
}

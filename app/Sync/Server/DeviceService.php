<?php

namespace App\Sync\Server;

use App\Exceptions\BusinessRuleException;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\Audit;
use App\Support\BranchContext;
use App\Support\Settings;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cihaz eşleştirme: kurum kodu + personel kullanıcı adı/parolası → cihaz jetonu (Sanctum, yetenek 'sync').
 * Öğrenci/veli/yalnız-öğretmen-portalı hesapları eşleştiremez. Cihaz iptal edilince jeton silinir.
 */
class DeviceService
{
    public const ABILITY = 'sync';

    public function pairingCode(int $branchId, bool $rotate = false): string
    {
        $code = Settings::get('sync.pairing_code', null, $branchId);
        if (! $code || $rotate) {
            $code = strtoupper(Str::random(4).'-'.Str::random(4));
            $code = strtr($code, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'K', 'L' => 'M']);
            Settings::put('sync', ['pairing_code' => $code], $branchId);
        }

        return (string) $code;
    }

    public function branchForCode(string $code): ?int
    {
        $code = strtoupper(trim($code));
        foreach (DB::table('settings')->where('group', 'sync')->where('key', 'pairing_code')->get(['branch_id', 'value']) as $row) {
            if (hash_equals((string) json_decode((string) $row->value, true), $code)) {
                return (int) $row->branch_id;
            }
        }

        return null;
    }

    /**
     * @param array{code: string, login: string, password: string, device_name: string, platform: string, app_version?: ?string, public_key?: ?string, mode?: ?string} $data
     * @return array<string, mixed>
     */
    public function pair(array $data, Request $request): array
    {
        $branchId = $this->branchForCode($data['code']);
        $user = User::query()->where('username', $data['login'])->orWhere('email', $data['login'])->first();
        $valid = Hash::check($data['password'], $user?->password ?? '$2y$12$'.str_repeat('a', 53));

        LoginEvent::query()->create([
            'user_id' => $user?->id, 'username' => mb_substr($data['login'], 0, 120),
            'successful' => $valid && $user?->is_active && $branchId !== null, 'channel' => 'sync',
            'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        if (! $branchId) {
            throw ValidationException::withMessages(['code' => 'Kurum kodu geçersiz.']);
        }
        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['login' => 'Kullanıcı adı veya parola hatalı.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'Hesabınız pasif durumda.']);
        }
        // Portal (öğrenci/veli/öğretmen portalı) hesapları masaüstü eşitlemesine giremez
        if (! $user->isStaff()) {
            throw new BusinessRuleException('Portal hesaplarıyla cihaz eşleştirilemez. Personel hesabıyla giriş yapın.', 'portal_account', [], 403);
        }
        if ((int) $user->branch_id !== $branchId) {
            throw new BusinessRuleException('Bu kullanıcı kurum koduyla eşleşen şubede değil.', 'branch_mismatch', [], 403);
        }
        if (! $user->hasRole('super-admin') && ! $user->can('sync.use')) {
            throw new BusinessRuleException('Hesabınızın masaüstü/mobil eşitleme yetkisi yok.', 'forbidden', [], 403);
        }
        $publicKey = $data['public_key'] ?? null;
        if ($publicKey !== null && strlen((string) base64_decode($publicKey, true)) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw ValidationException::withMessages(['public_key' => 'Cihaz açık anahtarı geçersiz.']);
        }

        return app(BranchContext::class)->run($branchId, function () use ($data, $user, $branchId, $publicKey, $request) {
            return DB::transaction(function () use ($data, $user, $branchId, $publicKey, $request) {
                $n = 1 + (int) DB::table('sync_devices')->where('branch_id', $branchId)->lockForUpdate()->count();
                while (DB::table('sync_devices')->where('branch_id', $branchId)->where('code', 'D'.$n)->exists()) {
                    $n++;
                }
                $uuid = (string) Str::uuid7();
                $token = $user->createToken('sync:'.$uuid, [self::ABILITY]);

                $device = SyncDevice::query()->create([
                    'uuid' => $uuid, 'branch_id' => $branchId, 'user_id' => $user->id, 'code' => 'D'.$n,
                    'name' => mb_substr($data['device_name'], 0, 120), 'platform' => $data['platform'],
                    'mode' => $data['mode'] ?? 'desktop', 'app_version' => $data['app_version'] ?? null,
                    'token_id' => $token->accessToken->id, 'public_key' => $publicKey, 'status' => 'active',
                    'paired_at' => now(), 'last_seen_at' => now(), 'last_ip' => $request->ip(),
                ]);

                Audit::log('sync.device_paired', sprintf('"%s" cihazını (%s, %s) eşleştirdi.', $device->name, $device->code, $device->platform), null);

                return [
                    'token' => $token->plainTextToken,
                    'device' => $this->present($device),
                    'user' => ['uuid' => DB::table('users')->where('id', $user->id)->value('uuid'), 'name' => $user->name],
                    'branch' => ['uuid' => DB::table('branches')->where('id', $branchId)->value('uuid'), 'id' => $branchId],
                    'server_time' => now()->toIso8601String(),
                    'key_bundle' => $this->keyBundle($device),
                ];
            });
        });
    }

    /**
     * Kurum veri anahtarı paketi (TC gibi şifreli alanlar için). Cihazın X25519 açık anahtarıyla mühürlenir
     * (sodium sealed box); yalnız cihazın gizli anahtarı açar. APP_KEY paylaşımı açıkça izin verilmedikçe YOK.
     *
     * @return array{sealed: string, alg: string, kind: string}|array{sealed: null, reason: string}
     */
    public function keyBundle(SyncDevice $device): array
    {
        if (! $device->public_key) {
            return ['sealed' => null, 'reason' => 'Cihaz açık anahtar göndermedi.'];
        }
        $dataKey = config('kurs.data_key');
        $kind = 'data_key';
        if (! $dataKey && config('sync.share_app_key')) {
            $dataKey = config('app.key');
            $kind = 'app_key';
        }
        if (! $dataKey) {
            return ['sealed' => null, 'reason' => 'Kurum veri anahtarı (KURS_DATA_KEY) tanımlı değil; şifreli alanlar cihaza açık inmez.'];
        }
        $payload = json_encode(['key' => $dataKey, 'cipher' => config('app.cipher'), 'kind' => $kind, 'issued_at' => now()->toIso8601String()]);
        $sealed = sodium_crypto_box_seal($payload, base64_decode($device->public_key));
        $device->forceFill(['key_issued_at' => now()])->save();
        Audit::log('sync.key_issued', sprintf('"%s" cihazına kurum veri anahtarı paketi verildi.', $device->name));

        return ['sealed' => base64_encode($sealed), 'alg' => 'x25519-xsalsa20-poly1305 (sealed box)', 'kind' => $kind];
    }

    public function revoke(SyncDevice $device, ?User $by): SyncDevice
    {
        return DB::transaction(function () use ($device, $by) {
            if ($device->token_id) {
                DB::table('personal_access_tokens')->where('id', $device->token_id)->delete();
            }
            $device->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $by?->id, 'token_id' => null])->save();
            Audit::log('sync.device_revoked', sprintf('"%s" (%s) cihazının eşitleme erişimini iptal etti.', $device->name, $device->code));

            return $device;
        });
    }

    /**
     * Yerel kurulumdan (çevrimiçiyken) parola değişikliği. Kuyruğa alınmaz: parola/özet cihazda bekletilmez.
     * Sunucu mevcut parolayı doğrular (çalınmış cihazla başka hesabın parolası değiştirilemez), yalnız cihazın
     * şubesindeki etkin personel hesabı. Diğer web oturumları ve eşitleme dışı jetonlar kapatılır.
     *
     * @return array{password_hash: string, password_changed_at: string}
     */
    public function changePassword(SyncDevice $device, string $userUuid, string $current, string $new): array
    {
        $key = 'sync-password:'.$device->id.':'.$userUuid;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            throw new BusinessRuleException('Çok fazla hatalı deneme. Birkaç dakika sonra tekrar deneyin.', 'too_many_attempts', [], 429);
        }
        /** @var User|null $user */
        $user = User::query()->where('uuid', $userUuid)->first();
        if (! $user || ! $user->isStaff() || (int) $user->branch_id !== (int) $device->branch_id || ! $user->is_active) {
            throw new BusinessRuleException('Bu hesabın parolası bu cihazdan değiştirilemez.', 'forbidden', [], 403);
        }
        if (! Hash::check($current, (string) $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['current_password' => 'Mevcut parola hatalı.']);
        }
        if (Hash::check($new, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'Yeni parola mevcut paroladan farklı olmalı.']);
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);

        return DB::transaction(function () use ($user, $new, $device) {
            $user->forceFill(['password' => $new, 'must_change_password' => false, 'initial_password' => null, 'password_changed_at' => now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->where('name', 'not like', 'sync:%')->delete();
            Audit::log('auth.password_changed', sprintf('parolasını değiştirdi ("%s" yerel kurulumundan).', $device->name), $user);

            return ['password_hash' => (string) $user->getAuthPassword(), 'password_changed_at' => $user->password_changed_at->toIso8601String()];
        });
    }

    /** @return array<string, mixed> */
    public function present(SyncDevice $d): array
    {
        return [
            'id' => $d->id,
            'uuid' => $d->uuid,
            'code' => $d->code,
            'name' => $d->name,
            'platform' => $d->platform,
            'platform_label' => SyncDevice::PLATFORMS[$d->platform] ?? $d->platform,
            'mode' => $d->mode,
            'app_version' => $d->app_version,
            'status' => $d->status,
            'user' => $d->relationLoaded('user') || $d->user_id ? ['id' => $d->user_id, 'name' => $d->user?->name] : null,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'last_push_at' => $d->last_push_at?->toIso8601String(),
            'last_pull_at' => $d->last_pull_at?->toIso8601String(),
            'last_cursor' => (int) $d->last_cursor,
            'pending_reported' => (int) $d->pending_reported,
            'rejected_total' => (int) $d->rejected_total,
            'behind' => max(0, (int) (DB::table('sync_changes')->max('id') ?? 0) - (int) $d->last_cursor),
            'key_issued_at' => $d->key_issued_at?->toIso8601String(),
            'paired_at' => $d->paired_at?->toIso8601String(),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'open_conflicts' => SyncConflict::query()->where('device_id', $d->id)->where('status', 'open')->count(),
        ];
    }
}

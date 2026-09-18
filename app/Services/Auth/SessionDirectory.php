<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\BranchContext;
use App\Sync\Models\SyncDevice;
use App\Sync\Server\DeviceSessionService;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * AÇIK OTURUMLAR VE CİHAZLAR — tek liste (sunucu düğümü).
 *
 *  web:<sha256>   Tarayıcı oturumu (sessions tablosu). Kimlik ham oturum kimliğinin özetidir.
 *  app:<sha256>   Masaüstü uygulaması (Mac) yerel oturumu; cihazın raporundan (sync_device_sessions).
 *                 Kapatma bir istek kaydı açar; cihaz bağlanınca uygular ("kapatılıyor").
 *  token:<id>     Mobil uygulama jetonu. Eşitleme cihaz jetonları ('sync:*') burada LİSTELENMEZ
 *                 (onlar Ayarlar › Bağlı masaüstü cihazlar'dan yönetilir; silinirse eşitleme kopar).
 *
 * Ham oturum kimliği hiçbir yanıtta yer almaz.
 */
class SessionDirectory
{
    public function __construct(private readonly DeviceSessionService $devices) {}

    /**
     * @param  array{web?: ?string, token?: ?int, app?: ?string}  $current  isteği yapan oturum (işaretlenir, "diğerleri"nden hariç)
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(User $user, array $current = []): Collection
    {
        $web = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(fn ($s) => [
                'id' => 'web:'.hash('sha256', $s->id),
                'kind' => 'web',
                'kind_label' => 'Tarayıcı',
                'device' => self::describeAgent((string) $s->user_agent),
                'detail' => null,
                'ip_address' => $s->ip_address,
                'last_active_at' => date(DATE_ATOM, (int) $s->last_activity),
                'is_current' => ($current['web'] ?? null) !== null && $s->id === $current['web'],
                'status' => 'active',
            ]);

        $app = $this->devices->rowsForUser($user->id)->map(fn ($r) => self::presentApp($r, $current['app'] ?? null));

        $mobile = $user->tokens()->where('name', 'not like', 'sync:%')->orderByDesc('last_used_at')->get()->map(fn ($t) => [
            'id' => 'token:'.$t->id,
            'kind' => 'mobile',
            'kind_label' => 'Mobil uygulama',
            'device' => $t->name,
            'detail' => null,
            'ip_address' => null,
            'last_active_at' => $t->last_used_at?->toAtomString(),
            'is_current' => ($current['token'] ?? null) !== null && (int) $t->id === (int) $current['token'],
            'status' => 'active',
        ]);

        return self::sort($web->concat($app)->concat($mobile));
    }

    /** @return array<string, mixed> */
    public static function presentApp(object $r, ?string $currentHash = null): array
    {
        $platform = SyncDevice::PLATFORMS[$r->platform] ?? $r->platform;

        return [
            'id' => 'app:'.$r->session_hash,
            'kind' => 'app',
            'kind_label' => ($r->platform === 'macos' ? 'Mac' : $platform).' uygulaması',
            'device' => $r->device_name,
            'device_code' => $r->device_code,
            'detail' => trim($platform.($r->app_version ? ' · v'.$r->app_version : '')),
            'ip_address' => $r->last_ip,
            'last_active_at' => $r->last_activity_at ? Carbon::parse($r->last_activity_at)->toIso8601String() : null,
            'device_last_seen_at' => $r->last_seen_at ? Carbon::parse($r->last_seen_at)->toIso8601String() : null,
            'is_current' => $currentHash !== null && hash_equals((string) $r->session_hash, strtolower($currentHash)),
            'status' => ((int) $r->closing) > 0 ? 'closing' : 'active',
        ];
    }

    public static function sort(Collection $rows): Collection
    {
        return $rows->sortBy([
            fn ($a, $b) => (int) $b['is_current'] <=> (int) $a['is_current'],
            fn ($a, $b) => strcmp((string) $b['last_active_at'], (string) $a['last_active_at']),
        ])->values();
    }

    /**
     * Tek oturumu kapat. $owner oturumun sahibidir; $actor işlemi yapan (kendisi ya da yönetici).
     *
     * @param  array{web?: ?string, token?: ?int, app?: ?string}  $current
     */
    public function revoke(User $owner, string $id, User $actor, array $current = [], ?SyncDevice $via = null): string
    {
        if (str_starts_with($id, 'token:')) {
            $token = $owner->tokens()->whereKey((int) substr($id, 6))->where('name', 'not like', 'sync:%')->first();
            if (! $token) {
                throw new BusinessRuleException('Oturum bulunamadı ya da zaten kapatılmış.', 'not_found', [], 404);
            }
            if ((int) $token->id === (int) ($current['token'] ?? 0)) {
                throw new BusinessRuleException('Kullandığınız oturumu buradan kapatamazsınız; çıkış yapın.', 'current_session', [], 422);
            }
            $token->delete();
            $this->audit($actor, $owner, 'auth.session_revoked', sprintf('"%s" mobil uygulama oturumunu kapattı', $token->name), $via);

            return 'Oturum kapatıldı.';
        }

        if (str_starts_with($id, 'web:')) {
            $hash = substr($id, 4);
            $sessions = DB::table('sessions')->where('user_id', $owner->id)->get(['id', 'user_agent'])
                ->filter(fn ($s) => hash_equals(hash('sha256', $s->id), $hash));
            if ($sessions->isEmpty()) {
                throw new BusinessRuleException('Oturum bulunamadı ya da zaten kapatılmış.', 'not_found', [], 404);
            }
            foreach ($sessions as $s) {
                if (($current['web'] ?? null) === $s->id) {
                    throw new BusinessRuleException('Kullandığınız oturumu buradan kapatamazsınız; çıkış yapın.', 'current_session', [], 422);
                }
                DB::table('sessions')->where('id', $s->id)->delete();
            }
            $this->cycleRememberToken($owner, $current);
            $this->audit($actor, $owner, 'auth.session_revoked', sprintf('tarayıcı oturumunu (%s) kapattı', self::describeAgent((string) $sessions->first()->user_agent)), $via);

            return 'Oturum kapatıldı.';
        }

        if (str_starts_with($id, 'app:')) {
            $row = $this->devices->findForUser($owner->id, substr($id, 4));
            if (! $row) {
                throw new BusinessRuleException('Oturum bulunamadı ya da zaten kapatılmış.', 'not_found', [], 404);
            }
            if (($current['app'] ?? null) !== null && hash_equals((string) $row->session_hash, strtolower((string) $current['app']))) {
                throw new BusinessRuleException('Kullandığınız oturumu buradan kapatamazsınız; çıkış yapın.', 'current_session', [], 422);
            }
            $this->devices->requestRevoke($row, $actor);
            $this->audit($actor, $owner, 'auth.session_revoked', sprintf('"%s" (%s) cihazındaki uygulama oturumunu kapattı', $row->device_name, $row->device_code), $via);

            return self::appMessage($row);
        }

        throw new BusinessRuleException('Geçersiz oturum kimliği.', 'invalid_session_id', [], 422);
    }

    /**
     * Mevcut oturum dışındaki TÜM oturumlar (tarayıcı + uygulama + mobil jeton).
     *
     * @param  array{web?: ?string, token?: ?int, app?: ?string}  $current
     * @return array{web: int, app: int, mobile: int, message: string}
     */
    public function revokeOthers(User $owner, User $actor, array $current = [], ?SyncDevice $via = null): array
    {
        $web = DB::table('sessions')->where('user_id', $owner->id)
            ->when($current['web'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
        if ($web > 0) {
            $this->cycleRememberToken($owner, $current);
        }
        $mobile = $owner->tokens()->where('name', 'not like', 'sync:%')
            ->when($current['token'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))->delete();

        $app = 0;
        foreach ($this->devices->rowsForUser($owner->id) as $row) {
            if (($current['app'] ?? null) !== null && hash_equals((string) $row->session_hash, strtolower((string) $current['app']))) {
                continue;
            }
            if (! (int) $row->closing) {
                $this->devices->requestRevoke($row, $actor);
            }
            $app++;
        }

        if ($web + $app + $mobile > 0) {
            $this->audit($actor, $owner, 'auth.sessions_revoked_all',
                sprintf('%s oturumlarını kapattı (%d tarayıcı, %d uygulama, %d mobil)', (int) $actor->id === (int) $owner->id ? 'diğer tüm' : 'tüm', $web, $app, $mobile), $via);
        }

        $msg = $web + $app + $mobile === 0 ? 'Kapatılacak başka oturum yok.' : 'Diğer oturumlar kapatıldı.';
        if ($app > 0) {
            $msg .= " Masaüstü uygulamasındaki $app oturum, cihaz sunucuya bağlandığında kapanır.";
        }

        return ['web' => $web, 'app' => $app, 'mobile' => $mobile, 'message' => $msg];
    }

    public static function appMessage(object $row): string
    {
        $online = $row->last_seen_at && Carbon::parse($row->last_seen_at)->gte(now()->subMinutes(5));

        return $online
            ? sprintf('"%s" cihazına kapatma isteği gönderildi; oturum bir sonraki eşitlemede (genellikle 1 dakika içinde) kapanır.', $row->device_name)
            : sprintf('"%s" şu an çevrimdışı. Kapatma isteği bekletiliyor; cihaz sunucuya bağlandığı anda oturum kapanır.', $row->device_name);
    }

    /**
     * "Beni hatırla" çerezi, silinen oturumu sessizce geri açmasın: hatırlatma jetonu yenilenir. İşlemi yapan
     * kişi kendi (mevcut) tarayıcısındaysa ve hatırlatma çerezi varsa yeni jetonla çerez yeniden verilir.
     *
     * @param  array{web?: ?string}  $current
     */
    public function cycleRememberToken(User $owner, array $current = []): void
    {
        $token = Str::random(60);
        DB::table('users')->where('id', $owner->id)->update(['remember_token' => $token]);
        $owner->setRememberToken($token);

        $guard = Auth::guard('web');
        if (! empty($current['web']) && $guard instanceof SessionGuard && (int) $guard->id() === (int) $owner->id
            && request()->cookies->has($guard->getRecallerName())) {
            Cookie::queue(Cookie::make($guard->getRecallerName(),
                $owner->getAuthIdentifier().'|'.$token.'|'.$guard->hashPasswordForCookie($owner->getAuthPassword()), 576000));
        }
    }

    /** Denetim kaydı — işlemi yapan açıkça verilir (cihaz vekilli isteklerde Auth::user() cihazı eşleştirendir). */
    public function audit(User $actor, User $owner, string $action, string $what, ?SyncDevice $via = null): void
    {
        $self = (int) $actor->id === (int) $owner->id;
        $desc = $actor->name.', '.($self ? '' : $owner->name.' kullanıcısının ').$what.($via ? sprintf(' ("%s" uygulamasından)', $via->name) : '').'.';
        $req = app()->runningInConsole() ? null : request();
        AuditLog::query()->create([
            'branch_id' => $via?->branch_id ?? app(BranchContext::class)->id() ?? $owner->branch_id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $owner->getMorphClass(),
            'subject_id' => $owner->id,
            'description' => mb_substr($desc, 0, 500),
            'ip_address' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : 'console',
        ]);
    }

    /** @return array{web: ?string, token: ?int} */
    public static function currentOf(Request $request): array
    {
        $token = $request->user()?->currentAccessToken();

        return [
            'web' => $request->hasSession() ? $request->session()->getId() : null,
            'token' => $token instanceof PersonalAccessToken ? (int) $token->getKey() : null,
        ];
    }

    public static function describeAgent(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Tarayıcı',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return trim("$browser $os");
    }
}

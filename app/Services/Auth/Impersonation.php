<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

/**
 * "Öğrenci/Veli/Öğretmen olarak giriş yap" önizlemesi. Asıl personelin kimliği YALNIZ sunucu tarafı
 * oturumda tutulur (istemci bunu değiştiremez). Önizleme sırasında yazma işlemleri engellenir;
 * ilk giriş şifre belirleme zorunluluğu önizlemeye uygulanmaz.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    public const KIND_STUDENT = 'student';

    public const KIND_GUARDIAN = 'guardian';

    public const KIND_TEACHER = 'teacher';

    /** Önizleme sırasında izin verilen yazma uçları (api/v1 altında). */
    public const WRITE_ALLOWLIST = ['auth/impersonation/leave', 'auth/logout', 'client-errors'];

    /**
     * @return array{impersonator_id:int, impersonator_name:string, kind?:string, target_id?:int, target_name?:string,
     *               student_id?:int, student_name?:string, user_id:int, started_at:string}|null
     */
    public static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $data = $request->session()->get(self::SESSION_KEY);

        return is_array($data) && isset($data['impersonator_id'], $data['user_id']) ? $data : null;
    }

    /** Oturumdaki önizleme kaydı gerçekten şu anki kullanıcıya mı ait? */
    public static function activeFor(Request $request): ?array
    {
        $data = self::current($request);
        $user = $request->user();

        return $data && $user && (int) $data['user_id'] === (int) $user->id ? $data : null;
    }

    public static function isWriteAllowed(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return in_array(self::apiPath($request), self::WRITE_ALLOWLIST, true);
    }

    /** "api/v1/" öneki atılmış yol. */
    public static function apiPath(Request $request): string
    {
        return ltrim(preg_replace('#^api/v1/#', '', ltrim($request->path(), '/')), '/');
    }

    /** Oturum kaydı (öğrenci ya da veli). */
    public static function make(string $kind, int $impersonatorId, string $impersonatorName, int $targetId, string $targetName, int $userId): array
    {
        $data = [
            'impersonator_id' => $impersonatorId,
            'impersonator_name' => $impersonatorName,
            'kind' => $kind,
            'target_id' => $targetId,
            'target_name' => $targetName,
            'user_id' => $userId,
            'started_at' => now()->toAtomString(),
        ];
        if ($kind === self::KIND_STUDENT) {
            $data['student_id'] = $targetId;
            $data['student_name'] = $targetName;
        }

        return $data;
    }

    public static function kind(array $data): string
    {
        return $data['kind'] ?? self::KIND_STUDENT;
    }

    public static function targetId(array $data): int
    {
        return (int) ($data['target_id'] ?? $data['student_id'] ?? 0);
    }

    public static function targetName(array $data): string
    {
        return (string) ($data['target_name'] ?? $data['student_name'] ?? '');
    }

    /** Önizleme bitince dönülecek yönetim sayfası. */
    public static function returnPath(array $data): string
    {
        return match (self::kind($data)) {
            self::KIND_GUARDIAN => '/veliler/',
            self::KIND_TEACHER => '/ogretmenler/',
            default => '/ogrenciler/',
        }.self::targetId($data);
    }

    /** İstemciye gösterilecek özet (personel/kullanıcı id'leri dahil edilmez). */
    public static function publicPayload(?array $data): ?array
    {
        return $data ? [
            'kind' => self::kind($data),
            'impersonator_name' => $data['impersonator_name'],
            'target_name' => self::targetName($data),
            'student_name' => self::targetName($data),
            'target_id' => self::targetId($data),
            'started_at' => $data['started_at'],
        ] : null;
    }
}

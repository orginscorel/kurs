<?php

namespace App\Http\Middleware;

use App\Models\Teacher;
use App\Services\Teachers\TeacherScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Öğretmen portalı: öğretmen kaydı OTURUMDAKİ kullanıcıdan çözülür (istemci öğretmen id'si gönderemez).
 * Koşullar: kullanıcıya bağlı, aktif, silinmemiş öğretmen kaydı + 'teacher_portal.access' yetkisi.
 * Çözülen öğretmen ve kapsamı (TeacherScope) istek özniteliğine konur; uçlar sınıf/öğrenci
 * erişimini bu kapsamla denetler.
 */
class EnsurePortalTeacher
{
    public const ATTRIBUTE = 'portal_teacher';

    public const SCOPE_ATTRIBUTE = 'portal_teacher_scope';

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        $teacher = $user ? Teacher::query()->where('user_id', $user->id)->where('is_active', true)->first() : null;

        if (! $teacher || ! $user->can('teacher_portal.access')) {
            return self::deny('Bu sayfa yalnız öğretmen hesaplarına açıktır.');
        }
        if ($permission && ! $user->can($permission)) {
            return self::deny('Bu işlem için yetkiniz yok.');
        }

        $request->attributes->set(self::ATTRIBUTE, $teacher);
        $request->attributes->set(self::SCOPE_ATTRIBUTE, new TeacherScope($teacher));

        return $next($request);
    }

    private static function deny(string $message): Response
    {
        return response()->json([
            'message' => $message,
            'error_code' => 'teacher_portal_forbidden',
        ], 403);
    }
}

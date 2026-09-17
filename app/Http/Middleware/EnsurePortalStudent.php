<?php

namespace App\Http\Middleware;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\Portal\PortalAccounts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Öğrenci/veli portalı: öğrenci kaydı OTURUMDAKİ kullanıcıdan çözülür ve istek özniteliğine konur.
 *  - Öğrenci hesabı: yalnız kendi kaydı; istemciden gelen öğrenci id'si YOK SAYILIR.
 *  - Veli hesabı: guardian_student ile bağlı, açık (silinmemiş, ayrılmamış/mezun olmamış) öğrencilerinden
 *    biri. `student_id` sorgu parametresi YALNIZ bu liste içinden kabul edilir; listede olmayan id → 403.
 */
class EnsurePortalStudent
{
    public const ATTRIBUTE = 'portal_student';

    public const GUARDIAN_ATTRIBUTE = 'portal_guardian';

    /** Velinin görebildiği öğrenciler (sıra: birincil velisi olduğu, sonra ad). */
    public const CHILDREN_ATTRIBUTE = 'portal_children';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isStudent()) {
            $student = Student::query()->where('user_id', $user->id)->first();
            if ($student) {
                $request->attributes->set(self::ATTRIBUTE, $student);
                $request->attributes->set(self::CHILDREN_ATTRIBUTE, collect([$student]));

                return $next($request);
            }
        }

        if ($user && $user->isGuardian()) {
            $guardian = Guardian::query()->where('user_id', $user->id)->first();
            $children = $guardian ? self::childrenOf($guardian) : collect();

            if ($children->isEmpty()) {
                return self::deny('Hesabınıza bağlı görüntülenebilir öğrenci bulunmuyor.');
            }

            $requested = $request->query('student_id');
            if ($requested !== null && $requested !== '') {
                if (! is_string($requested) || ! ctype_digit($requested)) {
                    return self::deny();
                }
                $student = $children->firstWhere('id', (int) $requested);
                if (! $student) {
                    return self::deny();
                }
            } else {
                $student = $children->first();
            }

            $request->attributes->set(self::ATTRIBUTE, $student);
            $request->attributes->set(self::GUARDIAN_ATTRIBUTE, $guardian);
            $request->attributes->set(self::CHILDREN_ATTRIBUTE, $children);

            return $next($request);
        }

        return self::deny();
    }

    /** @return Collection<int, Student> */
    public static function childrenOf(Guardian $guardian): Collection
    {
        return $guardian->students()
            ->whereNotIn('students.status', PortalAccounts::CLOSED_STATUSES)
            ->orderByDesc('guardian_student.is_primary')->orderBy('students.first_name')->orderBy('students.id')
            ->get();
    }

    private static function deny(string $message = 'Bu sayfa yalnız öğrenci ve veli hesaplarına açıktır.'): Response
    {
        return response()->json([
            'message' => $message,
            'error_code' => 'portal_forbidden',
        ], 403);
    }

    public static function isGuardianRequest(Request $request): bool
    {
        return $request->attributes->get(self::GUARDIAN_ATTRIBUTE) instanceof Guardian
            && $request->user() instanceof User && $request->user()->isGuardian();
    }
}

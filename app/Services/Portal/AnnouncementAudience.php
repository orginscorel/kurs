<?php

namespace App\Services\Portal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Portallarda görünen yayımlanmış duyurular ve okundu bilgisi.
 * Hedef kitle biçimi: {"type": "all_students|class_group|program|teachers|guardians", "id"?: n, "students_only"?: bool};
 * sınıf/program hedefli duyurular o sınıftaki öğrencilerin velilerine de görünür (students_only=true değilse);
 * eski/demo kayıtlardaki {"all_students": true}, {"guardians": true}, {"teachers": true} de tanınır.
 */
final class AnnouncementAudience
{
    private static function base(int $branchId): Builder
    {
        return DB::table('announcements')
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at');
    }

    private static function flag(Builder $q, string $type, string $flag): void
    {
        $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(audience, '$.type')) = ?", [$type])
            ->orWhereRaw("JSON_EXTRACT(audience, '$.{$flag}') = true");
    }

    /** Öğretmen: öğretmenlere yönelik + tüm öğrencilere yapılan (bilgi amaçlı) duyurular. */
    public static function forTeacher(int $branchId): Builder
    {
        return self::base($branchId)->where(function ($q) {
            self::flag($q, 'teachers', 'teachers');
            self::flag($q, 'all_students', 'all_students');
        });
    }

    /**
     * Veli: velilere yönelik duyurular + çocuğunun sınıf/program duyuruları.
     * Duyuruda "yalnız öğrencilere" (audience.students_only = true) işaretliyse veli görmez.
     * Çocuk bilgisi verilmezse (eski çağrılar) yalnız velilere yönelik duyurular döner.
     *
     * @param  Collection<int,int>|null  $groupIds  seçili çocuğun açık sınıfları
     * @param  Collection<int,int>|null  $programIds  seçili çocuğun programları
     */
    public static function forGuardian(int $branchId, ?Collection $groupIds = null, ?Collection $programIds = null): Builder
    {
        $groupIds ??= collect();
        $programIds ??= collect();

        return self::base($branchId)->where(function ($q) use ($groupIds, $programIds) {
            self::flag($q, 'guardians', 'guardians');
            $q->orWhereRaw("JSON_EXTRACT(audience, '$.all_guardians') = true");
            self::targeted($q, $groupIds, $programIds, guardianView: true);
        });
    }

    /** Öğrenci: tüm öğrenciler / kendi sınıfı / kendi programı. */
    public static function forStudent(int $branchId, Collection $groupIds, Collection $programIds): Builder
    {
        return self::base($branchId)->where(function ($q) use ($groupIds, $programIds) {
            self::flag($q, 'all_students', 'all_students');
            self::targeted($q, $groupIds, $programIds, guardianView: false);
        });
    }

    /** Sınıf / program hedefli duyurular; veli görünümünde "yalnız öğrencilere" olanlar dışarıda kalır. */
    private static function targeted(Builder $q, Collection $groupIds, Collection $programIds, bool $guardianView): void
    {
        $targets = ['class_group' => $groupIds, 'program' => $programIds];
        foreach ($targets as $type => $ids) {
            $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $q->orWhere(function ($w) use ($type, $ids, $guardianView) {
                $w->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(audience, '$.type')) = ?", [$type])
                    ->whereIn(DB::raw("CAST(JSON_EXTRACT(audience, '$.id') AS UNSIGNED)"), $ids->all());
                if ($guardianView) {
                    $w->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(audience, '$.students_only')), 'false') NOT IN ('true', '1')");
                }
            });
        }
    }

    /** @return Collection<int, array{id:int, title:string, body:string, published_at:string, is_read:bool}> */
    public static function withReadState(Builder $query, int $userId): Collection
    {
        $rows = $query->get(['id', 'title', 'body', 'published_at']);
        $read = $rows->isEmpty() ? collect() : DB::table('announcement_reads')->where('user_id', $userId)
            ->whereIn('announcement_id', $rows->pluck('id'))->pluck('read_at', 'announcement_id');

        return $rows->map(fn ($a) => [
            'id' => (int) $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'published_at' => $a->published_at,
            'is_read' => isset($read[$a->id]),
        ])->values();
    }

    public static function unreadCount(Builder $query, int $userId): int
    {
        return (clone $query)->reorder()
            ->whereNotExists(fn ($w) => $w->from('announcement_reads as ar')->whereColumn('ar.announcement_id', 'announcements.id')->where('ar.user_id', $userId))
            ->count();
    }

    public static function markRead(int $announcementId, int $userId): void
    {
        DB::table('announcement_reads')->insertOrIgnore(['announcement_id' => $announcementId, 'user_id' => $userId, 'read_at' => now()]);
    }
}

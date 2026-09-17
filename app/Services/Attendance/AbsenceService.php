<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Support\Attendance\AttendanceRate;
use App\Support\Audit;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Devamsızlık raporları: tarih/sınıf/durum filtreli liste, öğrenci bazlı özet,
 * eşik aşanlar, izin/rapor toplu girişi.
 */
class AbsenceService
{
    /** Devamsızlık listesinin varsayılan kapsamı: "var" kayıtları yalnız açıkça seçilince (status=present|all) listelenir. */
    public const ABSENCE_STATUSES = ['absent', 'late', 'excused', 'medical'];

    public const ALL = 'all';

    /**
     * Ortak filtre + join'ler. $withListSelect=false ise sütun seçimi çağırana bırakılır
     * (aggregate/selectRaw kullanan studentSummary gibi metotlar için — GROUP BY ile
     * çakışan geniş bir SELECT * bırakmamak için gerekli).
     *
     * status: boş → yalnız devamsızlık durumları (ABSENCE_STATUSES); 'all' → tüm durumlar; diğer → o durum.
     *
     * @param array{from?:?string, to?:?string, class_group_id?:?int, status?:?string, student_id?:?int} $filters
     */
    public function query(array $filters, bool $withListSelect = true): Builder
    {
        $branchId = app(BranchContext::class)->require();

        $q = Attendance::query()->withoutGlobalScope('branch')
            ->join('students as st', 'st.id', '=', 'attendances.student_id')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'attendances.lesson_session_id')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->where('attendances.branch_id', $branchId);

        if ($withListSelect) {
            $q->select(['attendances.*', 'st.full_name', 'st.student_no', 's.name as subject', 'cg.name as class_group', 'cg.id as cgid']);
        }

        if (! empty($filters['from'])) {
            $q->where('attendances.date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('attendances.date', '<=', $filters['to']);
        }
        if (! empty($filters['class_group_id'])) {
            $q->where('cg.id', $filters['class_group_id']);
        }
        $status = $filters['status'] ?? null;
        if ($status === null || $status === '') {
            $q->whereIn('attendances.status', self::ABSENCE_STATUSES);
        } elseif ($status !== self::ALL) {
            $q->where('attendances.status', $status);
        }
        if (! empty($filters['student_id'])) {
            $q->where('attendances.student_id', $filters['student_id']);
        }

        return $q;
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query($filters)->orderByDesc('attendances.date')->orderByDesc('ls.starts_at')->paginate($perPage);
    }

    /** Öğrenci bazlı özet: toplam yok/geç/izinli + oran (oran için durum filtresi yok sayılır; tüm yoklamalar sayılır). */
    public function studentSummary(array $filters): \Illuminate\Support\Collection
    {
        return $this->query(['status' => self::ALL] + $filters, withListSelect: false)
            ->selectRaw('attendances.student_id, st.full_name, st.student_no,
                COUNT(*) AS total,
                SUM(attendances.status = "present") AS present,
                SUM(attendances.status = "late") AS late,
                SUM(attendances.status = "absent") AS absent,
                SUM(attendances.status = "excused") AS excused,
                SUM(attendances.status = "medical") AS medical')
            ->groupBy('attendances.student_id', 'st.full_name', 'st.student_no')
            ->orderByDesc('absent')
            ->get()
            ->map(function ($r) {
                $r->rate = AttendanceRate::rate((int) $r->present, (int) $r->late, (int) $r->total);

                return $r;
            });
    }

    /** Eşik aşanlar: verilen pencere içinde >= eşik sayıda "yok" olan öğrenciler. */
    public function overThreshold(int $threshold = 5, int $windowDays = 30): \Illuminate\Support\Collection
    {
        $branchId = app(BranchContext::class)->require();
        $since = CarbonImmutable::today()->subDays($windowDays)->toDateString();

        return DB::table('attendances as a')
            ->join('students as st', 'st.id', '=', 'a.student_id')
            ->where('a.branch_id', $branchId)->where('a.status', 'absent')->where('a.date', '>=', $since)
            ->groupBy('a.student_id', 'st.full_name', 'st.student_no')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['a.student_id', 'st.full_name', 'st.student_no', DB::raw('COUNT(*) AS absent_count')])
            ->filter(fn ($r) => AttendanceRate::overThreshold((int) $r->absent_count, $threshold))
            ->values();
    }

    /**
     * İzin/rapor girişi: tarih aralığında öğrencinin PLANLANMIŞ derslerini toplu
     * İZİNLİ/RAPORLU işaretler (mevcut kaydı ezer, elle girilmiş olsa da — bu
     * açıkça yönetici tarafından tetiklenen bir istisna girişidir).
     */
    public function bulkLeave(int $studentId, string $from, string $to, string $status, ?string $note, int $recordedBy): int
    {
        if (! in_array($status, ['excused', 'medical'], true)) {
            throw new BusinessRuleException('İzin durumu yalnız İZİNLİ ya da RAPORLU olabilir.');
        }

        $branchId = app(BranchContext::class)->require();

        $sessions = LessonSession::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->whereBetween('date', [$from, $to])->where('status', '!=', 'cancelled')
            ->whereHas('classGroup.activeStudents', fn ($q) => $q->where('students.id', $studentId))
            ->get(['id', 'date']);

        $count = 0;
        DB::transaction(function () use ($sessions, $studentId, $branchId, $status, $note, $recordedBy, &$count) {
            foreach ($sessions as $session) {
                Attendance::query()->withoutGlobalScope('branch')->updateOrCreate(
                    ['lesson_session_id' => $session->id, 'student_id' => $studentId],
                    ['branch_id' => $branchId, 'date' => $session->date, 'status' => $status, 'late_minutes' => null, 'note' => $note, 'method' => 'admin', 'recorded_by' => $recordedBy],
                );
                $count++;
            }
        });

        Audit::log('attendance.leave_entered', "Öğrenci için {$from} – {$to} arası ".($status === 'excused' ? 'izinli' : 'raporlu')." girişi yaptı ({$count} ders).");

        return $count;
    }
}

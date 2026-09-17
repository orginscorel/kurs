<?php

namespace App\Services\Teachers;

use App\Exceptions\BusinessRuleException;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Öğretmen portalında öğretmenin görebileceği veri sınırı (TEK doğruluk kaynağı).
 *
 * Sınıflarım: açık (aktif, silinmemiş) sınıflardan
 *   - öğretmenin geçerli ders programında (bugün ve sonrası) dersi olanlar,
 *   - sınıf rehber/danışman öğretmeni olduğu sınıflar,
 *   - son 14 gün / önümüzdeki 14 gün içinde ders oturumu (yerine ders dahil) olanlar.
 * Öğrencilerim: bu sınıfların mevcut öğrencileri + öğretmenin etüt/birebir öğrencileri (son 90 gün ve ileri)
 *   + rehber öğretmeni olduğu öğrenciler + son 90 günde ödev verdiği öğrenciler.
 * Ayrılmış/mezun öğrenci dahil edilmez.
 */
class TeacherScope
{
    public const CLOSED_STATUSES = ['withdrawn', 'graduated'];

    private ?Collection $groupIds = null;

    private ?Collection $studentIds = null;

    public function __construct(public readonly Teacher $teacher) {}

    /** @return Collection<int, int> */
    public function groupIds(): Collection
    {
        if ($this->groupIds !== null) {
            return $this->groupIds;
        }

        $today = CarbonImmutable::today();
        $tid = $this->teacher->id;

        $fromSchedule = DB::table('lesson_schedules')->where('teacher_id', $tid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today->toDateString()))
            ->pluck('class_group_id');
        $fromSessions = DB::table('lesson_sessions')->where('teacher_id', $tid)
            ->whereBetween('date', [$today->subDays(14)->toDateString(), $today->addDays(14)->toDateString()])
            ->pluck('class_group_id');
        $advisor = DB::table('class_groups')->where('advisor_teacher_id', $tid)->pluck('id');

        $ids = $fromSchedule->merge($fromSessions)->merge($advisor)->map(fn ($v) => (int) $v)->unique()->values();

        return $this->groupIds = $ids->isEmpty() ? collect() : DB::table('class_groups')->whereIn('id', $ids)
            ->where('is_active', true)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->values();
    }

    public function hasGroup(int $groupId): bool
    {
        return $this->groupIds()->contains($groupId);
    }

    public function assertGroup(int $groupId): void
    {
        if (! $this->hasGroup($groupId)) {
            throw new BusinessRuleException('Bu sınıf sizin sınıflarınız arasında değil.', 'teacher_scope_group', [], 403);
        }
    }

    /** @return Collection<int, int> */
    public function studentIds(): Collection
    {
        if ($this->studentIds !== null) {
            return $this->studentIds;
        }

        $tid = $this->teacher->id;
        $since = CarbonImmutable::today()->subDays(90);

        $groupIds = $this->groupIds();
        $inGroups = $groupIds->isEmpty() ? collect() : DB::table('class_group_student')->whereIn('class_group_id', $groupIds)->whereNull('left_on')->pluck('student_id');
        $inStudy = DB::table('study_session_student as sss')->join('study_sessions as ss', 'ss.id', '=', 'sss.study_session_id')
            ->where('ss.teacher_id', $tid)->where('ss.starts_at', '>=', $since)->whereNotIn('ss.status', ['cancelled', 'rejected'])
            ->pluck('sss.student_id');
        $guided = DB::table('students')->where('guidance_teacher_id', $tid)->pluck('id');
        $homework = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->where('h.teacher_id', $tid)->whereNull('h.deleted_at')->where('h.due_at', '>=', $since)
            ->pluck('hs.student_id');

        $ids = $inGroups->merge($inStudy)->merge($guided)->merge($homework)->map(fn ($v) => (int) $v)->unique()->values();

        return $this->studentIds = $ids->isEmpty() ? collect() : DB::table('students')->whereIn('id', $ids)
            ->whereNull('deleted_at')->whereNotIn('status', self::CLOSED_STATUSES)
            ->pluck('id')->map(fn ($v) => (int) $v)->values();
    }

    public function hasStudent(int $studentId): bool
    {
        return $this->studentIds()->contains($studentId);
    }

    public function assertStudent(int $studentId): void
    {
        if (! $this->hasStudent($studentId)) {
            throw new BusinessRuleException('Bu öğrenci sizin sınıflarınızda değil.', 'teacher_scope_student', [], 403);
        }
    }

    /** Öğretmenin bir sınıfta (ya da genelde) verdiği dersler: ders programı + branş eşlemesi. */
    public function subjectIds(?int $groupId = null): Collection
    {
        $fromSchedule = DB::table('lesson_schedules')->where('teacher_id', $this->teacher->id)->whereNull('deleted_at')
            ->when($groupId, fn ($q) => $q->where('class_group_id', $groupId))
            ->pluck('subject_id');
        $fromBranch = DB::table('teacher_subject')->where('teacher_id', $this->teacher->id)->pluck('subject_id');

        return $fromSchedule->merge($fromBranch)->map(fn ($v) => (int) $v)->unique()->values();
    }

    /** Sınıfların öğrenci sayıları ve öğretmenin o sınıftaki dersleri. */
    public function groupsWithMeta(): Collection
    {
        $ids = $this->groupIds();
        if ($ids->isEmpty()) {
            return collect();
        }
        $today = CarbonImmutable::today()->toDateString();

        $subjects = DB::table('lesson_schedules as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->where('ls.teacher_id', $this->teacher->id)->whereNull('ls.deleted_at')->whereIn('ls.class_group_id', $ids)
            ->where(fn ($q) => $q->whereNull('ls.valid_until')->orWhere('ls.valid_until', '>=', $today))
            ->groupBy('ls.class_group_id', 's.id', 's.name', 's.color')
            ->selectRaw('ls.class_group_id, s.id, s.name, s.color, COUNT(*) AS weekly')
            ->get()->groupBy('class_group_id');

        return DB::table('class_groups as g')
            ->leftJoin('programs as p', 'p.id', '=', 'g.program_id')
            ->leftJoin('classrooms as c', 'c.id', '=', 'g.homeroom_classroom_id')
            ->leftJoin('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'g.id')->whereNull('cgs.left_on'))
            ->whereIn('g.id', $ids)
            ->groupBy('g.id', 'g.name', 'g.grade_level', 'g.color', 'p.name', 'c.name', 'g.advisor_teacher_id')
            ->orderBy('g.grade_level')->orderBy('g.name')
            ->get(['g.id', 'g.name', 'g.grade_level', 'g.color', 'p.name as program', 'c.name as classroom', 'g.advisor_teacher_id', DB::raw('COUNT(cgs.id) AS student_count')])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'name' => $g->name,
                'grade_level' => $g->grade_level,
                'program' => $g->program,
                'classroom' => $g->classroom,
                'is_advisor' => (int) $g->advisor_teacher_id === $this->teacher->id,
                'student_count' => (int) $g->student_count,
                'subjects' => ($subjects[$g->id] ?? collect())->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'color' => $s->color, 'weekly' => (int) $s->weekly])->values(),
            ]);
    }
}

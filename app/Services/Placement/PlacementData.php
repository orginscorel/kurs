<?php

namespace App\Services\Placement;

use App\Services\Placement\Core\Candidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Veritabanından yerleştirme girdisi toplar: seviye listesi, son 3 deneme net ortalaması,
 * risk seviyesi, kardeş (ortak veli) anahtarı, sabitleme.
 */
class PlacementData
{
    /**
     * Seviyedeki öğrenciler: (a) seviyenin sınıflarında açık üyeliği olanlar + (b) school_grade'i bu seviye olan
     * aktif öğrenciler. Bekleme listesindekiler `waitlisted` işaretiyle döner.
     *
     * @param  Collection<string, object>  $levelGroups  bu seviyenin sınıfları ("10-A" anahtarlı)
     * @return Collection<int, object>  öğrenci id anahtarlı
     */
    public function roster(int $termId, int $level, Collection $levelGroups): Collection
    {
        $groupIds = $levelGroups->pluck('id')->all();
        $sectionById = $levelGroups->mapWithKeys(fn ($g) => [$g->id => $g->section])->all();

        // Dönemdeki tüm açık üyelikler (başka seviyedeki sınıf dahil)
        $memberships = DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->where('cg.academic_term_id', $termId)->whereNull('cgs.left_on')
            ->where(fn ($q) => $q->whereIn('cgs.class_group_id', $groupIds ?: [0])
                ->orWhereIn('cgs.student_id', DB::table('students')->where('status', 'active')->whereNull('deleted_at')->whereIn('school_grade', ClassStructure::gradeValues($level))->select('id')))
            ->orderBy('cgs.id')
            ->get(['cgs.id as membership_id', 'cgs.student_id', 'cgs.class_group_id', 'cgs.joined_on', 'cgs.is_pinned', 'cg.name as class_name']);
        $byStudent = $memberships->groupBy('student_id');

        $memberIds = $memberships->whereIn('class_group_id', $groupIds)->pluck('student_id')->all();
        $students = DB::table('students')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('id', $memberIds ?: [0])
                ->orWhere(fn ($w) => $w->where('status', 'active')->whereIn('school_grade', ClassStructure::gradeValues($level))))
            ->orderBy('id')
            ->get(['id', 'full_name', 'student_no', 'gender', 'status', 'school_grade', 'field', 'registered_on', 'photo_path', 'created_at']);

        $waiting = DB::table('class_waitlist')->where('academic_term_id', $termId)->where('status', 'waiting')->whereIn('student_id', $students->pluck('id'))
            ->get(['id', 'student_id', 'preferred_section', 'source', 'reason', 'created_at'])->keyBy('student_id');

        return $students->mapWithKeys(function ($s) use ($byStudent, $sectionById, $waiting, $level) {
            $rows = $byStudent[$s->id] ?? collect();
            $inLevel = $rows->first(fn ($r) => isset($sectionById[$r->class_group_id]));
            $other = $rows->first(fn ($r) => ! isset($sectionById[$r->class_group_id]));

            return [$s->id => (object) [
                'id' => (int) $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'gender' => $s->gender, 'status' => $s->status,
                'school_grade' => $s->school_grade, 'field' => $s->field, 'grade' => ClassStructure::gradeOf($s->school_grade), 'grade_mismatch' => ClassStructure::gradeOf($s->school_grade) !== $level,
                'registered_on' => $s->registered_on, 'photo_path' => $s->photo_path, 'created_at' => $s->created_at,
                'class_group_id' => $inLevel ? (int) $inLevel->class_group_id : null,
                'section' => $inLevel ? $sectionById[$inLevel->class_group_id] : null,
                'joined_on' => $inLevel?->joined_on,
                'pinned' => $inLevel ? (bool) $inLevel->is_pinned : false,
                'other_class' => $other ? ['id' => (int) $other->class_group_id, 'name' => $other->class_name] : null,
                'waitlist' => $waiting[$s->id] ?? null,
            ]];
        });
    }

    /** @return array<int, array{avg:?float, count:int}> son 3 yayımlanmış deneme net ortalaması */
    public function scores(array $studentIds): array
    {
        $out = array_fill_keys($studentIds, ['avg' => null, 'count' => 0]);
        if (! $studentIds) {
            return $out;
        }
        $rows = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->whereIn('r.student_id', $studentIds)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->orderByDesc('e.id')
            ->get(['r.student_id', 'r.net']);
        foreach ($rows->groupBy('student_id') as $sid => $list) {
            $nets = $list->take(3)->pluck('net')->map(fn ($n) => (float) $n);
            $out[(int) $sid] = ['avg' => round($nets->avg(), 2), 'count' => $nets->count()];
        }

        return $out;
    }

    /** @return array<int, string> öğrenci => low|medium|high (hesaplanmışsa) */
    public function risks(array $studentIds): array
    {
        return $studentIds ? DB::table('student_risk_scores')->whereIn('student_id', $studentIds)->pluck('level', 'student_id')->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all() : [];
    }

    /** @return array<int, int> öğrenci => kardeş anahtarı (yalnız aynı kümede veli paylaşan öğrenciler) */
    public function families(array $studentIds): array
    {
        if (! $studentIds) {
            return [];
        }
        $links = DB::table('guardian_student')->whereIn('student_id', $studentIds)->get(['guardian_id', 'student_id']);
        $shared = $links->groupBy('guardian_id')->filter(fn ($g) => $g->pluck('student_id')->unique()->count() > 1);
        $out = [];
        foreach ($shared as $guardianId => $rows) {
            foreach ($rows as $r) {
                $sid = (int) $r->student_id;
                $out[$sid] = isset($out[$sid]) ? min($out[$sid], (int) $guardianId) : (int) $guardianId;
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, object>  $students  roster() satırları
     * @param  array<int,int>  $pinOverrides  öğrenci => 1/0 (önizlemede geçici sabitleme)
     * @return list<Candidate>
     */
    public function candidates(Collection $students, array $scores, array $risks, array $families, array $pinOverrides = []): array
    {
        $out = [];
        foreach ($students as $s) {
            $registered = $s->registered_on ?? $s->created_at;
            $priority = $registered ? (int) (CarbonImmutable::parse($registered)->timestamp / 86400) : 99999;
            $out[] = new Candidate(
                id: $s->id,
                gender: $s->gender,
                score: $scores[$s->id]['avg'] ?? null,
                highRisk: ($risks[$s->id] ?? null) === 'high',
                family: $families[$s->id] ?? null,
                current: $s->section,
                pinned: array_key_exists($s->id, $pinOverrides) ? (bool) $pinOverrides[$s->id] : $s->pinned,
                priority: $priority,
            );
        }

        return $out;
    }
}

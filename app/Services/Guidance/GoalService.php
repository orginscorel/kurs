<?php

namespace App\Services\Guidance;

use App\Models\Student;
use App\Models\StudentGoal;
use App\Support\Audit;
use App\Support\Guidance\GoalProgress;
use Illuminate\Support\Facades\DB;

/**
 * Öğrenci hedefleri (üniversite, net hedefleri, ders bazlı hedefler) ve son 3 sınav
 * ortalamasına göre hedef-gerçek karşılaştırması.
 */
class GoalService
{
    public const FIELDS = ['university', 'department', 'target_rank', 'target_tyt_net', 'target_ayt_net', 'subject_targets', 'is_active'];

    public function create(Student $student, array $data): StudentGoal
    {
        return DB::transaction(function () use ($student, $data) {
            $goal = $student->goals()->create(array_intersect_key($data, array_flip(self::FIELDS)));
            // Audit kaydının "subject"ı öğrencidir (morph map'te StudentGoal yok).
            Audit::log('guidance.goal_created', "{$student->full_name} için hedef tanımladı.", $student);

            return $goal;
        });
    }

    public function update(StudentGoal $goal, array $data): StudentGoal
    {
        return DB::transaction(function () use ($goal, $data) {
            $goal->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $goal->save();
            Audit::log('guidance.goal_updated', sprintf('%s hedefini güncelledi.', $goal->student?->full_name ?? '—'), $goal->student);

            return $goal;
        });
    }

    public function delete(StudentGoal $goal): void
    {
        DB::transaction(function () use ($goal) {
            $student = $goal->student;
            $goal->delete();
            Audit::log('guidance.goal_deleted', sprintf('%s hedefini sildi.', $student?->full_name ?? '—'), $student);
        });
    }

    /** Hedef vs son 3 sınav ortalaması. */
    public function progress(StudentGoal $goal): array
    {
        $studentId = $goal->student_id;

        $tytNets = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $studentId)->where('t.code', 'TYT')->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit(3)->pluck('r.net')->map(fn ($n) => (float) $n)->all();

        $aytNets = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $studentId)->where('t.code', 'like', 'AYT_%')->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit(3)->pluck('r.net')->map(fn ($n) => (float) $n)->all();

        $subjectTargets = $goal->subject_targets ?? [];
        $subjects = [];
        foreach ($subjectTargets as $code => $target) {
            $nets = DB::table('exam_result_sections as s')->join('exam_sections as es', 'es.id', '=', 's.exam_section_id')
                ->join('exam_results as r', 'r.id', '=', 's.exam_result_id')->join('exams as e', 'e.id', '=', 'r.exam_id')
                ->where('r.student_id', $studentId)->where('es.code', $code)->where('e.status', 'results_published')->whereNull('e.deleted_at')
                ->orderByDesc('e.exam_date')->limit(3)->pluck('s.net')->map(fn ($n) => (float) $n)->all();

            $subjects[] = ['code' => $code, ...GoalProgress::compare((float) $target, GoalProgress::average($nets))];
        }

        return [
            'goal_id' => $goal->id,
            'tyt' => GoalProgress::compare($goal->target_tyt_net !== null ? (float) $goal->target_tyt_net : null, GoalProgress::average($tytNets)),
            'ayt' => GoalProgress::compare($goal->target_ayt_net !== null ? (float) $goal->target_ayt_net : null, GoalProgress::average($aytNets)),
            'subjects' => $subjects,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Staff;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Öğretmen paneli: giriş yapan öğretmen kullanıcısının kendi özet ekranı.
 * "Panelim" menüsü — kontrol merkezi yerine öğretmen kullanıcılarına gösterilir.
 */
class TeacherPanelController extends ApiController
{
    public function summary(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $today = now()->toDateString();

        $todayLessons = DB::table('lesson_sessions as ls')
            ->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->join('classrooms as c', 'c.id', '=', 'ls.classroom_id')
            ->where('ls.teacher_id', $teacher->id)->where('ls.date', $today)->orderBy('ls.starts_at')
            ->get(['ls.id', 'ls.starts_at', 'ls.ends_at', 'ls.status', 's.name as subject', 'cg.name as class_group', 'c.name as classroom', 'ls.attendance_taken_at']);

        $next = $todayLessons->first(fn ($l) => $l->status === 'scheduled' && $l->starts_at >= now()->toDateTimeString());

        $classGroupIds = DB::table('lesson_schedules')->where('teacher_id', $teacher->id)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->distinct()->pluck('class_group_id');

        $myClasses = DB::table('class_groups as cg')->whereIn('cg.id', $classGroupIds)
            ->leftJoin('class_group_student as cgs', fn ($j) => $j->on('cgs.class_group_id', '=', 'cg.id')->whereNull('cgs.left_on'))
            ->groupBy('cg.id', 'cg.name')->orderBy('cg.name')
            ->get(['cg.id', 'cg.name', DB::raw('COUNT(cgs.id) as student_count')]);

        $pendingAttendance = DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->where('ls.teacher_id', $teacher->id)->where('ls.status', 'completed')->whereNull('ls.attendance_taken_at')
            ->where('ls.date', '>=', now()->subDays(7)->toDateString())
            ->orderByDesc('ls.date')->limit(20)
            ->get(['ls.id', 'ls.date', 'ls.starts_at', 's.name as subject', 'cg.name as class_group']);

        $homeworkToGrade = DB::table('homework as h')
            ->join('homework_submissions as hs', 'hs.homework_id', '=', 'h.id')
            ->where('h.teacher_id', $teacher->id)->whereNull('h.deleted_at')
            ->where('hs.status', 'submitted')->whereNull('hs.score')
            ->select('h.id', 'h.title', DB::raw('COUNT(*) as pending_count'))
            ->groupBy('h.id', 'h.title')->orderByDesc('pending_count')->limit(10)->get();

        $upcomingExams = DB::table('exams as e')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->whereNull('e.deleted_at')->where('e.exam_date', '>=', $today)->orderBy('e.exam_date')->limit(6)
            ->get(['e.id', 'e.name', 'e.exam_date', 't.name as type']);

        $announcements = DB::table('announcements')->whereNotNull('published_at')
            ->orderByDesc('published_at')->limit(8)->get(['id', 'title', 'body', 'published_at', 'audience'])
            ->filter(function ($a) {
                $audience = json_decode($a->audience, true) ?: [];

                return ! empty($audience['all_students']) || ! empty($audience['teachers']) || empty($audience);
            })->values();

        return response()->json([
            'teacher' => ['id' => $teacher->id, 'full_name' => $teacher->full_name, 'avatar_url' => $teacher->avatar_path],
            'today_lessons' => $todayLessons,
            'next_lesson' => $next,
            'my_classes' => $myClasses,
            'pending_attendance' => $pendingAttendance,
            'homework_to_grade' => $homeworkToGrade,
            'upcoming_exams' => $upcomingExams,
            'announcements' => $announcements,
        ]);
    }

    private function resolveTeacher(Request $request): Teacher
    {
        $teacherId = $request->user()->teacher?->id;
        if (! $teacherId) {
            throw new BusinessRuleException('Bu ekrana yalnızca öğretmen hesapları erişebilir.', 'not_a_teacher', [], 403);
        }

        return Teacher::query()->findOrFail($teacherId);
    }
}

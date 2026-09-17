<?php

namespace App\Http\Controllers\Api\Academic;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Program;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Academic\TimeSlots;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Akademik modülün tüm formları için seçenekler tek istekte (istemci 5 dk önbellekler). */
class AcademicOptionsController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $teacherSubjects = DB::table('teacher_subject')->get()->groupBy('teacher_id')->map(fn ($rows) => $rows->pluck('subject_id')->map(fn ($v) => (int) $v)->values());
        $programSubjects = DB::table('program_subject')->get()->groupBy('program_id')->map(fn ($rows) => $rows->pluck('subject_id')->map(fn ($v) => (int) $v)->values());
        $myTeacher = $user->user_type === 'teacher' ? Teacher::query()->where('user_id', $user->id)->value('id') : null;

        return response()->json([
            'programs' => Program::query()->orderBy('name')->get(['id', 'code', 'name', 'kind', 'color', 'exam_track', 'is_active'])
                ->map(fn ($p) => [...$p->toArray(), 'subject_ids' => $programSubjects[$p->id] ?? []]),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'code', 'name', 'short_name', 'color', 'is_active']),
            'classrooms' => Classroom::query()->orderBy('name')->get(['id', 'name', 'kind', 'capacity', 'floor', 'is_active']),
            'teachers' => Teacher::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'color', 'title', 'is_active'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->full_name, 'first_name' => $t->first_name, 'last_name' => $t->last_name, 'color' => $t->color, 'title' => $t->title, 'is_active' => $t->is_active, 'subject_ids' => $teacherSubjects[$t->id] ?? []]),
            'terms' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'is_current']),
            'class_groups' => ClassGroup::query()->with('program:id,name,color')->orderBy('name')->get(['id', 'name', 'program_id', 'academic_term_id', 'capacity', 'homeroom_classroom_id', 'is_active'])
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'program_id' => $g->program_id, 'program' => $g->program?->name, 'color' => $g->program?->color, 'academic_term_id' => $g->academic_term_id, 'capacity' => $g->capacity, 'homeroom_classroom_id' => $g->homeroom_classroom_id, 'is_active' => $g->is_active]),
            'weekdays' => TimeSlots::WEEKDAYS,
            'classroom_kinds' => Classroom::KINDS,
            'my_teacher_id' => $myTeacher,
            'my_student_id' => $user->user_type === 'student' ? DB::table('students')->where('user_id', $user->id)->value('id') : null,
        ]);
    }
}

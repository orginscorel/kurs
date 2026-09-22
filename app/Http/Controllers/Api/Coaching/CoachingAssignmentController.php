<?php

namespace App\Http\Controllers\Api\Coaching;

use App\Http\Controllers\Api\ApiController;
use App\Models\CoachingAssignment;
use App\Models\CoachingSession;
use App\Models\Student;
use App\Models\User;
use App\Services\Coaching\CoachingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Öğrenci ↔ koç atamaları ve koçluk öğrenci listesi. */
class CoachingAssignmentController extends ApiController
{
    public function __construct(private readonly CoachingService $coaching) {}

    /** Öğrenciler + (varsa) aktif koçu + son görüşme tarihi. */
    public function index(Request $request): JsonResponse
    {
        $query = Student::query()
            ->whereIn('status', ['active', 'enrolled', 'frozen'])
            ->select('id', 'full_name', 'student_no', 'school_grade', 'field')
            ->with(['activeCoachingAssignment' => fn ($q) => $q->with('coach:id,name')])
            ->addSelect(['last_session_at' => CoachingSession::query()->selectRaw('MAX(held_at)')
                ->whereColumn('student_id', 'students.id')->whereNull('deleted_at')]);

        if ($coachId = $request->integer('coach_id')) {
            $query->whereHas('activeCoachingAssignment', fn (Builder $a) => $a->where('coach_id', $coachId));
        }
        if ($request->boolean('unassigned')) {
            $query->whereDoesntHave('activeCoachingAssignment');
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn (Builder $w) => $w->where('full_name', 'like', '%'.$q.'%')->orWhere('student_no', 'like', '%'.$q.'%'));
        }

        $paginator = $query->orderBy('full_name')->paginate($this->perPage($request));

        return $this->paginated($paginator, function (Student $s) {
            $a = $s->activeCoachingAssignment;

            return [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'student_no' => $s->student_no,
                'school_grade' => $s->school_grade,
                'field' => $s->field,
                'coach' => $a?->coach ? ['id' => $a->coach->id, 'name' => $a->coach->name] : null,
                'assigned_at' => $a?->assigned_at,
                'last_session_at' => $s->last_session_at,
            ];
        });
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'coaches' => User::query()->where('is_active', true)->whereIn('user_type', ['staff', 'teacher'])
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'coach_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'note' => ['nullable', 'string', 'max:300'],
        ], [], ['coach_id' => 'Koç', 'note' => 'Not']);

        $this->coaching->assignCoach($student, (int) $data['coach_id'], $data['note'] ?? null);

        return $this->ok('Koç atandı.');
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->coaching->unassign($student);

        return $this->ok('Koç ataması kaldırıldı.');
    }
}

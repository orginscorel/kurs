<?php

namespace App\Http\Controllers\Api\Coaching;

use App\Http\Controllers\Api\ApiController;
use App\Models\CoachingSession;
use App\Services\Coaching\CoachingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Akademik koçluk görüşmeleri. */
class CoachingSessionController extends ApiController
{
    public function __construct(private readonly CoachingService $coaching) {}

    public function index(Request $request): JsonResponse
    {
        $query = CoachingSession::query()->with(['student:id,full_name,student_no,school_grade', 'coach:id,name']);

        if ($studentId = $request->integer('student_id')) {
            $query->where('student_id', $studentId);
        }
        if ($coachId = $request->integer('coach_id')) {
            $query->where('coach_id', $coachId);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->whereHas('student', fn (Builder $s) => $s->where('full_name', 'like', '%'.$q.'%'));
        }
        if ($request->boolean('upcoming')) {
            $query->whereNotNull('next_session_on')->where('next_session_on', '>=', CarbonImmutable::today());
        }
        $this->applyDateRange($query, $request, 'held_at');
        $this->applySort($query, $request, ['held_at' => 'held_at', 'next_session_on' => 'next_session_on'], '-held_at');

        return $this->paginated($query->paginate($this->perPage($request)), fn (CoachingSession $s) => $this->row($s));
    }

    public function show(CoachingSession $session): JsonResponse
    {
        $session->load(['student:id,full_name,student_no,school_grade', 'coach:id,name']);

        return response()->json(['data' => $this->row($session)]);
    }

    public function store(Request $request): JsonResponse
    {
        $session = $this->coaching->createSession($this->validated($request));

        return response()->json(['message' => 'Koçluk görüşmesi kaydedildi.', 'id' => $session->id], 201);
    }

    public function update(Request $request, CoachingSession $session): JsonResponse
    {
        $this->coaching->updateSession($session, $this->validated($request, $session));

        return $this->ok('Koçluk görüşmesi güncellendi.');
    }

    public function destroy(CoachingSession $session): JsonResponse
    {
        $this->coaching->deleteSession($session);

        return $this->ok('Koçluk görüşmesi silindi.');
    }

    private function row(CoachingSession $s): array
    {
        return [
            'id' => $s->id,
            'student' => $s->relationLoaded('student') ? $s->student?->only(['id', 'full_name', 'student_no', 'school_grade']) : null,
            'coach' => $s->relationLoaded('coach') ? $s->coach?->only(['id', 'name']) : null,
            'held_at' => $s->held_at,
            'topics' => $s->topics,
            'focus' => $s->focus,
            'motivation' => $s->motivation,
            'action_items' => $s->action_items,
            'next_session_on' => $s->next_session_on,
        ];
    }

    private function validated(Request $request, ?CoachingSession $session = null): array
    {
        return $request->validate([
            'student_id' => [$session ? 'sometimes' : 'required', 'integer', Rule::exists('students', 'id')],
            'coach_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'held_at' => ['required', 'date'],
            'topics' => ['nullable', 'string', 'max:4000'],
            'focus' => ['nullable', 'integer', 'min:1', 'max:5'],
            'motivation' => ['nullable', 'integer', 'min:1', 'max:5'],
            'action_items' => ['nullable', 'string', 'max:4000'],
            'next_session_on' => ['nullable', 'date'],
        ], [], [
            'student_id' => 'Öğrenci', 'coach_id' => 'Koç', 'held_at' => 'Görüşme tarihi', 'topics' => 'Konuşulanlar',
            'focus' => 'Odak/çalışma düzeyi', 'motivation' => 'Motivasyon', 'action_items' => 'Yapılacaklar', 'next_session_on' => 'Sonraki görüşme',
        ]);
    }
}

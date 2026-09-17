<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Services\Attendance\AttendanceTakingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hızlı yoklama ekranı: bugünkü dersler → sınıf listesi tek ekranda → tek istekte kaydet.
 */
class AttendanceTakingController extends ApiController
{
    public function __construct(private readonly AttendanceTakingService $taking) {}

    public function sessions(Request $request): JsonResponse
    {
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();

        return response()->json(['date' => $date, 'data' => $this->taking->sessionsForDate($request->user(), $date)]);
    }

    public function roster(Request $request, LessonSession $session): JsonResponse
    {
        $this->taking->assertCanTake($session, $request->user());

        return response()->json($this->taking->roster($session));
    }

    public function store(Request $request, LessonSession $session): JsonResponse
    {
        $this->taking->assertCanTake($session, $request->user());

        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.status' => ['required', Rule::in(array_keys(Attendance::STATUSES))],
            'rows.*.late_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'rows.*.note' => ['nullable', 'string', 'max:300'],
        ], [], ['rows' => 'Öğrenci listesi', 'rows.*.student_id' => 'Öğrenci', 'rows.*.status' => 'Yoklama durumu', 'rows.*.late_minutes' => 'Geç kalma süresi (dk)', 'rows.*.note' => 'Not']);

        $count = $this->taking->save($session, $data['rows'], $request->user());

        return response()->json(['message' => "{$count} öğrencinin yoklaması kaydedildi."]);
    }
}

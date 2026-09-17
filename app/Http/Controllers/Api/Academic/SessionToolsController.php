<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\LessonSession;
use App\Services\Academic\SessionMover;
use App\Services\Academic\SubstituteFinder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tek oturum araçları: yedek öğretmen önerisi/atama, telafi (taşıma) önerisi/uygulama, izin etkisi. */
class SessionToolsController extends ApiController
{
    public function __construct(private readonly SubstituteFinder $substitutes, private readonly SessionMover $mover) {}

    public function substitutes(LessonSession $session): JsonResponse
    {
        return response()->json($this->substitutes->candidates($session));
    }

    public function substitute(Request $request, LessonSession $session): JsonResponse
    {
        $data = $request->validate(['teacher_id' => ['required', 'integer', Rule::exists('teachers', 'id')]]);
        $s = $this->substitutes->assign($session, (int) $data['teacher_id']);

        return $this->ok("Yedek öğretmen atandı: {$s->teacher->full_name}.");
    }

    public function freeSlots(Request $request, LessonSession $session): JsonResponse
    {
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from')) : CarbonImmutable::today();
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to')) : $from->addDays(14);
        $session->loadMissing(['classGroup', 'subject:id,name', 'teacher:id,first_name,last_name', 'classroom:id,name']);

        return response()->json(['data' => $this->mover->suggestions($session, $from->max(CarbonImmutable::today()), $to)]);
    }

    public function move(Request $request, LessonSession $session): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')],
            'reason' => ['nullable', 'string', 'max:200'],
        ], ['ends_at.after' => 'Bitiş saati başlangıçtan sonra olmalı.']);
        $makeup = $this->mover->move($session, $data);

        return $this->ok('Ders '.$makeup->starts_at->format('d.m.Y H:i').' saatine taşındı; öğrencilere bildirildi.', ['id' => $makeup->id]);
    }

    public function leaveImpact(Request $request): JsonResponse
    {
        $from = $request->date('from') ? CarbonImmutable::parse($request->date('from')) : CarbonImmutable::today();
        $to = $request->date('to') ? CarbonImmutable::parse($request->date('to')) : $from->addDays(14);
        if ($from->diffInDays($to) > 62) {
            throw new BusinessRuleException('En fazla 62 günlük aralık sorgulanabilir.', 'range_too_wide');
        }

        return response()->json($this->substitutes->leaveImpact($from, $to, $request->integer('teacher_id') ?: null));
    }
}

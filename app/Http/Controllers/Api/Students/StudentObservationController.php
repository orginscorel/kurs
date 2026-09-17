<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Api\ApiController;
use App\Models\Student;
use App\Models\StudentObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yönetim › öğrenci profili "Gözlemler" sekmesi: öğretmenlerin portal üzerinden girdiği gözlem notları
 * (salt okunur). Gözlemi yazan/düzenleyen öğretmen portalıdır; burada görünürlük yalnız gösterilir.
 */
class StudentObservationController extends ApiController
{
    public function index(Request $request, Student $student): JsonResponse
    {
        $request->validate(['kind' => ['nullable', 'in:positive,improve,note']]);

        $base = StudentObservation::query()->where('student_id', $student->id);
        $summary = (clone $base)->selectRaw("COUNT(*) AS total, COALESCE(SUM(points), 0) AS points,
            SUM(CASE WHEN kind = 'positive' THEN 1 ELSE 0 END) AS positive,
            SUM(CASE WHEN kind = 'improve' THEN 1 ELSE 0 END) AS improve,
            SUM(CASE WHEN kind = 'note' THEN 1 ELSE 0 END) AS note,
            SUM(CASE WHEN visible_to_guardian = 1 OR visible_to_student = 1 THEN 1 ELSE 0 END) AS shared")->first();

        $rows = (clone $base)
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->with(['teacher:id,first_name,last_name', 'subject:id,name', 'classGroup:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(min(200, max(10, (int) $request->integer('limit', 100))))
            ->get()
            ->map(fn (StudentObservation $o) => [
                'id' => $o->id,
                'kind' => $o->kind,
                'kind_label' => StudentObservation::KINDS[$o->kind] ?? $o->kind,
                'category' => $o->category,
                'category_label' => StudentObservation::CATEGORIES[$o->category] ?? $o->category,
                'points' => (int) $o->points,
                'body' => $o->body,
                'teacher' => $o->teacher ? ['id' => $o->teacher->id, 'name' => trim($o->teacher->first_name.' '.$o->teacher->last_name)] : null,
                'subject' => $o->subject?->name,
                'class_group' => $o->classGroup?->name,
                'visible_to_guardian' => (bool) $o->visible_to_guardian,
                'visible_to_student' => (bool) $o->visible_to_student,
                'created_at' => $o->created_at?->toAtomString(),
            ]);

        return response()->json([
            'data' => $rows,
            'summary' => [
                'total' => (int) ($summary->total ?? 0),
                'points' => (int) ($summary->points ?? 0),
                'positive' => (int) ($summary->positive ?? 0),
                'improve' => (int) ($summary->improve ?? 0),
                'note' => (int) ($summary->note ?? 0),
                'shared' => (int) ($summary->shared ?? 0),
            ],
        ]);
    }
}

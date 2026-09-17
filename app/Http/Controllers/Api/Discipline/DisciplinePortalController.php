<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\DisciplineDefense;
use App\Models\DisciplineSanction;
use App\Models\Student;
use App\Services\Discipline\DisciplineService;
use App\Services\Discipline\DisciplineSettings;
use App\Support\Discipline\DisciplineCatalog as C;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Öğrenci / veli portalı › Disiplin. Yalnız SONUÇLANMIŞ ve portala açık yaptırımlar + savunma istemleri (kurum ayarıyla).
 * Olay açıklaması, tanıklar, karar notu, diğer öğrenciler ve puanlar portala hiç dönmez.
 * Öğrenci kaydı oturumdan çözülür (EnsurePortalStudent); savunmayı yalnız öğrencinin kendi hesabı yazabilir.
 */
class DisciplinePortalController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Student $student */
        $student = $request->attributes->get(EnsurePortalStudent::ATTRIBUTE);
        $settings = DisciplineSettings::all((int) $student->branch_id);
        $isStudent = $request->user()->isStudent();

        if (! $settings['portal_enabled']) {
            return response()->json(['data' => ['enabled' => false, 'sanctions' => [], 'defenses' => []]]);
        }

        $behaviors = fn (array $incidentIds) => DB::table('discipline_incident_students as p')->join('discipline_behaviors as b', 'b.id', '=', 'p.behavior_id')
            ->whereIn('p.incident_id', $incidentIds)->where('p.student_id', $student->id)->get(['p.incident_id', 'b.name'])->groupBy('incident_id');

        $sanctions = DisciplineSanction::query()->withoutGlobalScope('branch')->where('student_id', $student->id)
            ->whereIn('status', C::DECIDED_SANCTION_STATUSES)->where('visible_to_portal', true)
            ->with(['type:id,name,level,is_suspension,tone', 'incident' => fn ($q) => $q->withoutGlobalScope('branch')->select('id', 'occurred_at', 'incident_no')])
            ->orderByDesc('decided_at')->limit(50)->get();
        $sb = $behaviors($sanctions->pluck('incident_id')->all());

        $defenses = collect();
        if ($settings['portal_defense_requests']) {
            $defenses = DisciplineDefense::query()->withoutGlobalScope('branch')->where('student_id', $student->id)
                ->whereIn('status', ['requested', 'submitted'])
                ->with(['incident' => fn ($q) => $q->withoutGlobalScope('branch')->select('id', 'occurred_at', 'incident_no', 'status', 'location')])
                ->orderByDesc('requested_at')->limit(30)->get();
        }
        $db = $behaviors($defenses->pluck('incident_id')->all());

        return response()->json(['data' => [
            'enabled' => true,
            'can_submit_defense' => $isStudent && $settings['portal_defense_submission'],
            'sanctions' => $sanctions->map(fn (DisciplineSanction $s) => [
                'id' => $s->id,
                'type' => $s->type?->name,
                'tone' => $s->type?->tone,
                'status' => $s->status,
                'status_label' => C::SANCTION_STATUSES[$s->status] ?? $s->status,
                'decided_at' => $s->decided_at?->toAtomString(),
                'occurred_at' => $s->incident?->occurred_at?->toAtomString(),
                'behavior' => ($sb[$s->incident_id] ?? collect())->pluck('name')->implode(', '),
                'starts_on' => $s->starts_on?->toDateString(),
                'ends_on' => $s->ends_on?->toDateString(),
                'days' => $s->days,
                'duty_description' => $s->duty_description,
                'expires_on' => $s->expires_on?->toDateString(),
            ])->values(),
            'defenses' => $defenses->map(fn (DisciplineDefense $d) => [
                'id' => $d->id,
                'status' => $d->status,
                'status_label' => C::DEFENSE_STATUSES[$d->status] ?? $d->status,
                'overdue' => $d->isOverdue(),
                'occurred_at' => $d->incident?->occurred_at?->toAtomString(),
                'location' => $d->incident?->location,
                'behavior' => ($db[$d->incident_id] ?? collect())->pluck('name')->implode(', '),
                'requested_at' => $d->requested_at?->toAtomString(),
                'due_on' => $d->due_on?->toDateString(),
                'request_note' => $d->request_note,
                'statement' => $d->statement,
                'submitted_at' => $d->submitted_at?->toAtomString(),
                'can_submit' => $isStudent && $settings['portal_defense_submission'] && $d->status === 'requested'
                    && $d->incident?->status !== 'closed',
            ])->values(),
        ]]);
    }

    public function submitDefense(Request $request, int $defense, DisciplineService $discipline): JsonResponse
    {
        /** @var Student $student */
        $student = $request->attributes->get(EnsurePortalStudent::ATTRIBUTE);
        if (! $request->user()->isStudent()) {
            throw new BusinessRuleException('Savunmayı yalnız öğrenci kendi hesabından yazabilir.', 'discipline_portal_student_only', [], 403);
        }
        $settings = DisciplineSettings::all((int) $student->branch_id);
        if (! $settings['portal_enabled'] || ! $settings['portal_defense_submission']) {
            throw new BusinessRuleException('Kurum portal üzerinden savunma almayı kapatmış. Savunmanızı kuruma yazılı teslim edin.', 'discipline_portal_closed', [], 403);
        }
        $data = $request->validate(['statement' => ['required', 'string', 'min:20', 'max:10000']],
            ['statement.required' => 'Savunma metnini yazın.', 'statement.min' => 'Savunma en az :min karakter olmalı.', 'statement.max' => 'Savunma en fazla :max karakter olabilir.']);

        $row = DisciplineDefense::query()->withoutGlobalScope('branch')->whereKey($defense)->where('student_id', $student->id)->first();
        if (! $row) {
            return response()->json(['message' => 'Kayıt bulunamadı.', 'error_code' => 'not_found'], 404);
        }
        if ($row->status !== 'requested' || ! $row->incident || $row->incident->status === 'closed') {
            throw new BusinessRuleException('Bu savunma artık yazılamaz.', 'discipline_defense_closed', [], 422);
        }
        $discipline->recordDefense($row, $data['statement'], $request->user(), 'portal');

        return $this->ok('Savunmanız kaydedildi. Teşekkür ederiz.');
    }
}

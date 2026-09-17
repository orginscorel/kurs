<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Http\Controllers\Api\ApiController;
use App\Models\DisciplineAppeal;
use App\Models\DisciplineIncident;
use App\Models\DisciplineSanction;
use App\Services\Discipline\DisciplineService;
use App\Support\Discipline\DisciplineCatalog as C;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Yaptırım verme / öneri, durum (tamamla, iptal), itiraz ve itiraz kararı. */
class SanctionController extends ApiController
{
    public function __construct(private readonly DisciplineService $discipline) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', 'max:60'], 'student_id' => ['nullable', 'integer'], 'type_id' => ['nullable', 'integer']]);
        $query = DisciplineSanction::query()->with(['student:id,full_name,student_no', 'type', 'incident:id,incident_no', 'decider:id,name', 'meeting:id,meeting_no']);
        if ($s = $request->query('status')) {
            $query->whereIn('status', array_intersect(explode(',', $s), array_keys(C::SANCTION_STATUSES)));
        }
        if ($sid = $request->integer('student_id')) {
            $query->where('student_id', $sid);
        }
        if ($t = $request->integer('type_id')) {
            $query->where('sanction_type_id', $t);
        }
        $this->applySort($query, $request, ['decided_at' => 'decided_at', 'starts_on' => 'starts_on', 'id' => 'id'], '-id');

        return $this->paginated($query->paginate($this->perPage($request)), fn ($x) => DisciplinePresenter::sanction($x));
    }

    public function store(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'sanction_type_id' => ['required', 'integer', Rule::exists('discipline_sanction_types', 'id')],
            'decision_note' => ['nullable', 'string', 'max:3000'],
            'duty_description' => ['nullable', 'string', 'max:300'],
            'starts_on' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'visible_to_portal' => ['sometimes', 'boolean'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $sanction = $this->discipline->decideSanction($incident, $data, $request->user());

        return response()->json([
            'message' => $sanction->status === 'proposed' ? 'Yaptırım kurula önerildi; kurul kararıyla yürürlüğe girer.' : 'Yaptırım verildi.',
            'id' => $sanction->id, 'status' => $sanction->status,
        ], 201);
    }

    public function status(Request $request, DisciplineSanction $sanction): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:300'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        if ($sanction->type->authority === 'board' && $sanction->status !== 'proposed' && ! $request->user()->can('discipline.board')) {
            return response()->json(['message' => 'Kurul kararıyla verilen yaptırımı yalnız disiplin kurulu yetkilisi değiştirebilir.', 'error_code' => 'forbidden'], 403);
        }
        $this->discipline->changeSanctionStatus($sanction, $data['status'], $request->user(), $data['reason'] ?? null);

        return $this->ok('Yaptırım güncellendi.');
    }

    public function appeal(Request $request, DisciplineSanction $sanction): JsonResponse
    {
        $data = $request->validate([
            'appellant' => ['required', Rule::in(['student', 'guardian'])],
            'appealed_on' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:3000'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->discipline->fileAppeal($sanction, $data, $request->user());

        return $this->ok('İtiraz kaydedildi. Yaptırım "itirazda" durumuna alındı.');
    }

    public function decideAppeal(Request $request, DisciplineAppeal $appeal): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'rejected', 'modified'])],
            'result_note' => ['nullable', 'string', 'max:2000'],
            'new_sanction_type_id' => ['required_if:status,modified', 'nullable', 'integer'],
            'starts_on' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ], DisciplinePresenter::messages() + ['new_sanction_type_id.required_if' => 'Yeni (daha hafif) yaptırımı seçin.'], DisciplinePresenter::attributes());
        $this->discipline->decideAppeal($appeal, $data, $request->user());

        return $this->ok('İtiraz karara bağlandı.');
    }
}

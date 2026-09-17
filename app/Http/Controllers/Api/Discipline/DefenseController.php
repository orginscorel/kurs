<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Http\Controllers\Api\ApiController;
use App\Models\DisciplineDefense;
use App\Models\DisciplineIncident;
use App\Services\Discipline\DisciplinePdf;
use App\Services\Discipline\DisciplineService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Savunma istemi / kaydı / "alınmadı" işareti ve savunma istem yazısı PDF'i. */
class DefenseController extends ApiController
{
    public function __construct(private readonly DisciplineService $discipline) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(['requested', 'submitted', 'waived', 'overdue'])]]);
        $status = $request->query('status', 'requested');
        $query = DisciplineDefense::query()->with(['student:id,full_name,student_no', 'incident:id,incident_no,occurred_at', 'requester:id,name'])
            ->when($status === 'overdue', fn ($q) => $q->where('status', 'requested')->where('due_on', '<', today()->toDateString()))
            ->when($status !== 'overdue', fn ($q) => $q->where('status', $status))
            ->orderBy($status === 'submitted' ? 'submitted_at' : 'due_on', $status === 'submitted' ? 'desc' : 'asc');

        return $this->paginated($query->paginate($this->perPage($request)), fn ($d) => DisciplinePresenter::defense($d));
    }

    public function store(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'due_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $defense = $this->discipline->requestDefense($incident, (int) $data['student_id'], $data['due_on'] ?? null, $data['note'] ?? null, $request->user());

        return response()->json(['message' => 'Savunma istendi. İstem yazısını yazdırabilirsiniz.', 'id' => $defense->id], 201);
    }

    public function record(Request $request, DisciplineDefense $defense): JsonResponse
    {
        $data = $request->validate([
            'statement' => ['required', 'string', 'min:3', 'max:10000'],
            'submitted_at' => ['nullable', 'date'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->discipline->recordDefense($defense, $data['statement'], $request->user(), 'staff', $data['submitted_at'] ?? null);

        return $this->ok('Savunma kaydedildi.');
    }

    public function waive(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->discipline->waiveDefense($incident, (int) $data['student_id'], $data['reason'], $request->user());

        return $this->ok('Savunma "alınmadı" olarak işaretlendi.');
    }

    public function pdf(Request $request, DisciplineDefense $defense, DisciplinePdf $pdf): Response
    {
        Audit::log('discipline.defense_pdf', "{$defense->incident?->incident_no} savunma istem yazısını oluşturdu ({$defense->student?->full_name}).", $defense->incident);

        return $pdf->defenseRequest($defense, $request->boolean('download'));
    }
}

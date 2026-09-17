<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Http\Controllers\Api\ApiController;
use App\Models\DisciplineIncident;
use App\Models\Document;
use App\Models\Student;
use App\Services\Discipline\DisciplineNotifier;
use App\Services\Discipline\DisciplineService;
use App\Support\Discipline\DisciplineCatalog as C;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Olay tutanakları: liste, ayrıntı (zaman çizelgesi), kayıt, durum, ek dosya, veli bildirim taslağı. */
class IncidentController extends ApiController
{
    public function __construct(private readonly DisciplineService $discipline) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:40'], 'kind' => ['nullable', Rule::in(['negative', 'positive'])],
            'severity' => ['nullable', Rule::in(array_keys(C::SEVERITIES))], 'class_group_id' => ['nullable', 'integer'],
            'behavior_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'source' => ['nullable', Rule::in(['staff', 'teacher_portal'])],
        ]);
        $query = DisciplineIncident::query()
            ->with(['participants.student:id,full_name,student_no', 'participants.behavior:id,name', 'reporter:id,name', 'teacher:id,first_name,last_name', 'classGroup:id,name'])
            ->withCount(['sanctions' => fn ($q) => $q->whereNotIn('status', ['cancelled'])]);

        if ($status = $request->query('status')) {
            $query->whereIn('status', array_intersect(explode(',', $status), array_keys(C::INCIDENT_STATUSES)));
        }
        foreach (['kind', 'severity', 'source'] as $f) {
            if ($v = $request->query($f)) {
                $query->where($f, $v);
            }
        }
        if ($cg = $request->integer('class_group_id')) {
            $query->whereHas('participants', fn (Builder $p) => $p->whereExists(fn ($e) => $e->from('class_group_student as x')
                ->whereColumn('x.student_id', 'discipline_incident_students.student_id')->where('x.class_group_id', $cg)->whereNull('x.left_on')));
        }
        if ($b = $request->integer('behavior_id')) {
            $query->whereHas('participants', fn (Builder $p) => $p->where('behavior_id', $b));
        }
        if ($sid = $request->integer('student_id')) {
            $query->whereHas('participants', fn (Builder $p) => $p->where('student_id', $sid));
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn (Builder $w) => $w->where('incident_no', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")
                ->orWhereHas('participants.student', fn (Builder $s) => $s->where('full_name', 'like', "%{$q}%")->orWhere('student_no', 'like', "%{$q}%")));
        }
        $this->applyDateRange($query, $request, 'occurred_at');
        $this->applySort($query, $request, ['occurred_at' => 'occurred_at', 'incident_no' => 'incident_no', 'severity' => 'severity', 'status' => 'status'], '-occurred_at');

        $paginator = $query->paginate($this->perPage($request));
        $summary = DisciplineIncident::query()->selectRaw("status, COUNT(*) AS c")->where('kind', 'negative')->groupBy('status')->pluck('c', 'status');

        return $this->paginated($paginator, fn (DisciplineIncident $i) => DisciplinePresenter::incidentRow($i), [
            'status_counts' => collect(C::INCIDENT_STATUSES)->map(fn ($l, $k) => (int) ($summary[$k] ?? 0)),
        ]);
    }

    public function show(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $incident->load([
            'participants.student:id,full_name,student_no,photo_path,status', 'participants.behavior', 'reporter:id,name',
            'teacher:id,first_name,last_name', 'subject:id,name', 'classGroup:id,name',
            'sanctions' => fn ($q) => $q->with(['type', 'decider:id,name', 'meeting:id,meeting_no', 'appeals.decider:id,name', 'student:id,full_name,student_no'])->orderBy('id'),
            'defenses' => fn ($q) => $q->with(['requester:id,name', 'student:id,full_name,student_no']),
            'events' => fn ($q) => $q->with('user:id,name')->orderByDesc('id'),
            'documents' => fn ($q) => $q->with('uploader:id,name')->orderByDesc('id'),
            'boardItems.meeting:id,meeting_no,scheduled_at,status',
        ]);
        $standing = app(\App\Services\Discipline\DisciplineStanding::class)->forStudents($incident->participants->pluck('student_id')->all());
        $classes = \Illuminate\Support\Facades\DB::table('class_group_student as x')->join('class_groups as g', 'g.id', '=', 'x.class_group_id')
            ->whereIn('x.student_id', $incident->participants->pluck('student_id'))->whereNull('x.left_on')->pluck('g.name', 'x.student_id');

        return response()->json(['data' => DisciplinePresenter::incidentRow($incident) + [
            'description' => $incident->description,
            'witnesses' => $incident->witnesses,
            'class_group_id' => $incident->class_group_id,
            'subject_id' => $incident->subject_id,
            'teacher_id' => $incident->teacher_id,
            'guardian_notified_via' => $incident->guardian_notified_via,
            'decided_at' => $incident->decided_at?->toAtomString(),
            'closed_at' => $incident->closed_at?->toAtomString(),
            'transitions' => C::INCIDENT_TRANSITIONS[$incident->status] ?? [],
            'participants' => $incident->participants->map(fn ($p) => [
                'student_id' => $p->student_id, 'full_name' => $p->student?->full_name, 'student_no' => $p->student?->student_no,
                'class_name' => $classes[$p->student_id] ?? null,
                'role' => $p->role, 'role_label' => C::ROLES[$p->role] ?? $p->role,
                'behavior_id' => $p->behavior_id, 'behavior' => $p->behavior?->name, 'suggested_sanction' => $p->behavior?->suggested_sanction,
                'penalty_points' => $p->penalty_points, 'merit_points' => $p->merit_points, 'note' => $p->note,
                'standing' => $standing[$p->student_id] ?? null,
            ])->values(),
            'sanctions' => $incident->sanctions->map(fn ($s) => DisciplinePresenter::sanction($s))->values(),
            'defenses' => $incident->defenses->map(fn ($d) => DisciplinePresenter::defense($d))->values(),
            'events' => $incident->events->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'message' => $e->message, 'user' => $e->user?->name, 'created_at' => $e->created_at?->toAtomString()])->values(),
            'documents' => $incident->documents->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'mime_type' => $d->mime_type, 'size' => $d->size, 'uploaded_by' => $d->uploader?->name, 'created_at' => $d->created_at?->toAtomString()])->values(),
            'board_items' => $incident->boardItems->map(fn ($b) => ['id' => $b->id, 'meeting_id' => $b->meeting_id, 'meeting_no' => $b->meeting?->meeting_no,
                'scheduled_at' => $b->meeting?->scheduled_at?->toAtomString(), 'result' => $b->result])->values(),
        ]]);
    }

    private function rules(bool $create): array
    {
        return [
            'occurred_at' => [$create ? 'required' : 'sometimes', 'date'],
            'location' => ['nullable', 'string', 'max:120'],
            'class_group_id' => ['nullable', 'integer', Rule::exists('class_groups', 'id')],
            'subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'witnesses' => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', Rule::in(array_keys(C::SEVERITIES))],
            'students' => [$create ? 'required' : 'sometimes', 'array', 'min:1', 'max:30'],
            'students.*.student_id' => ['required', 'integer'],
            'students.*.behavior_id' => ['nullable', 'integer'],
            'students.*.role' => ['nullable', Rule::in(array_keys(C::ROLES))],
            'students.*.note' => ['nullable', 'string', 'max:500'],
            'quick_sanction_type_id' => ['nullable', 'integer'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true), DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        if (! empty($data['quick_sanction_type_id']) && ! $request->user()->can('discipline.decide')) {
            unset($data['quick_sanction_type_id']);
        }
        $incident = $this->discipline->createIncident($data, $request->user());

        return response()->json([
            'message' => $incident->kind === 'positive' ? 'Olumlu davranış kaydedildi.' : 'Olay kaydedildi.',
            'id' => $incident->id, 'incident_no' => $incident->incident_no,
        ], 201);
    }

    public function update(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate($this->rules(false), DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        if (in_array($incident->status, ['closed'], true) && ! $request->user()->can('discipline.decide')) {
            return response()->json(['message' => 'Kapanmış olay yalnız karar yetkilisi tarafından düzenlenebilir.', 'error_code' => 'forbidden'], 403);
        }
        $this->discipline->updateIncident($incident, $data, $request->user());

        return $this->ok('Olay güncellendi.');
    }

    public function status(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(C::INCIDENT_STATUSES))],
            'note' => ['nullable', 'string', 'max:500'],
            'outcome' => ['nullable', Rule::in(['unfounded'])],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        // Kapatma / karara bağlama / yeniden açma karar yetkisi ister; incelemeye alma oluşturma yetkisiyle yapılır
        if ($data['status'] !== 'review' && ! $request->user()->can('discipline.decide')) {
            return response()->json(['message' => 'Bu durum değişikliği için karar yetkisi gerekir.', 'error_code' => 'forbidden'], 403);
        }
        $this->discipline->changeStatus($incident, $data['status'], $request->user(), $data['note'] ?? null, $data['outcome'] ?? null);

        return $this->ok('Durum güncellendi: '.C::INCIDENT_STATUSES[$data['status']].'.');
    }

    public function points(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'], 'points' => ['required', 'integer', 'min:0', 'max:100'], 'reason' => ['required', 'string', 'max:300'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->discipline->adjustPoints($incident, (int) $data['student_id'], (int) $data['points'], $data['reason'], $request->user());

        return $this->ok('Puan düzeltildi.');
    }

    public function destroy(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->discipline->deleteIncident($incident, $request->user(), $data['reason']);

        return $this->ok('Kayıt silindi.');
    }

    public function upload(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'title' => ['nullable', 'string', 'max:150'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        if ($incident->documents()->count() >= 20) {
            return response()->json(['message' => 'Bir olaya en fazla 20 dosya eklenebilir.', 'error_code' => 'too_many_files'], 422);
        }
        $doc = $this->discipline->attach($incident, $request->file('file'), $request->user(), $request->input('title'));

        return response()->json(['message' => 'Dosya eklendi.', 'id' => $doc->id], 201);
    }

    public function download(DisciplineIncident $incident, int $document): StreamedResponse
    {
        $doc = $incident->documents()->whereKey($document)->firstOrFail();
        abort_unless(Storage::disk($doc->disk ?: 'local')->exists($doc->path), 404);
        \App\Support\Audit::log('discipline.attachment_viewed', "{$incident->incident_no} olayının ek dosyasını açtı ({$doc->title}).", $incident);
        $ext = pathinfo($doc->path, PATHINFO_EXTENSION);
        $name = \Illuminate\Support\Str::slug(pathinfo($doc->title, PATHINFO_FILENAME) ?: 'ek').($ext ? '.'.$ext : '');

        return Storage::disk($doc->disk ?: 'local')->response($doc->path, $name, ['Content-Type' => $doc->mime_type ?: 'application/octet-stream'], 'inline');
    }

    public function removeDocument(Request $request, DisciplineIncident $incident, int $document): JsonResponse
    {
        /** @var Document $doc */
        $doc = $incident->documents()->whereKey($document)->firstOrFail();
        $this->discipline->detach($incident, $doc, $request->user());

        return $this->ok('Dosya silindi.');
    }

    public function notifyDraft(Request $request, DisciplineIncident $incident, DisciplineNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(['sanction', 'defense', 'positive'])],
            'sanction_id' => ['nullable', 'integer'],
        ]);
        $student = Student::query()->findOrFail($data['student_id']);
        abort_unless($incident->participants()->where('student_id', $student->id)->exists(), 404);
        $sanction = ! empty($data['sanction_id']) ? $incident->sanctions()->with('type')->whereKey($data['sanction_id'])->where('student_id', $student->id)->firstOrFail() : null;
        if ($data['kind'] === 'sanction' && ! $sanction) {
            $sanction = $incident->sanctions()->with('type')->where('student_id', $student->id)->whereIn('status', ['active', 'appealed', 'completed'])->latest('id')->first();
        }

        return response()->json(['data' => $notifier->draft($incident, $student, $data['kind'], $request->user()->can('students.view_sensitive'), $sanction)
            + ['notified_at' => $incident->guardian_notified_at?->toAtomString()]]);
    }

    public function notified(Request $request, DisciplineIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'via' => ['required', Rule::in(['phone', 'meeting', 'whatsapp', 'letter', 'other'])],
            'sanction_id' => ['nullable', 'integer'],
        ], DisciplinePresenter::messages(), DisciplinePresenter::attributes());
        $this->discipline->markGuardianNotified($incident, $data['via'], $request->user(), $data['sanction_id'] ?? null);

        return $this->ok('Veli bilgilendirmesi kayda geçti.');
    }
}

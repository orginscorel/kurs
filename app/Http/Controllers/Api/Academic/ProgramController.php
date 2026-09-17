<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Program;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgramController extends ApiController
{
    public const KINDS = ['group' => 'Grup dersi', 'private' => 'Birebir', 'study' => 'Etüt'];

    public const TRACKS = ['TYT' => 'TYT', 'AYT_SAY' => 'AYT Sayısal', 'AYT_EA' => 'AYT Eşit Ağırlık', 'AYT_SOZ' => 'AYT Sözel', 'AYT_DIL' => 'AYT Dil', 'LGS' => 'LGS', 'NONE' => 'Sınav hedefi yok'];

    public function index(Request $request): JsonResponse
    {
        $query = Program::query()
            ->withCount(['classGroups as class_groups_count' => fn ($q) => $q->where('is_active', true), 'subjects'])
            ->addSelect(['students_count' => DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
                ->selectRaw('COUNT(DISTINCT cgs.student_id)')->whereColumn('cg.program_id', 'programs.id')->whereNull('cgs.left_on')->whereNull('cg.deleted_at')])
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('status', 'active') === 'active', fn ($q) => $q->where('is_active', true));
        $this->applySort($query, $request, ['name' => 'name', 'code' => 'code', 'kind' => 'kind', 'class_groups_count' => 'class_groups_count', 'students_count' => 'students_count'], 'name');

        return $this->paginated($query->paginate($this->perPage($request, 50)), fn (Program $p) => [
            ...$p->only(['id', 'code', 'name', 'kind', 'exam_track', 'color', 'description', 'is_active']),
            'kind_label' => self::KINDS[$p->kind] ?? $p->kind, 'track_label' => self::TRACKS[$p->exam_track] ?? null,
            'class_groups_count' => (int) $p->class_groups_count, 'subjects_count' => (int) $p->subjects_count, 'students_count' => (int) $p->students_count,
            'weekly_hours' => (int) DB::table('program_subject')->where('program_id', $p->id)->sum('weekly_hours'),
        ], ['kinds' => self::KINDS, 'tracks' => self::TRACKS]);
    }

    public function show(Program $program): JsonResponse
    {
        $program->load(['subjects' => fn ($q) => $q->orderBy('name')]);
        $groups = $program->classGroups()->with(['term:id,name', 'homeroom:id,name', 'advisor:id,first_name,last_name'])
            ->withCount(['activeStudents as students_count'])->orderBy('name')->get();

        $teachersFromSchedules = DB::table('lesson_schedules as ls')->join('teachers as t', 't.id', '=', 'ls.teacher_id')->join('subjects as s', 's.id', '=', 'ls.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')->where('cg.program_id', $program->id)->whereNull('ls.deleted_at')->whereNull('cg.deleted_at')
            ->groupBy('t.id', 't.first_name', 't.last_name', 't.color', 's.id', 's.name')
            ->get(['t.id', 't.first_name', 't.last_name', 't.color', 's.id as subject_id', 's.name as subject', DB::raw('COUNT(*) as lessons')]);
        $assigned = DB::table('program_subject_teacher as pst')->join('teachers as t', 't.id', '=', 'pst.teacher_id')->join('subjects as s', 's.id', '=', 'pst.subject_id')
            ->where('pst.program_id', $program->id)->get(['t.id', 't.first_name', 't.last_name', 't.color', 's.id as subject_id', 's.name as subject']);

        $teachers = collect();
        foreach ($teachersFromSchedules->concat($assigned) as $r) {
            $key = $r->id;
            $entry = $teachers->get($key, ['id' => $r->id, 'name' => "{$r->first_name} {$r->last_name}", 'color' => $r->color, 'subjects' => [], 'lessons' => 0]);
            if (! in_array($r->subject, $entry['subjects'], true)) {
                $entry['subjects'][] = $r->subject;
            }
            $entry['lessons'] += (int) ($r->lessons ?? 0);
            $teachers->put($key, $entry);
        }

        return response()->json([
            'program' => [...$program->only(['id', 'code', 'name', 'kind', 'exam_track', 'color', 'description', 'is_active']), 'kind_label' => self::KINDS[$program->kind] ?? $program->kind, 'track_label' => self::TRACKS[$program->exam_track] ?? null],
            'subjects' => $program->subjects->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'color' => $s->color, 'weekly_hours' => (int) $s->pivot->weekly_hours, 'curriculum' => $s->pivot->curriculum]),
            'class_groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'term' => $g->term?->name, 'capacity' => $g->capacity, 'students_count' => (int) $g->students_count, 'homeroom' => $g->homeroom?->name, 'advisor' => $g->advisor?->full_name, 'is_active' => $g->is_active]),
            'teachers' => $teachers->sortBy('name')->values(),
            'packages' => $program->packages()->where('is_active', true)->get(['id', 'name', 'list_price', 'default_installments']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $program = Program::query()->create($data);
        Audit::log('program.created', "{$program->name} programını oluşturdu.", $program);

        return response()->json(['message' => 'Program oluşturuldu.', 'id' => $program->id], 201);
    }

    public function update(Request $request, Program $program): JsonResponse
    {
        $program->fill($this->validated($request, $program))->save();
        $changes = Audit::diff($program);
        if ($changes['after'] !== []) {
            Audit::log('program.updated', "{$program->name} programını güncelledi.", $program, $changes);
        }

        return $this->ok('Program güncellendi.');
    }

    public function destroy(Program $program): JsonResponse
    {
        if ($program->classGroups()->exists()) {
            throw new BusinessRuleException('Bu programa bağlı sınıflar var; önce sınıfları başka programa taşıyın ya da programı pasife alın.', 'program_has_groups');
        }
        if (DB::table('enrollments')->where('program_id', $program->id)->exists()) {
            throw new BusinessRuleException('Bu programla yapılmış kayıtlar var; program silinemez, pasife alın.', 'program_has_enrollments');
        }
        $program->forceFill(['is_active' => false])->save();
        $program->delete();
        Audit::log('program.deleted', "{$program->name} programını sildi.", $program);

        return $this->ok('Program silindi.');
    }

    /** Programın ders listesi: haftalık saat + müfredat notu (tam eşitleme). */
    public function syncSubjects(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'subjects' => ['present', 'array', 'max:40'],
            'subjects.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'subjects.*.weekly_hours' => ['nullable', 'integer', 'min:0', 'max:40'],
            'subjects.*.curriculum' => ['nullable', 'string', 'max:2000'],
        ], [], ['subjects' => 'Dersler', 'subjects.*.subject_id' => 'Ders', 'subjects.*.weekly_hours' => 'Haftalık saat', 'subjects.*.curriculum' => 'Müfredat notu']);
        $sync = [];
        foreach ($data['subjects'] as $row) {
            $sync[(int) $row['subject_id']] = ['weekly_hours' => (int) ($row['weekly_hours'] ?? 0), 'curriculum' => $row['curriculum'] ?? null];
        }
        $program->subjects()->sync($sync);
        Audit::log('program.subjects_updated', "{$program->name} programının ders listesini güncelledi (".count($sync).' ders).', $program);

        return $this->ok('Ders listesi güncellendi.');
    }

    private function validated(Request $request, ?Program $program = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_\-]+$/u', Rule::unique('programs', 'code')->where('branch_id', $program?->branch_id ?? app(\App\Support\BranchContext::class)->id())->ignore($program?->id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'exam_track' => ['nullable', Rule::in(array_keys(self::TRACKS))],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], ['code.regex' => 'Kod yalnızca büyük harf, rakam, tire ve alt çizgi içerebilir.', 'code.unique' => 'Bu kod başka bir programda kullanılıyor.'], [
            'code' => 'Program kodu', 'name' => 'Program adı', 'kind' => 'Program türü', 'exam_track' => 'Sınav alanı', 'color' => 'Renk', 'description' => 'Açıklama', 'is_active' => 'Durum',
        ]);
    }
}

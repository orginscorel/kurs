<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Subject;
use App\Models\Topic;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubjectController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Subject::query()->withCount(['topics', 'teachers'])
            ->addSelect(['weekly_lessons' => DB::table('lesson_schedules')->selectRaw('COUNT(*)')->whereColumn('subject_id', 'subjects.id')->whereNull('deleted_at')])
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('code', 'like', "%$s%")))
            ->when($request->query('status', 'active') === 'active', fn ($q) => $q->where('is_active', true));
        $this->applySort($query, $request, ['name' => 'name', 'code' => 'code', 'topics_count' => 'topics_count', 'teachers_count' => 'teachers_count', 'weekly_lessons' => 'weekly_lessons'], 'name');

        return $this->paginated($query->paginate($this->perPage($request, 50)), fn (Subject $s) => [
            ...$s->only(['id', 'code', 'name', 'short_name', 'color', 'is_active']),
            'topics_count' => (int) $s->topics_count, 'teachers_count' => (int) $s->teachers_count, 'weekly_lessons' => (int) $s->weekly_lessons,
        ]);
    }

    public function show(Subject $subject): JsonResponse
    {
        $topics = $subject->topics()->orderBy('sort')->orderBy('id')->get(['id', 'parent_id', 'name', 'outcome_code', 'sort']);
        $stats = DB::table('student_topic_stats')->whereIn('topic_id', $topics->pluck('id'))->groupBy('topic_id')
            ->selectRaw('topic_id, SUM(asked) AS asked, SUM(correct) AS correct')->get()->keyBy('topic_id');

        return response()->json([
            'subject' => $subject->only(['id', 'code', 'name', 'short_name', 'color', 'is_active']),
            'topics' => $topics->map(fn ($t) => [
                ...$t->toArray(),
                'asked' => (int) ($stats[$t->id]->asked ?? 0),
                'success_rate' => isset($stats[$t->id]) && $stats[$t->id]->asked > 0 ? round($stats[$t->id]->correct / $stats[$t->id]->asked * 100) : null,
            ]),
            'teachers' => $subject->teachers()->orderBy('first_name')->get(['teachers.id', 'first_name', 'last_name', 'color', 'title', 'is_active'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->full_name, 'color' => $t->color, 'title' => $t->title, 'is_active' => $t->is_active]),
            'programs' => DB::table('program_subject as ps')->join('programs as p', 'p.id', '=', 'ps.program_id')->where('ps.subject_id', $subject->id)->whereNull('p.deleted_at')
                ->get(['p.id', 'p.name', 'p.color', 'ps.weekly_hours']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $subject = Subject::query()->create($this->validated($request));
        Audit::log('subject.created', "{$subject->name} dersini oluşturdu.", $subject);

        return response()->json(['message' => 'Ders oluşturuldu.', 'id' => $subject->id], 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $subject->fill($this->validated($request, $subject))->save();
        $changes = Audit::diff($subject);
        if ($changes['after'] !== []) {
            Audit::log('subject.updated', "{$subject->name} dersini güncelledi.", $subject, $changes);
        }

        return $this->ok('Ders güncellendi.');
    }

    public function destroy(Subject $subject): JsonResponse
    {
        if (DB::table('lesson_schedules')->where('subject_id', $subject->id)->whereNull('deleted_at')->exists() || DB::table('lesson_sessions')->where('subject_id', $subject->id)->exists()) {
            throw new BusinessRuleException('Ders programında kullanılan ders silinemez; pasife alın.', 'subject_in_use');
        }
        if (DB::table('homework')->where('subject_id', $subject->id)->exists()) {
            throw new BusinessRuleException('Bu derse bağlı ödevler var; ders silinemez, pasife alın.', 'subject_in_use');
        }
        $subject->delete();
        Audit::log('subject.deleted', "{$subject->name} dersini sildi.", $subject);

        return $this->ok('Ders silindi.');
    }

    /** Öğretmen–ders ilişkisi (teacher_subject) tam eşitleme. */
    public function syncTeachers(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate(['teacher_ids' => ['present', 'array', 'max:200'], 'teacher_ids.*' => ['integer', Rule::exists('teachers', 'id')]]);
        $subject->teachers()->sync($data['teacher_ids']);
        Audit::log('subject.teachers_updated', "{$subject->name} dersinin öğretmen listesini güncelledi (".count($data['teacher_ids']).' öğretmen).', $subject);

        return $this->ok('Öğretmen listesi güncellendi.');
    }

    // ------------------------------------------------------------------ konular / kazanımlar

    public function storeTopic(Request $request, Subject $subject): JsonResponse
    {
        $data = $this->validatedTopic($request, $subject);
        $data['sort'] = $data['sort'] ?? ((int) $subject->topics()->where('parent_id', $data['parent_id'] ?? null)->max('sort') + 1);
        $topic = $subject->topics()->create($data);

        return response()->json(['message' => 'Konu eklendi.', 'id' => $topic->id, 'data' => $topic], 201);
    }

    public function updateTopic(Request $request, Subject $subject, Topic $topic): JsonResponse
    {
        abort_unless($topic->subject_id === $subject->id, 404);
        $data = $this->validatedTopic($request, $subject, $topic);
        if (($data['parent_id'] ?? null) === $topic->id) {
            throw new BusinessRuleException('Konu kendi alt konusu olamaz.', 'topic_self_parent');
        }
        $topic->fill($data)->save();

        return $this->ok('Konu güncellendi.');
    }

    public function destroyTopic(Subject $subject, Topic $topic): JsonResponse
    {
        abort_unless($topic->subject_id === $subject->id, 404);
        if ($topic->children()->exists()) {
            throw new BusinessRuleException('Alt konuları olan konu silinemez; önce alt konuları silin ya da taşıyın.', 'topic_has_children');
        }
        $topic->delete();

        return $this->ok('Konu silindi.');
    }

    /** Konu sıralaması: [{id, sort, parent_id}] */
    public function reorderTopics(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:500'], 'items.*.id' => ['required', 'integer'], 'items.*.sort' => ['required', 'integer', 'min:0'], 'items.*.parent_id' => ['nullable', 'integer']]);
        DB::transaction(function () use ($subject, $data) {
            foreach ($data['items'] as $row) {
                $subject->topics()->whereKey($row['id'])->update(['sort' => $row['sort'], 'parent_id' => $row['parent_id'] ?? null]);
            }
        });

        return $this->ok('Sıralama kaydedildi.');
    }

    private function validated(Request $request, ?Subject $subject = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_\-]+$/u', Rule::unique('subjects', 'code')->where('branch_id', $subject?->branch_id ?? app(\App\Support\BranchContext::class)->id())->ignore($subject?->id)],
            'name' => ['required', 'string', 'max:80'],
            'short_name' => ['nullable', 'string', 'max:20'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ], ['code.regex' => 'Kod yalnızca büyük harf, rakam, tire ve alt çizgi içerebilir.', 'code.unique' => 'Bu kod başka bir derste kullanılıyor.'], [
            'code' => 'Ders kodu', 'name' => 'Ders adı', 'short_name' => 'Kısa ad', 'color' => 'Renk', 'is_active' => 'Durum',
        ]);
    }

    private function validatedTopic(Request $request, Subject $subject, ?Topic $topic = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'parent_id' => ['nullable', 'integer', Rule::exists('topics', 'id')->where('subject_id', $subject->id)],
            'outcome_code' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ], [], ['name' => 'Konu adı', 'parent_id' => 'Üst konu', 'outcome_code' => 'Kazanım kodu', 'sort' => 'Sıra']);
    }
}

<?php

namespace App\Http\Controllers\Api\ClassroomDesign;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\Classroom;
use App\Models\ClassroomLayout;
use App\Models\ClassroomLayoutVersion;
use App\Services\ClassroomDesign\SampleLayout;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 3D derslik tasarımı ve oturma düzeni.
 * Okuma academic.view; yazma classroom_layouts.manage. Her "Kaydet" yeni sürüm (V1, V2…) üretir.
 */
class ClassroomLayoutController extends ApiController
{
    /** Kaydedilen JSON için üst sınır (bayt) */
    private const MAX_DATA_BYTES = 2_000_000;

    /** Küçük görsel üst sınırı (base64 metin) */
    private const MAX_THUMB_BYTES = 600_000;

    public function index(Request $request): JsonResponse
    {
        $this->ensureSample($request);

        $query = ClassroomLayout::query()
            ->leftJoin('classrooms as c', 'c.id', '=', 'classroom_layouts.classroom_id')
            ->leftJoin('users as u', 'u.id', '=', 'classroom_layouts.updated_by')
            ->select(['classroom_layouts.id', 'classroom_layouts.uuid', 'classroom_layouts.name', 'classroom_layouts.classroom_id', 'classroom_layouts.version',
                'classroom_layouts.is_active', 'classroom_layouts.is_demo', 'classroom_layouts.stats', 'classroom_layouts.updated_at', 'classroom_layouts.created_at',
                'c.name as classroom_name', 'c.floor as classroom_floor', 'c.capacity as classroom_capacity', 'u.name as updated_by_name',
                DB::raw('CASE WHEN classroom_layouts.thumbnail IS NULL THEN 0 ELSE 1 END AS has_thumbnail')])
            ->when($request->query('classroom_id'), fn ($q, $id) => $q->where('classroom_layouts.classroom_id', (int) $id))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('classroom_layouts.name', 'like', "%$s%")->orWhere('c.name', 'like', "%$s%")));
        $this->applySort($query, $request, ['name' => 'classroom_layouts.name', 'updated_at' => 'classroom_layouts.updated_at'], '-updated_at');

        return $this->paginated($query->paginate($this->perPage($request, 50)), fn (ClassroomLayout $l) => [
            'id' => $l->id,
            'uuid' => $l->uuid,
            'name' => $l->name,
            'classroom' => $l->classroom_id ? ['id' => $l->classroom_id, 'name' => $l->getAttribute('classroom_name'), 'floor' => $l->getAttribute('classroom_floor'),
                'capacity' => $l->getAttribute('classroom_capacity') !== null ? (int) $l->getAttribute('classroom_capacity') : null] : null,
            'version' => $l->version,
            'is_active' => $l->is_active,
            'is_demo' => $l->is_demo,
            'stats' => is_array($l->stats) ? $l->stats : null,
            'thumbnail_url' => (int) $l->getAttribute('has_thumbnail') === 1
                ? '/api/v1/classroom-layouts/'.$l->id.'/thumbnail?v='.$l->version.'-'.optional($l->updated_at)->timestamp : null,
            'updated_at' => optional($l->updated_at)->toIso8601String(),
            'updated_by' => $l->getAttribute('updated_by_name'),
        ]);
    }

    public function show(ClassroomLayout $layout): JsonResponse
    {
        $classroom = $layout->classroom_id
            ? Classroom::query()->withTrashed()->whereKey($layout->classroom_id)->first(['id', 'name', 'floor', 'capacity', 'kind'])
            : null;

        return response()->json(['data' => [
            'id' => $layout->id,
            'uuid' => $layout->uuid,
            'name' => $layout->name,
            'classroom' => $classroom ? $classroom->only(['id', 'name', 'floor', 'capacity', 'kind']) : null,
            'version' => $layout->version,
            'is_active' => $layout->is_active,
            'is_demo' => $layout->is_demo,
            'has_thumbnail' => $layout->thumbnail !== null,
            'data' => $this->maskNames(is_array($layout->data) ? $layout->data : []),
            'stats' => is_array($layout->stats) ? $layout->stats : null,
            'updated_at' => optional($layout->updated_at)->toIso8601String(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $this->validated($request, true);
        $layout = DB::transaction(function () use ($v) {
            $layout = ClassroomLayout::query()->create([
                'name' => $v['name'], 'classroom_id' => $v['classroom_id'] ?? null, 'version' => 1, 'is_active' => true, 'is_demo' => false,
                'data' => $v['data'], 'stats' => $v['stats'] ?? null, 'thumbnail' => $v['thumbnail'] ?? null,
                'created_by' => Auth::id(), 'updated_by' => Auth::id(),
            ]);
            $this->addVersion($layout, 1, $v['label'] ?? 'İlk sürüm');

            return $layout;
        });
        Audit::log('classroom_layout.created', "{$layout->name} derslik tasarımını oluşturdu.", $layout);

        return response()->json(['message' => 'Derslik tasarımı oluşturuldu.', 'id' => $layout->id, 'version' => 1], 201);
    }

    /** Kaydet: tasarımı günceller ve yeni sürüm (V n+1) ekler. base_version eskiyse çakışma (409). */
    public function update(Request $request, ClassroomLayout $layout): JsonResponse
    {
        $v = $this->validated($request, false);
        if (isset($v['base_version']) && (int) $v['base_version'] !== (int) $layout->version) {
            throw new BusinessRuleException(
                "Bu tasarım siz açtıktan sonra başka bir yerde kaydedilmiş (V{$layout->version}). Değişikliklerinizi kaybetmemek için yeni bir kopya olarak kaydedin ya da sayfayı yenileyin.",
                'layout_version_conflict', ['version' => $layout->version], 409,
            );
        }

        $next = DB::transaction(function () use ($layout, $v) {
            $next = $layout->version + 1;
            $layout->fill([
                'name' => $v['name'] ?? $layout->name,
                'classroom_id' => array_key_exists('classroom_id', $v) ? $v['classroom_id'] : $layout->classroom_id,
                'data' => $v['data'], 'stats' => $v['stats'] ?? $layout->stats, 'version' => $next, 'updated_by' => Auth::id(),
            ]);
            if (array_key_exists('thumbnail', $v) && $v['thumbnail'] !== null) {
                $layout->thumbnail = $v['thumbnail'];
            }
            $layout->save();
            $this->addVersion($layout, $next, $v['label'] ?? null);

            return $next;
        });
        Audit::log('classroom_layout.saved', "{$layout->name} derslik tasarımını kaydetti (V{$next}).", $layout);

        return response()->json(['message' => "Kaydedildi (V{$next}).", 'version' => $next, 'updated_at' => optional($layout->updated_at)->toIso8601String()]);
    }

    /** Yalnız küçük görsel (sürüm üretmez) — ör. örnek kaydın ilk açılışı. */
    public function updateThumbnail(Request $request, ClassroomLayout $layout): JsonResponse
    {
        $v = $request->validate(['thumbnail' => ['required', 'string', 'max:'.self::MAX_THUMB_BYTES, 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/']],
            [], ['thumbnail' => 'Küçük görsel']);
        $layout->forceFill(['thumbnail' => $v['thumbnail']])->saveQuietly();

        return $this->ok('Görsel güncellendi.');
    }

    public function thumbnail(ClassroomLayout $layout): Response
    {
        $raw = (string) $layout->thumbnail;
        if (! preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/s', $raw, $m)) {
            abort(404);
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            abort(404);
        }

        return response($binary, 200, ['Content-Type' => $m[1], 'Cache-Control' => 'private, max-age=604800']);
    }

    public function destroy(ClassroomLayout $layout): JsonResponse
    {
        $layout->delete();
        Audit::log('classroom_layout.deleted', "{$layout->name} derslik tasarımını sildi.", $layout);

        return $this->ok('Derslik tasarımı silindi.');
    }

    public function versions(ClassroomLayout $layout): JsonResponse
    {
        $rows = ClassroomLayoutVersion::query()->where('classroom_layout_id', $layout->id)
            ->leftJoin('users as u', 'u.id', '=', 'classroom_layout_versions.created_by')
            ->orderByDesc('classroom_layout_versions.version')
            ->get(['classroom_layout_versions.id', 'classroom_layout_versions.version', 'classroom_layout_versions.label', 'classroom_layout_versions.stats',
                'classroom_layout_versions.created_at', 'u.name as created_by_name']);

        return response()->json(['data' => $rows->map(fn (ClassroomLayoutVersion $r) => [
            'id' => $r->id, 'version' => $r->version, 'label' => $r->label, 'stats' => is_array($r->stats) ? $r->stats : null,
            'created_at' => optional($r->created_at)->toIso8601String(), 'created_by' => $r->getAttribute('created_by_name'),
            'is_current' => $r->version === $layout->version,
        ])->values()]);
    }

    /** Eski sürümü geri yükler: içeriği kopyalanır ve YENİ sürüm olarak kaydedilir (geçmiş silinmez). */
    public function restore(ClassroomLayout $layout, int $version): JsonResponse
    {
        $row = ClassroomLayoutVersion::query()->where('classroom_layout_id', $layout->id)->where('version', $version)->first(['id', 'version', 'data', 'stats']);
        if (! $row) {
            throw new BusinessRuleException('Sürüm bulunamadı.', 'layout_version_missing', [], 404);
        }
        $next = DB::transaction(function () use ($layout, $row) {
            $next = $layout->version + 1;
            $layout->fill(['data' => $row->data, 'stats' => $row->stats, 'version' => $next, 'updated_by' => Auth::id()])->save();
            $this->addVersion($layout, $next, "V{$row->version} geri yüklendi");

            return $next;
        });
        Audit::log('classroom_layout.restored', "{$layout->name} derslik tasarımında V{$row->version} sürümünü geri yükledi (V{$next}).", $layout);

        return response()->json(['message' => "V{$row->version} geri yüklendi (V{$next}).", 'version' => $next]);
    }

    /**
     * Öğrenci ataması için sınıf listesi: dersliği kullanan sınıflar (sınıf dersliği + ders programında bu derslikte dersi olanlar)
     * ve seçilen sınıfların öğrencileri (UUID ile). Öğrenci adları yalnız students.view yetkisiyle döner.
     */
    public function roster(Request $request): JsonResponse
    {
        $v = $request->validate([
            'classroom_id' => ['nullable', 'integer'],
            'group_ids' => ['nullable', 'array', 'max:20'],
            'group_ids.*' => ['integer'],
        ]);
        $classroomId = isset($v['classroom_id']) ? (int) $v['classroom_id'] : null;
        $today = CarbonImmutable::today()->toDateString();

        $counts = DB::table('class_group_student as cgs')->join('students as s', 's.id', '=', 'cgs.student_id')
            ->whereNull('cgs.left_on')->whereNull('s.deleted_at')->whereNotIn('s.status', ['withdrawn', 'graduated'])
            ->groupBy('cgs.class_group_id')->selectRaw('cgs.class_group_id, COUNT(*) AS n')->pluck('n', 'class_group_id');

        $groups = DB::table('class_groups')->where('branch_id', $this->branchId())->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('grade_level')->orderBy('name')->get(['id', 'name', 'homeroom_classroom_id']);

        $weekly = $classroomId ? DB::table('lesson_schedules')->where('classroom_id', $classroomId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->groupBy('class_group_id')->selectRaw('class_group_id, COUNT(*) AS n')->pluck('n', 'class_group_id') : collect();

        $list = $groups->map(fn ($g) => [
            'id' => (int) $g->id,
            'name' => $g->name,
            'student_count' => (int) ($counts[$g->id] ?? 0),
            'homeroom' => $classroomId !== null && (int) $g->homeroom_classroom_id === $classroomId,
            'weekly_lessons' => (int) ($weekly[$g->id] ?? 0),
        ]);
        $using = $list->filter(fn ($g) => $g['homeroom'] || $g['weekly_lessons'] > 0)
            ->sortByDesc(fn ($g) => ($g['homeroom'] ? 1000 : 0) + $g['weekly_lessons'])->values();

        $selected = isset($v['group_ids']) ? array_values(array_map('intval', $v['group_ids'])) : $using->take(1)->pluck('id')->all();
        $selected = array_values(array_intersect($selected, $list->pluck('id')->all()));

        $canNames = (bool) $request->user()?->can('students.view');
        $students = [];
        if ($selected !== [] && $canNames) {
            $groupNames = $list->pluck('name', 'id');
            $students = DB::table('class_group_student as cgs')->join('students as s', 's.id', '=', 'cgs.student_id')
                ->whereIn('cgs.class_group_id', $selected)->whereNull('cgs.left_on')->whereNull('s.deleted_at')
                ->where('s.branch_id', $this->branchId())->whereNotIn('s.status', ['withdrawn', 'graduated'])
                ->orderBy('s.first_name')->orderBy('s.last_name')
                ->get(['s.uuid', 's.first_name', 's.last_name', 's.student_no', 'cgs.class_group_id', 'cgs.joined_on'])
                ->unique('uuid')->filter(fn ($s) => $s->uuid !== null)
                ->map(fn ($s) => [
                    'uuid' => $s->uuid, 'name' => trim($s->first_name.' '.$s->last_name), 'first_name' => $s->first_name, 'last_name' => $s->last_name,
                    'student_no' => $s->student_no, 'group_id' => (int) $s->class_group_id, 'group_name' => $groupNames[$s->class_group_id] ?? '',
                    'joined_on' => $s->joined_on,
                ])->values()->all();
        }

        return response()->json([
            'groups' => $list->values(),
            'using_group_ids' => $using->pluck('id')->values(),
            'selected_group_ids' => $selected,
            'students' => $students,
            'can_view_students' => $canNames,
        ]);
    }

    // ------------------------------------------------------------------ yardımcılar

    private function branchId(): ?int
    {
        return app(\App\Support\BranchContext::class)->id();
    }

    /** İlk açılış: şubede hiç tasarım yoksa (silinmişler dahil) ÖRNEK kayıt oluşturulur. Silinirse geri gelmez. */
    private function ensureSample(Request $request): void
    {
        if (config('kurs.node') !== 'server' || ! $request->user()?->can('classroom_layouts.manage') || $this->branchId() === null) {
            return;
        }
        if (ClassroomLayout::query()->withTrashed()->exists()) {
            return;
        }
        DB::transaction(function () {
            $layout = ClassroomLayout::query()->create([
                'name' => SampleLayout::NAME, 'classroom_id' => null, 'version' => 1, 'is_active' => true, 'is_demo' => true,
                'data' => SampleLayout::data(), 'stats' => SampleLayout::stats(), 'created_by' => Auth::id(), 'updated_by' => Auth::id(),
            ]);
            $this->addVersion($layout, 1, 'Örnek derslik');
        });
    }

    private function addVersion(ClassroomLayout $layout, int $version, ?string $label): void
    {
        ClassroomLayoutVersion::query()->create([
            'branch_id' => $layout->branch_id, 'classroom_layout_id' => $layout->id, 'version' => $version, 'label' => $label,
            'data' => $layout->data, 'stats' => $layout->stats, 'created_by' => Auth::id(),
        ]);
        $keep = ClassroomLayoutVersion::query()->where('classroom_layout_id', $layout->id)->orderByDesc('version')
            ->limit(ClassroomLayout::MAX_VERSIONS)->pluck('version')->all();
        if (count($keep) >= ClassroomLayout::MAX_VERSIONS) {
            ClassroomLayoutVersion::query()->where('classroom_layout_id', $layout->id)->where('version', '<', min($keep))->delete();
        }
    }

    /** students.view yetkisi yoksa oturma düzenindeki öğrenci adları gizlenir (UUID ve yerleşim kalır). */
    private function maskNames(array $data): array
    {
        if (Auth::user()?->can('students.view') || ! isset($data['objects']) || ! is_array($data['objects'])) {
            return $data;
        }
        foreach ($data['objects'] as $i => $o) {
            if (is_array($o) && isset($o['seats']) && is_array($o['seats'])) {
                foreach ($o['seats'] as $k => $seat) {
                    if (is_array($seat)) {
                        $data['objects'][$i]['seats'][$k]['name'] = 'Öğrenci';
                    }
                }
            }
        }

        return $data;
    }

    private function validated(Request $request, bool $creating): array
    {
        $v = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->where('branch_id', $this->branchId())->whereNull('deleted_at')],
            'label' => ['nullable', 'string', 'max:120'],
            'base_version' => ['nullable', 'integer', 'min:0'],
            'thumbnail' => ['nullable', 'string', 'max:'.self::MAX_THUMB_BYTES, 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/'],
            'stats' => ['nullable', 'array'],
            'stats.*' => ['nullable', 'numeric'],
            'data' => ['required', 'array'],
            'data.schema' => ['required', 'integer', Rule::in([1])],
            'data.room' => ['required', 'array'],
            'data.room.polygon' => ['required', 'array', 'min:3', 'max:64'],
            'data.room.polygon.*.x' => ['required', 'numeric', 'between:-200,200'],
            'data.room.polygon.*.z' => ['required', 'numeric', 'between:-200,200'],
            'data.room.ceiling' => ['required', 'numeric', 'between:2,8'],
            'data.room.wallThickness' => ['required', 'numeric', 'between:0.05,1'],
            'data.openings' => ['present', 'array', 'max:80'],
            'data.openings.*.kind' => ['required', Rule::in(['door', 'window'])],
            'data.openings.*.wall' => ['required', 'integer', 'min:0', 'max:63'],
            'data.openings.*.offset' => ['required', 'numeric'],
            'data.openings.*.width' => ['required', 'numeric', 'between:0.2,10'],
            'data.objects' => ['present', 'array', 'max:800'],
            'data.objects.*.id' => ['required', 'string', 'max:40'],
            'data.objects.*.type' => ['required', 'string', 'max:40'],
            'data.objects.*.x' => ['required', 'numeric', 'between:-200,200'],
            'data.objects.*.z' => ['required', 'numeric', 'between:-200,200'],
            'data.objects.*.rot' => ['required', 'numeric'],
            'data.objects.*.seats' => ['nullable', 'array', 'max:8'],
            'data.objects.*.seats.*.uuid' => ['sometimes', 'string', 'max:40'],
            'data.objects.*.seats.*.name' => ['sometimes', 'string', 'max:120'],
        ], [
            'data.room.polygon.min' => 'Oda en az 3 köşeden oluşmalı.',
            'data.objects.max' => 'Bir derslikte en fazla 800 nesne olabilir.',
        ], [
            'name' => 'Tasarım adı', 'classroom_id' => 'Derslik', 'data' => 'Tasarım verisi', 'data.room.ceiling' => 'Tavan yüksekliği',
            'data.room.wallThickness' => 'Duvar kalınlığı', 'thumbnail' => 'Küçük görsel',
        ]);

        if (strlen((string) json_encode($v['data'])) > self::MAX_DATA_BYTES) {
            throw new BusinessRuleException('Tasarım verisi çok büyük (en fazla 2 MB).', 'layout_too_large');
        }
        // Doğrulanan alanlar dışındaki (nesne rengi, kamera, ayarlar…) içerik olduğu gibi saklanır
        $v['data'] = $request->input('data');

        return $v;
    }
}

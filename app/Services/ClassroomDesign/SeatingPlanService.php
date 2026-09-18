<?php

namespace App\Services\ClassroomDesign;

use App\Exceptions\BusinessRuleException;
use App\Models\ClassroomLayout;
use App\Models\ClassSeatingPlan;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SINIF OTURMA PLANI — oda düzeni (classroom_layouts) sınıflar arasında ortaktır; oturma planı sınıf başınadır.
 *  - Sınıfın dersliği: sınıf dersliği (homeroom) + ders programında sınıfın dersi olan derslikler (en sık önce).
 *  - Dersliğin oda düzeni: o dersliğe bağlı etkin, en son güncellenen tasarım.
 *  - Plan yoksa geriye uyumluluk: oda düzeni JSON'undaki eski atamalardan bu sınıfın öğrencileri alınır.
 *  - Oda düzeninde artık olmayan masadaki öğrenci "yerleştirilmemiş" sayılır (plan satırı silinmez).
 */
class SeatingPlanService
{
    public const STATUSES = ['reserved', 'unavailable'];

    /** @return Collection<int, array<string, mixed>> */
    public function classrooms(int $groupId): Collection
    {
        $today = CarbonImmutable::today()->toDateString();
        $homeroom = DB::table('class_groups')->where('id', $groupId)->value('homeroom_classroom_id');
        $weekly = DB::table('lesson_schedules')->where('class_group_id', $groupId)->whereNull('deleted_at')->whereNotNull('classroom_id')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->groupBy('classroom_id')->selectRaw('classroom_id, COUNT(*) AS n')->pluck('n', 'classroom_id');
        $ids = collect($weekly->keys())->map(fn ($v) => (int) $v);
        if ($homeroom) {
            $ids->push((int) $homeroom);
        }
        $ids = $ids->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $rooms = DB::table('classrooms')->whereIn('id', $ids)->whereNull('deleted_at')->get(['id', 'name', 'floor', 'capacity']);
        $layouts = $this->activeLayouts($ids->all());

        return $rooms->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => $r->name,
            'floor' => $r->floor,
            'capacity' => (int) $r->capacity,
            'homeroom' => (int) $homeroom === (int) $r->id,
            'weekly_lessons' => (int) ($weekly[$r->id] ?? 0),
            'layout' => $layouts[$r->id] ?? null,
        ])->sortByDesc(fn ($r) => ($r['homeroom'] ? 1000 : 0) + $r['weekly_lessons'])->values();
    }

    /** @return array<int, array<string, mixed>> derslik id → etkin tasarım özeti */
    public function activeLayouts(array $classroomIds): array
    {
        if ($classroomIds === []) {
            return [];
        }
        $rows = ClassroomLayout::query()->whereIn('classroom_id', $classroomIds)->where('is_active', true)
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get(['id', 'classroom_id', 'name', 'version', 'updated_at', DB::raw('CASE WHEN thumbnail IS NULL THEN 0 ELSE 1 END AS has_thumbnail')]);
        $out = [];
        foreach ($rows as $l) {
            if (isset($out[$l->classroom_id])) {
                continue;
            }
            $out[$l->classroom_id] = [
                'id' => $l->id, 'name' => $l->name, 'version' => $l->version,
                'thumbnail_url' => (int) $l->getAttribute('has_thumbnail') === 1 ? '/api/v1/classroom-layouts/'.$l->id.'/thumbnail?v='.$l->version.'-'.optional($l->updated_at)->timestamp : null,
            ];
        }

        return $out;
    }

    /** @return Collection<int, array<string, mixed>> sınıfın güncel öğrencileri (UUID ile) */
    public function students(int $groupId): Collection
    {
        return DB::table('class_group_student as cgs')->join('students as s', 's.id', '=', 'cgs.student_id')
            ->where('cgs.class_group_id', $groupId)->whereNull('cgs.left_on')->whereNull('s.deleted_at')
            ->whereNotIn('s.status', ['withdrawn', 'graduated'])->whereNotNull('s.uuid')
            ->orderBy('s.first_name')->orderBy('s.last_name')
            ->get(['s.uuid', 's.first_name', 's.last_name', 's.student_no', 's.gender'])
            ->unique('uuid')
            ->map(fn ($s) => [
                'uuid' => $s->uuid, 'name' => trim($s->first_name.' '.$s->last_name), 'first_name' => $s->first_name, 'last_name' => $s->last_name,
                'student_no' => $s->student_no, 'gender' => in_array($s->gender, ['female', 'male'], true) ? $s->gender : null,
            ])->values();
    }

    /** Ekran verisi: derslik seçenekleri, oda düzeni (JSON), plan, öğrenciler */
    public function payload(int $groupId, ?int $classroomId): array
    {
        $group = DB::table('class_groups')->where('id', $groupId)->first(['id', 'name']);
        $rooms = $this->classrooms($groupId);
        $room = $classroomId ? $rooms->firstWhere('id', $classroomId) : ($rooms->first(fn ($r) => $r['layout'] !== null) ?? $rooms->first());
        $students = $this->students($groupId);

        $layout = null;
        $plan = null;
        if ($room && $room['layout']) {
            $l = ClassroomLayout::query()->whereKey($room['layout']['id'])->first(['id', 'name', 'version', 'data', 'is_demo', 'classroom_id', 'updated_at', DB::raw('CASE WHEN thumbnail IS NULL THEN 0 ELSE 1 END AS has_thumbnail')]);
            if ($l) {
                $data = is_array($l->data) ? $l->data : [];
                $layout = ['id' => $l->id, 'name' => $l->name, 'version' => $l->version, 'is_demo' => $l->is_demo, 'has_thumbnail' => (int) $l->getAttribute('has_thumbnail') === 1, 'data' => $this->stripLegacySeats($data)];
                $row = ClassSeatingPlan::query()->where('class_group_id', $groupId)->where('classroom_layout_id', $l->id)
                    ->first(['id', 'seats', 'statuses', 'layout_version', 'updated_at', 'updated_by']);
                if ($row) {
                    $plan = [
                        'id' => $row->id, 'seats' => (object) (is_array($row->seats) ? $row->seats : []), 'statuses' => (object) (is_array($row->statuses) ? $row->statuses : []),
                        'layout_version' => $row->layout_version, 'updated_at' => optional($row->updated_at)->toIso8601String(),
                        'updated_by' => $row->updated_by ? DB::table('users')->where('id', $row->updated_by)->value('name') : null, 'legacy' => false,
                    ];
                } else {
                    $plan = $this->legacyPlan($data, $students->pluck('uuid')->all());
                }
            }
        }

        // Plan, öğrenci listesinde olmayan (sınıftan ayrılmış) öğrenci içeriyorsa adı görünsün
        $known = $students->pluck('uuid')->all();
        $extra = [];
        if ($plan) {
            $uuids = collect((array) $plan['seats'])->flatten()->filter()->unique()->diff($known)->values();
            if ($uuids->isNotEmpty()) {
                $extra = DB::table('students')->where('branch_id', app(BranchContext::class)->id())->whereIn('uuid', $uuids)
                    ->get(['uuid', 'first_name', 'last_name'])->map(fn ($s) => ['uuid' => $s->uuid, 'name' => trim($s->first_name.' '.$s->last_name)])->values()->all();
            }
        }

        return [
            'group' => ['id' => (int) $group->id, 'name' => $group->name],
            'classrooms' => $rooms,
            'classroom_id' => $room['id'] ?? null,
            'layout' => $layout,
            'plan' => $plan,
            'students' => $students,
            'former_students' => $extra,
            'has_gender' => $students->filter(fn ($s) => $s['gender'] !== null)->count() >= 2,
        ];
    }

    /** Oda düzeni JSON'undaki eski (sınıfa bağlı olmayan) atamalar oturma ekranına taşınmaz */
    private function stripLegacySeats(array $data): array
    {
        if (isset($data['objects']) && is_array($data['objects'])) {
            foreach ($data['objects'] as $i => $o) {
                if (is_array($o) && isset($o['seats']) && is_array($o['seats'])) {
                    $data['objects'][$i]['seats'] = array_map(fn () => null, $o['seats']);
                    unset($data['objects'][$i]['status']);
                }
            }
        }

        return $data;
    }

    /** Geriye uyumluluk: plan yokken oda düzenindeki eski atamalardan bu sınıfın öğrencileri */
    private function legacyPlan(array $data, array $groupUuids): ?array
    {
        $seats = [];
        $statuses = [];
        $set = array_flip($groupUuids);
        foreach (($data['objects'] ?? []) as $o) {
            if (! is_array($o) || ! isset($o['id'], $o['seats']) || ! is_array($o['seats'])) {
                continue;
            }
            $row = array_map(fn ($s) => is_array($s) && isset($s['uuid'], $set[$s['uuid']]) ? $s['uuid'] : null, $o['seats']);
            if (array_filter($row)) {
                $seats[$o['id']] = $row;
            }
            if (isset($o['status']) && in_array($o['status'], self::STATUSES, true)) {
                $statuses[$o['id']] = $o['status'];
            }
        }
        if ($seats === [] && $statuses === []) {
            return null;
        }

        return ['id' => null, 'seats' => (object) $seats, 'statuses' => (object) $statuses, 'layout_version' => null, 'updated_at' => null, 'updated_by' => null, 'legacy' => true];
    }

    /**
     * Planı kaydeder (sınıf + oda düzeni başına tek satır). Öğrenciler yalnız bu sınıfın güncel öğrencileri olabilir;
     * masa kimlikleri oda düzeninde olmalı, bir öğrenci tek oturakta.
     *
     * @param  array<string, list<string|null>>  $seats
     * @param  array<string, string>  $statuses
     */
    public function save(int $groupId, int $layoutId, array $seats, array $statuses, ?int $userId): ClassSeatingPlan
    {
        $layout = ClassroomLayout::query()->whereKey($layoutId)->first(['id', 'version', 'data', 'classroom_id']);
        if (! $layout) {
            throw new BusinessRuleException('Oda düzeni bulunamadı.', 'layout_missing', [], 404);
        }
        $desks = [];
        foreach ((is_array($layout->data) ? ($layout->data['objects'] ?? []) : []) as $o) {
            if (is_array($o) && isset($o['id']) && in_array($o['type'] ?? '', ['desk-single', 'desk-double'], true)) {
                $desks[$o['id']] = ($o['type'] === 'desk-double') ? 2 : 1;
            }
        }
        $allowed = array_flip($this->students($groupId)->pluck('uuid')->all());
        $seen = [];
        $cleanSeats = [];
        foreach ($seats as $deskId => $row) {
            if (! isset($desks[$deskId]) || ! is_array($row)) {
                continue;   // oda düzeninde olmayan masa: yok say (öğrenci yerleştirilmemiş kalır)
            }
            $out = [];
            for ($i = 0; $i < $desks[$deskId]; $i++) {
                $u = $row[$i] ?? null;
                if ($u === null || $u === '') {
                    $out[] = null;

                    continue;
                }
                if (! isset($allowed[$u])) {
                    throw new BusinessRuleException('Oturma planında bu sınıfta olmayan bir öğrenci var. Sayfayı yenileyip tekrar deneyin.', 'seating_foreign_student', ['uuid' => $u]);
                }
                if (isset($seen[$u])) {
                    throw new BusinessRuleException('Bir öğrenci aynı anda iki masada olamaz.', 'seating_duplicate_student', ['uuid' => $u]);
                }
                $seen[$u] = true;
                $out[] = $u;
            }
            if (array_filter($out)) {
                $cleanSeats[$deskId] = $out;
            }
        }
        $cleanStatuses = [];
        foreach ($statuses as $deskId => $st) {
            if (isset($desks[$deskId]) && in_array($st, self::STATUSES, true)) {
                $cleanStatuses[$deskId] = $st;
            }
        }
        foreach ($cleanStatuses as $deskId => $st) {
            if ($st === 'unavailable' && isset($cleanSeats[$deskId])) {
                throw new BusinessRuleException('Kullanılamaz masaya öğrenci oturtulamaz.', 'seating_unavailable_desk', ['desk' => $deskId]);
            }
        }

        $plan = ClassSeatingPlan::query()->firstOrNew(['class_group_id' => $groupId, 'classroom_layout_id' => $layoutId]);
        $plan->fill(['layout_version' => $layout->version, 'seats' => $cleanSeats, 'statuses' => $cleanStatuses, 'updated_by' => $userId])->save();

        return $plan;
    }
}

<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\ClassGroup;
use App\Models\ClassGroupSubjectHour;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Placement\ClassStructure;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sınıfa özel müfredat: program_subject haftalık saatleri + sınıf başına geçersiz kılma
 * (class_group_subject_hours). Alan (SAY/EA/…) için varsayılan müfredat önerisi ayarlardan gelir.
 */
class ClassCurriculum
{
    /** Alan → ders kodu → haftalık saat (kurum ayarıyla değiştirilebilir). */
    public const TRACK_DEFAULTS = [
        'SAY' => ['MAT' => 6, 'GEO' => 2, 'FIZ' => 4, 'KIM' => 3, 'BIY' => 3, 'TUR' => 2],
        'EA' => ['MAT' => 6, 'GEO' => 2, 'EDB' => 4, 'TAR' => 3, 'COG' => 3, 'TUR' => 2],
        'SOZ' => ['EDB' => 5, 'TAR' => 4, 'COG' => 4, 'FEL' => 2, 'DIN' => 1, 'TUR' => 3, 'MAT' => 2],
        'DIL' => ['ING' => 10, 'TUR' => 4, 'MAT' => 4, 'EDB' => 2],
        'TYT' => ['MAT' => 5, 'GEO' => 1, 'TUR' => 4, 'FIZ' => 2, 'KIM' => 2, 'BIY' => 2, 'TAR' => 1, 'COG' => 1, 'FEL' => 1],
        'LGS' => ['MAT' => 5, 'TUR' => 5, 'FEN' => 5, 'SOS' => 2, 'ING' => 2, 'DIN' => 1],
    ];

    public const MAX_WEEKLY = 20;

    /** @return array<string, array<string,int>> */
    public function trackCurricula(): array
    {
        $stored = Settings::get('classes.track_curricula');
        $out = self::TRACK_DEFAULTS;
        if (is_array($stored)) {
            foreach ($stored as $track => $map) {
                if (isset(ClassStructure::TRACKS[$track]) && is_array($map)) {
                    $out[$track] = array_map('intval', array_filter($map, fn ($h) => is_numeric($h) && (int) $h > 0));
                }
            }
        }

        return $out;
    }

    /** @param array<string, array<string,int>> $input alan → ders kodu → saat */
    public function saveTrackCurricula(array $input): array
    {
        $codes = Subject::query()->pluck('code')->filter()->all();
        $clean = [];
        foreach ($input as $track => $map) {
            if (! isset(ClassStructure::TRACKS[$track]) || ! is_array($map)) {
                continue;
            }
            foreach ($map as $code => $hours) {
                $h = (int) $hours;
                if ($h > 0 && in_array($code, $codes, true)) {
                    $clean[$track][$code] = min(self::MAX_WEEKLY, $h);
                }
            }
            $clean[$track] ??= [];
        }
        Settings::put('classes', ['track_curricula' => $clean]);
        Audit::log('settings.track_curricula_updated', 'Alanlara göre varsayılan müfredatı güncelledi ('.implode(', ', array_map(fn ($t) => ClassStructure::TRACKS[$t], array_keys($clean))).').');

        return $this->trackCurricula();
    }

    /** @return array<int,int> alan önerisi: ders id → saat */
    public function suggestion(?string $track): array
    {
        if (! $track) {
            return [];
        }
        $map = $this->trackCurricula()[$track] ?? [];
        $ids = Subject::query()->whereIn('code', array_keys($map))->pluck('id', 'code');
        $out = [];
        foreach ($map as $code => $h) {
            if (isset($ids[$code])) {
                $out[(int) $ids[$code]] = (int) $h;
            }
        }

        return $out;
    }

    /**
     * Sınıfların geçerli ders saatleri (çözücü girdisi).
     *
     * @param  Collection<int, ClassGroup>|iterable  $groups  program_id alanı dolu
     * @return array<int, list<array{subject:int, hours:int, teacher:?int, max_per_day:?int, block_size:?int, overridden:bool}>>
     */
    public function effective(iterable $groups): array
    {
        $groups = collect($groups);
        if ($groups->isEmpty()) {
            return [];
        }
        $program = DB::table('program_subject')->whereIn('program_id', $groups->pluck('program_id')->unique())->get(['program_id', 'subject_id', 'weekly_hours'])->groupBy('program_id');
        $overrides = ClassGroupSubjectHour::query()->whereIn('class_group_id', $groups->pluck('id'))->get()->groupBy('class_group_id');

        $out = [];
        foreach ($groups as $g) {
            $rows = [];
            foreach ($program[$g->program_id] ?? [] as $ps) {
                $rows[(int) $ps->subject_id] = ['subject' => (int) $ps->subject_id, 'hours' => (int) $ps->weekly_hours, 'teacher' => null, 'max_per_day' => null, 'block_size' => null, 'overridden' => false];
            }
            foreach ($overrides[$g->id] ?? [] as $o) {
                $rows[(int) $o->subject_id] = ['subject' => (int) $o->subject_id, 'hours' => (int) $o->weekly_hours, 'teacher' => $o->teacher_id ? (int) $o->teacher_id : null,
                    'max_per_day' => $o->max_per_day ?: null, 'block_size' => $o->block_size ?: null, 'overridden' => true];
            }
            $out[$g->id] = array_values(array_filter($rows, fn ($r) => $r['hours'] > 0));
        }

        return $out;
    }

    /** @return array<int,int> sınıf → toplam haftalık saat */
    public function totals(iterable $groups): array
    {
        return array_map(fn ($rows) => array_sum(array_column($rows, 'hours')), $this->effective($groups));
    }

    /** Düzenleme ekranı verisi. */
    public function forGroup(ClassGroup $group): array
    {
        $group->loadMissing(['program:id,name', 'timeTemplates' => fn ($q) => $q->where('is_active', true)]);
        $program = DB::table('program_subject')->where('program_id', $group->program_id)->pluck('weekly_hours', 'subject_id');
        $overrides = ClassGroupSubjectHour::query()->where('class_group_id', $group->id)->get()->keyBy('subject_id');
        $subjects = Subject::query()->orderBy('name')->get(['id', 'name', 'code', 'is_hard']);
        $teachers = DB::table('teacher_subject')->join('teachers', 'teachers.id', '=', 'teacher_subject.teacher_id')
            ->whereNull('teachers.deleted_at')->where('teachers.is_active', true)
            ->get(['teacher_subject.subject_id', 'teachers.id', 'teachers.first_name', 'teachers.last_name'])->groupBy('subject_id');
        $suggestion = $this->suggestion($group->track);

        $rows = [];
        foreach ($subjects as $s) {
            $o = $overrides[$s->id] ?? null;
            $base = isset($program[$s->id]) ? (int) $program[$s->id] : null;
            if ($base === null && ! $o && ! isset($suggestion[$s->id])) {
                $rows[] = ['subject_id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'is_hard' => (bool) $s->is_hard, 'program_hours' => null, 'hours' => 0,
                    'overridden' => false, 'teacher_id' => null, 'max_per_day' => null, 'block_size' => null, 'suggested' => null, 'in_program' => false,
                    'teachers' => $this->teacherOptions($teachers[$s->id] ?? collect())];

                continue;
            }
            $rows[] = [
                'subject_id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'is_hard' => (bool) $s->is_hard,
                'program_hours' => $base, 'hours' => $o ? (int) $o->weekly_hours : (int) ($base ?? 0), 'overridden' => (bool) $o,
                'teacher_id' => $o?->teacher_id, 'max_per_day' => $o?->max_per_day, 'block_size' => $o?->block_size,
                'suggested' => $suggestion[$s->id] ?? null, 'in_program' => $base !== null,
                'teachers' => $this->teacherOptions($teachers[$s->id] ?? collect()),
            ];
        }
        $active = fn ($r) => $r['hours'] > 0 || $r['in_program'] || $r['suggested'] !== null ? 0 : 1;
        usort($rows, fn ($a, $b) => [$active($a), $a['name']] <=> [$active($b), $b['name']]);

        $slots = $group->timeTemplates->sum(fn ($t) => count($t->periods()));
        [$level, $section] = ClassStructure::resolve($group->name, $group->grade_level, $group->section);

        return [
            'class' => ['id' => $group->id, 'name' => $group->name, 'grade_level' => $level, 'section' => $section, 'track' => $group->track,
                'track_label' => $group->track ? (ClassStructure::TRACKS[$group->track] ?? $group->track) : null,
                'program' => $group->program?->name, 'program_id' => $group->program_id,
                'templates' => $group->timeTemplates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()],
            'slots' => $slots,
            'rows' => $rows,
            'total' => array_sum(array_map(fn ($r) => $r['hours'], $rows)),
            'program_total' => (int) $program->sum(),
            'suggestion_total' => array_sum($suggestion),
            'overrides' => $overrides->count(),
        ];
    }

    private function teacherOptions(Collection $rows): array
    {
        return $rows->map(fn ($t) => ['id' => (int) $t->id, 'name' => trim($t->first_name.' '.$t->last_name)])->sortBy('name')->values()->all();
    }

    /**
     * Sınıf müfredatını kaydeder. Programla aynı ve ek ayarı olmayan satırlar silinir (programı izler).
     *
     * @param  list<array{subject_id:int, hours:int, teacher_id?:?int, max_per_day?:?int, block_size?:?int}>  $rows
     */
    public function save(ClassGroup $group, array $rows): array
    {
        $program = DB::table('program_subject')->where('program_id', $group->program_id)->pluck('weekly_hours', 'subject_id');
        $subjectIds = Subject::query()->pluck('id')->map(fn ($v) => (int) $v)->all();
        $teacherIds = Teacher::query()->pluck('id')->map(fn ($v) => (int) $v)->all();

        return DB::transaction(function () use ($group, $rows, $program, $subjectIds, $teacherIds) {
            $before = ClassGroupSubjectHour::query()->where('class_group_id', $group->id)->get()->keyBy('subject_id');
            $seen = [];
            $changed = 0;
            foreach ($rows as $r) {
                $sid = (int) ($r['subject_id'] ?? 0);
                if (! in_array($sid, $subjectIds, true) || isset($seen[$sid])) {
                    continue;
                }
                $seen[$sid] = true;
                $hours = max(0, min(self::MAX_WEEKLY, (int) ($r['hours'] ?? 0)));
                $teacher = ! empty($r['teacher_id']) && in_array((int) $r['teacher_id'], $teacherIds, true) ? (int) $r['teacher_id'] : null;
                $maxDay = ! empty($r['max_per_day']) ? max(1, min(6, (int) $r['max_per_day'])) : null;
                $block = ! empty($r['block_size']) && (int) $r['block_size'] === 2 ? 2 : null;
                if ($block && $maxDay !== null && $maxDay < 2) {
                    throw new BusinessRuleException('İkişer saatlik blok ders için günlük üst sınır en az 2 olmalı.', 'curriculum_block_max');
                }
                $base = isset($program[$sid]) ? (int) $program[$sid] : 0;
                $plain = $hours === $base && $teacher === null && $maxDay === null && $block === null;
                $existing = $before[$sid] ?? null;
                if ($plain) {
                    if ($existing) {
                        $existing->delete();
                        $changed++;
                    }

                    continue;
                }
                $values = ['weekly_hours' => $hours, 'teacher_id' => $teacher, 'max_per_day' => $maxDay, 'block_size' => $block];
                if (! $existing || $existing->only(array_keys($values)) != $values) {
                    ClassGroupSubjectHour::query()->updateOrCreate(['class_group_id' => $group->id, 'subject_id' => $sid], $values);
                    $changed++;
                }
            }
            // Gönderilmeyen satırlar: programı izlemeye döner
            foreach ($before as $sid => $o) {
                if (! isset($seen[(int) $sid])) {
                    $o->delete();
                    $changed++;
                }
            }

            if ($changed > 0) {
                $total = array_sum(array_column($this->effective([$group])[$group->id] ?? [], 'hours'));
                Audit::log('class_group.curriculum_updated', "{$group->name} sınıfının ders saatlerini güncelledi ({$changed} değişiklik, haftalık toplam {$total} saat).", $group);
            }

            return ['changed' => $changed];
        });
    }

    /**
     * Alan önerisini sınıflara uygular (programdaki diğer dersler 0 saate çekilir).
     *
     * @param  list<int>  $groupIds
     */
    public function applyTrack(array $groupIds): array
    {
        $done = [];
        $skipped = [];
        foreach (ClassGroup::query()->whereIn('id', $groupIds)->get() as $g) {
            $suggestion = $this->suggestion($g->track);
            if (! $suggestion) {
                $skipped[] = $g->name;

                continue;
            }
            $program = DB::table('program_subject')->where('program_id', $g->program_id)->pluck('weekly_hours', 'subject_id');
            $rows = [];
            foreach (array_unique(array_merge(array_keys($suggestion), array_map('intval', $program->keys()->all()))) as $sid) {
                $rows[] = ['subject_id' => $sid, 'hours' => $suggestion[$sid] ?? 0];
            }
            // Mevcut sabit öğretmen / sınır ayarları korunur
            $existing = ClassGroupSubjectHour::query()->where('class_group_id', $g->id)->get()->keyBy('subject_id');
            $rows = array_map(fn ($r) => $r + ['teacher_id' => $existing[$r['subject_id']]->teacher_id ?? null, 'max_per_day' => $existing[$r['subject_id']]->max_per_day ?? null, 'block_size' => $existing[$r['subject_id']]->block_size ?? null], $rows);
            $this->save($g, $rows);
            $done[] = $g->name;
        }

        return ['applied' => $done, 'skipped' => $skipped];
    }
}

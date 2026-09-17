<?php

namespace App\Services\Academic\Timetable;

use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\LessonSchedule;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Academic\ClassCurriculum;
use App\Services\Academic\TimeSlots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Veritabanından çözücü problemi kurar (şube bağlamında çağrılmalı).
 *
 * - Seçili sınıflar: sınıfın geçerli müfredatı (program + sınıfa özel saatler) − kilitli dersler = yerleştirilecek saatler
 *   (sınıfa özel sabit öğretmen, günlük üst sınır, blok ders dahil)
 * - Seçilmeyen sınıfların (uygulama tarihinde geçerli) programı + kilitli dersler = SABİT kısıt
 * - Öğretmen: bota dahil olanlar (in_timetable); branş (teacher_subject), program-ders öğretmen kısıtı (varsa),
 *   uygunluk, haftalık üst sınır (sert) ve hedef saat (yumuşak),
 *   uzun izinler (önümüzdeki 4 haftanın ≥3'ünde aynı gün izinli → o gün kapalı)
 * - Derslik: aktif, etüt odası değil, kapasite ≥ sınıf mevcudu
 */
class TimetableProblemBuilder
{
    public function __construct(private readonly ClassCurriculum $curriculum) {}

    /** @return array{problem: Problem, names: array, locked: list<array>, external: list<array>, warnings: list<string>} */
    public function build(int $termId, array $classIds, array $settings, CarbonImmutable $from): array
    {
        $warnings = [];
        $groups = ClassGroup::query()->with(['timeTemplates' => fn ($q) => $q->where('is_active', true)])
            ->where('academic_term_id', $termId)->whereIn('id', $classIds)->where('is_active', true)->get()->keyBy('id');

        $sizes = DB::table('class_group_student')->whereIn('class_group_id', $groups->keys())->whereNull('left_on')
            ->groupBy('class_group_id')->pluck(DB::raw('COUNT(*)'), 'class_group_id');

        $classes = [];
        foreach ($groups as $g) {
            $slots = [];
            foreach ($g->timeTemplates as $template) {
                foreach ($template->periods() as $period) {
                    $slots[] = [$period['weekday'], TimeSlots::toMinutes($period['start']), TimeSlots::toMinutes($period['end'])];
                }
            }
            $size = max((int) ($sizes[$g->id] ?? 0), 1);
            $classes[$g->id] = ['name' => $g->name, 'size' => $size, 'homeroom' => $g->homeroom_classroom_id, 'slots' => $slots];
        }

        $subjects = Subject::query()->get(['id', 'name', 'is_hard'])->mapWithKeys(fn ($s) => [$s->id => ['name' => $s->name, 'hard' => (bool) $s->is_hard]])->all();

        $teacherModels = Teacher::query()->where('is_active', true)->where('in_timetable', true)->get(['id', 'first_name', 'last_name', 'max_weekly_hours', 'target_weekly_hours']);
        $excluded = Teacher::query()->where('is_active', true)->where('in_timetable', false)->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $teacherSubjects = DB::table('teacher_subject')->whereIn('teacher_id', $teacherModels->pluck('id'))->get()->groupBy('teacher_id');
        $availability = DB::table('teacher_availabilities')->whereIn('teacher_id', $teacherModels->pluck('id'))->get()->groupBy('teacher_id');
        $blocked = $this->leaveBlockedDays($teacherModels->pluck('id')->all(), $from);

        $teachers = [];
        foreach ($teacherModels as $t) {
            $avail = null;
            if (isset($availability[$t->id])) {
                $avail = [];
                foreach ($availability[$t->id] as $a) {
                    $avail[(int) $a->weekday][] = [TimeSlots::toMinutes($a->starts_at), TimeSlots::toMinutes($a->ends_at)];
                }
            }
            $teachers[$t->id] = [
                'name' => $t->full_name,
                'subjects' => ($teacherSubjects[$t->id] ?? collect())->pluck('subject_id')->map(fn ($v) => (int) $v)->all(),
                'max_units' => (int) ($t->max_weekly_hours ?: 40),
                'target' => $t->target_weekly_hours ? (int) $t->target_weekly_hours : null,
                'availability' => $avail,
                'blocked_days' => array_keys($blocked[$t->id] ?? []),
            ];
            foreach ($blocked[$t->id] ?? [] as $wd => $range) {
                $warnings[] = "{$t->full_name} önümüzdeki haftalarda {$range} — ".TimeSlots::WEEKDAYS[$wd].' günleri bot tarafından kapalı sayıldı.';
            }
        }

        $rooms = Classroom::query()->where('is_active', true)->where('kind', '!=', 'study')->get(['id', 'name', 'capacity'])
            ->mapWithKeys(fn ($r) => [$r->id => ['name' => $r->name, 'capacity' => (int) $r->capacity]])->all();

        // Uygulama tarihinde ve sonrasında geçerli şablonlar
        $active = LessonSchedule::query()->with(['subject:id,name', 'teacher:id,first_name,last_name', 'classroom:id,name', 'classGroup:id,name'])
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from->toDateString()))
            ->get();

        $fixed = [];
        $locked = [];
        $lockedCount = [];
        $external = [];
        foreach ($active as $s) {
            $isSelected = isset($classes[$s->class_group_id]);
            if ($isSelected && ! $s->is_locked) {
                continue; // yeniden planlanacak
            }
            if (! $isSelected && isset($teachers[$s->teacher_id])) {
                // Öğretmen görünümünde "diğer sınıflardaki dersler" olarak gösterilir
                $external[] = ['teacher' => $s->teacher_id, 'weekday' => $s->weekday, 'start' => substr($s->starts_at, 0, 5), 'end' => substr($s->ends_at, 0, 5),
                    'class_name' => $s->classGroup?->name, 'subject_name' => $s->subject?->name, 'room_name' => $s->classroom?->name];
            }
            $fixed[] = [
                'class' => $isSelected ? $s->class_group_id : null, 'subject' => $s->subject_id, 'teacher' => $s->teacher_id, 'room' => $s->classroom_id,
                'weekday' => $s->weekday, 'start' => TimeSlots::toMinutes($s->starts_at), 'end' => TimeSlots::toMinutes($s->ends_at),
            ];
            if ($isSelected) {
                $key = $s->class_group_id.'|'.$s->subject_id;
                $lockedCount[$key] = ($lockedCount[$key] ?? 0) + 1;
                $locked[] = ['id' => $s->id, 'class' => $s->class_group_id, 'subject' => $s->subject_id, 'teacher' => $s->teacher_id, 'room' => $s->classroom_id,
                    'weekday' => $s->weekday, 'start' => substr($s->starts_at, 0, 5), 'end' => substr($s->ends_at, 0, 5)];
            }
        }

        $effective = $this->curriculum->effective($groups->values());
        $restrict = DB::table('program_subject_teacher')->whereIn('program_id', $groups->pluck('program_id')->unique())->get()
            ->groupBy(fn ($r) => $r->program_id.'|'.$r->subject_id)->map(fn ($rows) => $rows->pluck('teacher_id')->map(fn ($v) => (int) $v)->all());

        $demands = [];
        $allTeachers = null;
        foreach ($groups as $g) {
            if (empty($effective[$g->id])) {
                $warnings[] = "{$g->name}: programında ya da sınıf müfredatında haftalık saati tanımlı ders yok.";
            }
            foreach ($effective[$g->id] ?? [] as $row) {
                $hours = $row['hours'] - ($lockedCount[$g->id.'|'.$row['subject']] ?? 0);
                if ($hours <= 0) {
                    continue;
                }
                $demand = ['class' => $g->id, 'subject' => $row['subject'], 'hours' => $hours, 'teachers' => $restrict[$g->program_id.'|'.$row['subject']] ?? null,
                    'max_per_day' => $row['max_per_day'], 'block' => $row['block_size']];
                if ($row['teacher'] !== null) {
                    $tid = $row['teacher'];
                    $demand['teachers'] = [$tid];
                    $demand['strict'] = true;
                    if (! isset($teachers[$tid])) {
                        $allTeachers ??= Teacher::withTrashed()->get(['id', 'first_name', 'last_name', 'is_active', 'deleted_at'])->keyBy('id');
                        $name = $allTeachers[$tid]->full_name ?? "#{$tid}";
                        $why = isset($excluded[$tid]) ? 'bota dahil edilmemiş' : 'aktif değil';
                        $warnings[] = "{$g->name} · ".($subjects[$row['subject']]['name'] ?? 'Ders').": sabit öğretmen {$name} {$why}; bu ders yerleşemeyecek.";
                    }
                }
                $demands[] = $demand;
            }
        }
        if ($excluded->isNotEmpty()) {
            $warnings[] = $excluded->count().' öğretmen bota dahil edilmedi: '.$excluded->map(fn ($t) => $t->full_name)->implode(', ').'.';
        }

        $problem = new Problem($classes, $subjects, $teachers, $rooms, $demands, $fixed, $settings['weights'] ?? [], (int) ($settings['max_per_day'] ?? 2));

        return [
            'problem' => $problem,
            'names' => [
                'classes' => array_map(fn ($c) => ['name' => $c['name'], 'size' => $c['size'], 'slots' => count($c['slots'])], $classes),
                'subjects' => array_map(fn ($s) => $s['name'], $subjects),
                'teachers' => array_map(fn ($t) => $t['name'], $teachers),
                'rooms' => array_map(fn ($r) => $r['name'], $rooms),
            ],
            'locked' => $locked,
            'external' => $external,
            'warnings' => $warnings,
        ];
    }

    /**
     * Uzun izinler: önümüzdeki 4 haftada bir hafta günü en az 3 kez izne denk geliyorsa o gün kapalı.
     * Tek günlük izinler programı bozmaz — yedek öğretmen botu çözer.
     *
     * @return array<int, array<int,string>> öğretmen → gün → açıklama
     */
    private function leaveBlockedDays(array $teacherIds, CarbonImmutable $from): array
    {
        $to = $from->addDays(27);
        $leaves = DB::table('teacher_leaves')->whereIn('teacher_id', $teacherIds)->where('status', 'approved')
            ->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get();

        $out = [];
        foreach ($leaves->groupBy('teacher_id') as $teacherId => $rows) {
            $hits = [];
            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                foreach ($rows as $leave) {
                    if ($d->toDateString() >= $leave->starts_on && $d->toDateString() <= $leave->ends_on) {
                        $hits[$d->dayOfWeekIso] = ($hits[$d->dayOfWeekIso] ?? 0) + 1;
                        break;
                    }
                }
            }
            foreach ($hits as $wd => $n) {
                if ($n >= 3) {
                    $first = $rows->min('starts_on');
                    $last = $rows->max('ends_on');
                    $out[(int) $teacherId][$wd] = 'izinli ('.CarbonImmutable::parse($first)->format('d.m').'–'.CarbonImmutable::parse($last)->format('d.m').')';
                }
            }
        }

        return $out;
    }
}

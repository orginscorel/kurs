<?php

namespace App\Services\Academic;

use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\LessonSchedule;
use App\Models\Teacher;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Haftalık ders programı PDF'i (sınıf / öğretmen / derslik bazlı), kurum markalı, A4 yatay. */
class SchedulePdf
{
    public function weekly(string $view, int $id, CarbonImmutable $date): Response
    {
        $start = TimeSlots::weekStart($date);
        $end = $start->addDays(6);
        [$title, $subtitle, $slug] = match ($view) {
            'class_group' => (function () use ($id) {
                $g = ClassGroup::query()->with(['program:id,name', 'homeroom:id,name', 'advisor:id,first_name,last_name'])->findOrFail($id);

                return ["{$g->name} Sınıfı", collect([$g->program?->name, $g->homeroom ? "Derslik: {$g->homeroom->name}" : null, $g->advisor ? "Danışman: {$g->advisor->full_name}" : null])->filter()->implode(' · '), $g->name];
            })(),
            'teacher' => (function () use ($id) {
                $t = Teacher::query()->findOrFail($id);

                return [$t->full_name, $t->title ?? 'Öğretmen', $t->full_name];
            })(),
            default => (function () use ($id) {
                $c = Classroom::query()->findOrFail($id);

                return [$c->name, "Kapasite {$c->capacity}".($c->floor ? " · Kat {$c->floor}" : ''), $c->name];
            })(),
        };

        $rows = LessonSchedule::query()
            ->with(['subject:id,name,short_name', 'teacher:id,first_name,last_name', 'classroom:id,name', 'classGroup:id,name'])
            ->where('valid_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $start->toDateString()))
            ->when($view === 'class_group', fn ($q) => $q->where('class_group_id', $id))
            ->when($view === 'teacher', fn ($q) => $q->where('teacher_id', $id))
            ->when($view === 'classroom', fn ($q) => $q->where('classroom_id', $id))
            ->orderBy('starts_at')->get()
            ->filter(function ($s) use ($start) {
                $day = $start->addDays($s->weekday - 1);

                return $day->gte($s->valid_from) && (! $s->valid_until || $day->lte($s->valid_until));
            });

        $periods = $rows->map(fn ($s) => substr($s->starts_at, 0, 5).'–'.substr($s->ends_at, 0, 5))->unique()->sort()->values();
        $weekdays = collect(range(1, 6))->merge($rows->where('weekday', 7)->isNotEmpty() ? [7] : [])->values();
        $grid = [];
        foreach ($rows as $s) {
            $grid[substr($s->starts_at, 0, 5).'–'.substr($s->ends_at, 0, 5)][$s->weekday][] = [
                'subject' => $s->subject?->name,
                'line' => match ($view) {
                    'class_group' => $s->teacher?->full_name,
                    default => $s->classGroup?->name,
                },
                'room' => $view === 'classroom' ? $s->teacher?->full_name : $s->classroom?->name,
            ];
        }

        $totals = $rows->groupBy(fn ($s) => $s->subject?->name)->map->count()->sortDesc();

        $pdf = Pdf::loadView('pdf.academic.weekly-schedule', [
            'institution' => $this->institution(), 'title' => $title, 'subtitle' => $subtitle, 'viewLabel' => ['class_group' => 'Sınıf Programı', 'teacher' => 'Öğretmen Programı', 'classroom' => 'Derslik Programı'][$view],
            'weekStart' => $start, 'weekEnd' => $end, 'periods' => $periods, 'weekdays' => $weekdays, 'grid' => $grid, 'totals' => $totals,
            'lessonCount' => $rows->count(), 'dayNames' => TimeSlots::WEEKDAYS, 'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('ders-programi-'.\Illuminate\Support\Str::slug($slug).'-'.$start->format('Y-m-d').'.pdf');
    }

    private function institution(): array
    {
        $inst = Settings::group('institution');
        $inst['logo_data'] = null;
        if (! empty($inst['logo_path']) && Storage::disk('public')->exists($inst['logo_path'])) {
            $mime = Storage::disk('public')->mimeType($inst['logo_path']) ?: 'image/png';
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $inst['logo_data'] = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($inst['logo_path']));
            }
        }

        return $inst;
    }
}

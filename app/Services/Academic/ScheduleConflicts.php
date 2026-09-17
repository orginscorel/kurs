<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\StudySession;
use App\Models\TeacherLeave;
use Carbon\CarbonImmutable;

/**
 * Öğretmen, derslik ve sınıf çakışma denetimi.
 * İki aralık çakışır ⇔ başlangıç1 < bitiş2 VE başlangıç2 < bitiş1 (uç uca dersler çakışmaz).
 */
class ScheduleConflicts
{
    /**
     * Haftalık şablon için çakışmalar.
     *
     * @param array{teacher_id:int, classroom_id:int, class_group_id:int, weekday:int, starts_at:string,
     *              ends_at:string, valid_from:string, valid_until?:?string} $slot
     * @return list<array{type:string, message:string, schedule_id:int}>
     */
    public function forSchedule(array $slot, ?int $ignoreId = null): array
    {
        if ($slot['starts_at'] >= $slot['ends_at']) {
            throw new BusinessRuleException('Bitiş saati başlangıçtan sonra olmalı.', 'invalid_time_range');
        }

        $candidates = LessonSchedule::query()
            ->with(['teacher:id,first_name,last_name', 'classroom:id,name', 'classGroup:id,name', 'subject:id,name'])
            ->where('weekday', $slot['weekday'])
            ->where('starts_at', '<', $slot['ends_at'])
            ->where('ends_at', '>', $slot['starts_at'])
            // Geçerlilik dönemleri kesişmeli
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $slot['valid_from']))
            ->when(! empty($slot['valid_until']), fn ($q) => $q->where('valid_from', '<=', $slot['valid_until']))
            ->where(fn ($q) => $q
                ->where('teacher_id', $slot['teacher_id'])
                ->orWhere('classroom_id', $slot['classroom_id'])
                ->orWhere('class_group_id', $slot['class_group_id']))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();

        $conflicts = [];
        foreach ($candidates as $c) {
            $range = substr($c->starts_at, 0, 5).'–'.substr($c->ends_at, 0, 5);
            if ($c->teacher_id === (int) $slot['teacher_id']) {
                $conflicts[] = ['type' => 'teacher', 'schedule_id' => $c->id,
                    'message' => "{$c->teacher->first_name} {$c->teacher->last_name} bu saatte {$c->classGroup->name} sınıfında {$c->subject->name} dersinde ($range)."];
            }
            if ($c->classroom_id === (int) $slot['classroom_id']) {
                $conflicts[] = ['type' => 'classroom', 'schedule_id' => $c->id,
                    'message' => "{$c->classroom->name} bu saatte {$c->classGroup->name} sınıfına ayrılmış ($range)."];
            }
            if ($c->class_group_id === (int) $slot['class_group_id']) {
                $conflicts[] = ['type' => 'class_group', 'schedule_id' => $c->id,
                    'message' => "{$c->classGroup->name} sınıfının bu saatte {$c->subject->name} dersi var ($range)."];
            }
        }

        return $conflicts;
    }

    /**
     * Somut zaman aralığı için (ders oturumu, etüt, birebir). Öğretmen izni de denetlenir.
     *
     * @return list<array{type:string, message:string}>
     */
    public function forRange(CarbonImmutable $start, CarbonImmutable $end, ?int $teacherId, ?int $classroomId, ?int $classGroupId = null, ?int $ignoreSessionId = null, ?int $ignoreStudyId = null): array
    {
        if ($start->gte($end)) {
            throw new BusinessRuleException('Bitiş saati başlangıçtan sonra olmalı.', 'invalid_time_range');
        }

        $conflicts = [];

        $sessions = LessonSession::query()
            ->with(['classGroup:id,name', 'classroom:id,name', 'subject:id,name'])
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->where(fn ($q) => $q
                ->when($teacherId, fn ($q) => $q->orWhere('teacher_id', $teacherId))
                ->when($classroomId, fn ($q) => $q->orWhere('classroom_id', $classroomId))
                ->when($classGroupId, fn ($q) => $q->orWhere('class_group_id', $classGroupId)))
            ->when($ignoreSessionId, fn ($q) => $q->whereKeyNot($ignoreSessionId))
            ->get();

        foreach ($sessions as $s) {
            $range = $s->starts_at->format('H:i').'–'.$s->ends_at->format('H:i');
            if ($teacherId && $s->teacher_id === $teacherId) {
                $conflicts[] = ['type' => 'teacher', 'message' => "Öğretmenin bu saatte {$s->classGroup->name} sınıfında {$s->subject->name} dersi var ($range)."];
            }
            if ($classroomId && $s->classroom_id === $classroomId) {
                $conflicts[] = ['type' => 'classroom', 'message' => "{$s->classroom->name} bu saatte dolu ($range)."];
            }
            if ($classGroupId && $s->class_group_id === $classGroupId) {
                $conflicts[] = ['type' => 'class_group', 'message' => "{$s->classGroup->name} sınıfının bu saatte dersi var ($range)."];
            }
        }

        $studies = StudySession::query()
            ->whereIn('status', ['requested', 'approved'])
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->where(fn ($q) => $q
                ->when($teacherId, fn ($q) => $q->orWhere('teacher_id', $teacherId))
                ->when($classroomId, fn ($q) => $q->orWhere('classroom_id', $classroomId)))
            ->when($ignoreStudyId, fn ($q) => $q->whereKeyNot($ignoreStudyId))
            ->get();

        foreach ($studies as $s) {
            $range = $s->starts_at->format('H:i').'–'.$s->ends_at->format('H:i');
            $label = $s->kind === 'private' ? 'birebir ders' : 'etüt';
            if ($teacherId && $s->teacher_id === $teacherId) {
                $conflicts[] = ['type' => 'teacher', 'message' => "Öğretmenin bu saatte $label kaydı var ($range)."];
            }
            if ($classroomId && $s->classroom_id === $classroomId) {
                $conflicts[] = ['type' => 'classroom', 'message' => "Derslik bu saatte $label için ayrılmış ($range)."];
            }
        }

        if ($teacherId) {
            $onLeave = TeacherLeave::query()
                ->where('teacher_id', $teacherId)->where('status', 'approved')
                ->whereDate('starts_on', '<=', $start->toDateString())
                ->whereDate('ends_on', '>=', $start->toDateString())
                ->exists();

            if ($onLeave) {
                $conflicts[] = ['type' => 'teacher_leave', 'message' => 'Öğretmen bu tarihte izinli.'];
            }
        }

        return $conflicts;
    }

    /** @param list<array{type:string, message:string}> $conflicts */
    public static function throwIfAny(array $conflicts): void
    {
        if ($conflicts !== []) {
            throw new BusinessRuleException(
                'Çakışma var: '.$conflicts[0]['message'],
                'schedule_conflict',
                ['conflicts' => $conflicts],
                409,
            );
        }
    }
}

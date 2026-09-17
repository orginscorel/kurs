<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\Holiday;
use App\Models\LessonSession;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Birleşik takvim: ders oturumları, etüt/birebir, sınavlar, öğretmen izinleri ve tatiller tek olay listesinde.
 * Görünürlük izinle daraltılır: etüt (study.view), sınav (exams.view), izin (teachers.view|schedule.manage).
 */
class CalendarService
{
    public const TYPES = ['lesson', 'study', 'exam', 'leave', 'holiday'];

    public const MAX_DAYS = 62;

    /**
     * @param  array{types?:list<string>, class_group_id?:?int, teacher_id?:?int, classroom_id?:?int, student_id?:?int}  $f
     */
    public function events(CarbonImmutable $from, CarbonImmutable $to, array $f, ?User $user): array
    {
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new BusinessRuleException('Takvimde en fazla '.self::MAX_DAYS.' günlük aralık sorgulanabilir.', 'range_too_wide');
        }
        $types = array_values(array_intersect($f['types'] ?? self::TYPES, self::TYPES)) ?: self::TYPES;
        $can = fn (string $p) => $user?->can($p) ?? false;
        $classId = $f['class_group_id'] ?? null;
        $teacherId = $f['teacher_id'] ?? null;
        $roomId = $f['classroom_id'] ?? null;
        $studentId = $f['student_id'] ?? null;
        $groupIds = null;
        if ($studentId) {
            if (! $can('students.view')) {
                abort(403);
            }
            $groupIds = Student::query()->findOrFail($studentId)->currentClassGroups()->pluck('class_groups.id')->all();
        }
        $d1 = $from->toDateString();
        $d2 = $to->toDateString();
        $now = CarbonImmutable::now();
        $events = [];

        if (in_array('lesson', $types, true)) {
            $rows = LessonSession::query()
                ->with(['subject:id,name,short_name,color', 'teacher:id,first_name,last_name', 'classroom:id,name', 'classGroup:id,name'])
                ->withCount(['attendances as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late']), 'attendances as absent_count' => fn ($q) => $q->where('status', 'absent')])
                ->whereBetween('date', [$d1, $d2])
                ->when($classId, fn ($q) => $q->where('class_group_id', $classId))
                ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
                ->when($roomId, fn ($q) => $q->where('classroom_id', $roomId))
                ->when($groupIds !== null, fn ($q) => $q->whereIn('class_group_id', $groupIds ?: [0]))
                ->orderBy('starts_at')->get();

            foreach ($rows as $s) {
                $events[] = [
                    'key' => "lesson-{$s->id}", 'type' => 'lesson', 'id' => $s->id, 'all_day' => false,
                    'date' => $s->date->toDateString(), 'end_date' => null, 'starts_at' => $s->starts_at->format('H:i'), 'ends_at' => $s->ends_at->format('H:i'),
                    'title' => $s->subject?->name ?? 'Ders', 'short' => $s->subject?->short_name, 'color' => $s->subject?->color,
                    'subtitle' => trim(($s->classGroup?->name ?? '').' · '.($s->teacher?->full_name ?? '').' · '.($s->classroom?->name ?? ''), ' ·'),
                    'status' => $s->status,
                    'phase' => $s->status === 'cancelled' ? 'cancelled' : ($now->lt($s->starts_at) ? 'upcoming' : ($now->lt($s->ends_at) ? 'in_progress' : 'done')),
                    'lesson' => [
                        'schedule_id' => $s->lesson_schedule_id, 'makeup_of_id' => $s->makeup_of_id, 'holiday_id' => $s->holiday_id,
                        'subject' => ['id' => $s->subject_id, 'name' => $s->subject?->name, 'color' => $s->subject?->color],
                        'teacher' => ['id' => $s->teacher_id, 'name' => $s->teacher?->full_name], 'classroom' => ['id' => $s->classroom_id, 'name' => $s->classroom?->name],
                        'class_group' => ['id' => $s->class_group_id, 'name' => $s->classGroup?->name],
                        'attendance_taken' => (bool) $s->attendance_taken_at, 'present_count' => (int) $s->present_count, 'absent_count' => (int) $s->absent_count,
                        'topic_note' => $s->topic_note, 'cancel_reason' => $s->cancel_reason,
                    ],
                ];
            }
        }

        if (in_array('study', $types, true) && $can('study.view') && ! $classId) {
            $rows = DB::table('study_sessions as ss')->leftJoin('teachers as t', 't.id', '=', 'ss.teacher_id')->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')->leftJoin('classrooms as c', 'c.id', '=', 'ss.classroom_id')
                ->where('ss.branch_id', app(\App\Support\BranchContext::class)->require())->whereIn('ss.status', ['requested', 'approved', 'completed'])
                ->whereBetween('ss.starts_at', [$from->startOfDay(), $to->endOfDay()])
                ->when($teacherId, fn ($q) => $q->where('ss.teacher_id', $teacherId))
                ->when($roomId, fn ($q) => $q->where('ss.classroom_id', $roomId))
                ->when($studentId, fn ($q) => $q->whereExists(fn ($x) => $x->from('study_session_student')->whereColumn('study_session_id', 'ss.id')->where('student_id', $studentId)))
                ->orderBy('ss.starts_at')
                ->get(['ss.id', 'ss.kind', 'ss.status', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 's.name as subject', 'c.name as classroom', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);
            foreach ($rows as $r) {
                $events[] = [
                    'key' => "study-{$r->id}", 'type' => 'study', 'id' => $r->id, 'all_day' => false,
                    'date' => substr($r->starts_at, 0, 10), 'end_date' => null, 'starts_at' => substr($r->starts_at, 11, 5), 'ends_at' => substr($r->ends_at, 11, 5),
                    'title' => ($r->kind === 'private' ? 'Birebir' : 'Etüt').($r->subject ? " · {$r->subject}" : ''), 'short' => null, 'color' => null,
                    'subtitle' => trim(($r->teacher ?? '').($r->classroom ? " · {$r->classroom}" : '').($r->topic ? " · {$r->topic}" : '')),
                    'status' => $r->status, 'phase' => $r->status === 'requested' ? 'pending' : 'upcoming',
                ];
            }
        }

        if (in_array('exam', $types, true) && $can('exams.view') && ! $teacherId && ! $roomId) {
            $rows = DB::table('exams as e')->leftJoin('exam_types as et', 'et.id', '=', 'e.exam_type_id')
                ->where('e.branch_id', app(\App\Support\BranchContext::class)->require())->whereNull('e.deleted_at')
                ->whereBetween('e.exam_date', [$d1, $d2])->orderBy('e.exam_date')
                ->get(['e.id', 'e.name', 'e.exam_date', 'e.status', 'e.publisher', 'et.name as type']);
            foreach ($rows as $r) {
                $events[] = [
                    'key' => "exam-{$r->id}", 'type' => 'exam', 'id' => $r->id, 'all_day' => true, 'date' => $r->exam_date, 'end_date' => null, 'starts_at' => null, 'ends_at' => null,
                    'title' => $r->name, 'short' => null, 'color' => null, 'subtitle' => trim(($r->type ?? 'Sınav').($r->publisher ? " · {$r->publisher}" : '')), 'status' => $r->status, 'phase' => null,
                ];
            }
        }

        if (in_array('leave', $types, true) && ($can('teachers.view') || $can('schedule.manage')) && ! $classId && ! $roomId && ! $studentId) {
            $rows = DB::table('teacher_leaves as l')->join('teachers as t', 't.id', '=', 'l.teacher_id')
                ->where('t.branch_id', app(\App\Support\BranchContext::class)->require())->where('l.status', 'approved')
                ->where('l.starts_on', '<=', $d2)->where('l.ends_on', '>=', $d1)
                ->when($teacherId, fn ($q) => $q->where('l.teacher_id', $teacherId))
                ->get(['l.id', 'l.teacher_id', 'l.starts_on', 'l.ends_on', 'l.kind', DB::raw("CONCAT(t.first_name,' ',t.last_name) AS teacher")]);
            $kinds = ['annual' => 'Yıllık izin', 'sick' => 'Raporlu', 'excuse' => 'Mazeret izni', 'other' => 'İzin'];
            foreach ($rows as $r) {
                $events[] = [
                    'key' => "leave-{$r->id}", 'type' => 'leave', 'id' => $r->id, 'all_day' => true, 'date' => $r->starts_on, 'end_date' => $r->ends_on, 'starts_at' => null, 'ends_at' => null,
                    'title' => "{$r->teacher} izinli", 'short' => null, 'color' => null, 'subtitle' => $kinds[$r->kind] ?? 'İzin', 'status' => 'approved', 'phase' => null, 'teacher_id' => (int) $r->teacher_id,
                ];
            }
        }

        if (in_array('holiday', $types, true)) {
            foreach (Holiday::query()->where('starts_on', '<=', $d2)->where('ends_on', '>=', $d1)->orderBy('starts_on')->get() as $h) {
                $events[] = [
                    'key' => "holiday-{$h->id}", 'type' => 'holiday', 'id' => $h->id, 'all_day' => true, 'date' => $h->starts_on->toDateString(), 'end_date' => $h->ends_on->toDateString(),
                    'starts_at' => null, 'ends_at' => null, 'title' => $h->name, 'short' => null, 'color' => null,
                    'subtitle' => (Holiday::KINDS[$h->kind] ?? 'Tatil').($h->cancel_sessions ? " · {$h->cancelled_count} ders iptal" : ''), 'status' => null, 'phase' => null,
                    'holiday' => ['kind' => $h->kind, 'cancel_sessions' => $h->cancel_sessions, 'cancelled_count' => $h->cancelled_count, 'notes' => $h->notes],
                ];
            }
        }

        usort($events, fn ($a, $b) => [$a['date'], $a['all_day'] ? 0 : 1, $a['starts_at'] ?? ''] <=> [$b['date'], $b['all_day'] ? 0 : 1, $b['starts_at'] ?? '']);

        $lessons = array_filter($events, fn ($e) => $e['type'] === 'lesson');

        return [
            'from' => $d1, 'to' => $d2, 'events' => array_values($events),
            'summary' => [
                'lessons' => count($lessons), 'cancelled' => count(array_filter($lessons, fn ($e) => $e['status'] === 'cancelled')),
                'studies' => count(array_filter($events, fn ($e) => $e['type'] === 'study')), 'exams' => count(array_filter($events, fn ($e) => $e['type'] === 'exam')),
                'leaves' => count(array_filter($events, fn ($e) => $e['type'] === 'leave')), 'holidays' => count(array_filter($events, fn ($e) => $e['type'] === 'holiday')),
            ],
        ];
    }

    /** iCal akışı olayları: sahibine göre −14 / +90 gün. */
    public function feedEvents(string $ownerType, int $ownerId): array
    {
        $from = CarbonImmutable::today()->subDays(14);
        $to = CarbonImmutable::today()->addDays(90);
        $groupIds = match ($ownerType) {
            'student' => Student::query()->findOrFail($ownerId)->currentClassGroups()->pluck('class_groups.id')->all(),
            'class_group' => [$ownerId],
            default => null,
        };

        $events = [];
        LessonSession::query()->with(['subject:id,name', 'teacher:id,first_name,last_name', 'classroom:id,name', 'classGroup:id,name'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($ownerType === 'teacher', fn ($q) => $q->where('teacher_id', $ownerId))
            ->when($ownerType === 'classroom', fn ($q) => $q->where('classroom_id', $ownerId))
            ->when($groupIds !== null, fn ($q) => $q->whereIn('class_group_id', $groupIds ?: [0]))
            ->orderBy('starts_at')->chunk(500, function ($rows) use (&$events, $ownerType) {
                foreach ($rows as $s) {
                    $events[] = [
                        'uid' => "lesson-{$s->id}", 'date' => $s->date->toDateString(), 'start' => $s->starts_at->format('H:i'), 'end' => $s->ends_at->format('H:i'),
                        'title' => ($s->subject?->name ?? 'Ders').($ownerType === 'teacher' || $ownerType === 'classroom' ? ' · '.$s->classGroup?->name : ''),
                        'location' => $s->classroom?->name, 'description' => trim('Öğretmen: '.($s->teacher?->full_name ?? '—').($s->cancel_reason ? "\nİptal: {$s->cancel_reason}" : '').($s->topic_note ? "\n{$s->topic_note}" : '')),
                        'cancelled' => $s->status === 'cancelled', 'updated' => $s->updated_at?->toDateTimeString(),
                    ];
                }
            });

        if (in_array($ownerType, ['teacher', 'student'], true)) {
            DB::table('study_sessions as ss')->leftJoin('subjects as s', 's.id', '=', 'ss.subject_id')->leftJoin('classrooms as c', 'c.id', '=', 'ss.classroom_id')
                ->where('ss.branch_id', app(\App\Support\BranchContext::class)->require())->whereIn('ss.status', ['approved', 'completed', 'cancelled'])
                ->whereBetween('ss.starts_at', [$from->startOfDay(), $to->endOfDay()])
                ->when($ownerType === 'teacher', fn ($q) => $q->where('ss.teacher_id', $ownerId))
                ->when($ownerType === 'student', fn ($q) => $q->whereExists(fn ($x) => $x->from('study_session_student')->whereColumn('study_session_id', 'ss.id')->where('student_id', $ownerId)))
                ->get(['ss.id', 'ss.kind', 'ss.status', 'ss.starts_at', 'ss.ends_at', 'ss.topic', 's.name as subject', 'c.name as classroom'])
                ->each(function ($r) use (&$events) {
                    $events[] = ['uid' => "study-{$r->id}", 'date' => substr($r->starts_at, 0, 10), 'start' => substr($r->starts_at, 11, 5), 'end' => substr($r->ends_at, 11, 5),
                        'title' => ($r->kind === 'private' ? 'Birebir' : 'Etüt').($r->subject ? " · {$r->subject}" : ''), 'location' => $r->classroom, 'description' => $r->topic, 'cancelled' => $r->status === 'cancelled'];
                });
        }

        foreach (Holiday::query()->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get() as $h) {
            $events[] = ['uid' => "holiday-{$h->id}", 'date' => $h->starts_on->toDateString(), 'end_date' => $h->ends_on->toDateString(), 'title' => $h->name, 'description' => Holiday::KINDS[$h->kind] ?? 'Tatil'];
        }

        return $events;
    }
}

<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\Student;
use App\Models\StudySession;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Etüt (grup) ve birebir ders talepleri: oluşturma → onay/ret → katılım.
 * Öğretmen/derslik çakışması ve öğretmen izni ScheduleConflicts::forRange ile denetlenir.
 */
class StudyService
{
    public const STATUSES = ['requested' => 'Onay bekliyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'completed' => 'Tamamlandı', 'cancelled' => 'İptal'];

    public function __construct(private readonly ScheduleConflicts $conflicts) {}

    public function create(array $data, User $user, bool $autoApprove): StudySession
    {
        $start = CarbonImmutable::parse($data['starts_at']);
        $end = CarbonImmutable::parse($data['ends_at']);
        $this->assertRange($start, $end);
        ScheduleConflicts::throwIfAny($this->conflicts->forRange($start, $end, (int) $data['teacher_id'], $data['classroom_id'] ?? null));

        $studentIds = array_values(array_unique(array_map('intval', $data['student_ids'] ?? [])));
        $capacity = (int) ($data['capacity'] ?? ($data['kind'] === 'private' ? 1 : 6));
        if ($data['kind'] === 'private') {
            $capacity = 1;
        }
        if (count($studentIds) > $capacity) {
            throw new BusinessRuleException("Kontenjan {$capacity} kişi; ".count($studentIds).' öğrenci seçildi.', 'capacity_exceeded');
        }

        return DB::transaction(function () use ($data, $user, $autoApprove, $start, $end, $studentIds, $capacity) {
            $session = StudySession::query()->create([
                'kind' => $data['kind'], 'teacher_id' => $data['teacher_id'], 'subject_id' => $data['subject_id'] ?? null,
                'classroom_id' => $data['classroom_id'] ?? null, 'topic' => $data['topic'] ?? null,
                'starts_at' => $start, 'ends_at' => $end, 'capacity' => $capacity,
                'status' => $autoApprove ? 'approved' : 'requested', 'requested_by' => $user->id,
                'approved_by' => $autoApprove ? $user->id : null,
                'fee' => $data['kind'] === 'private' ? ($data['fee'] ?? null) : null, 'notes' => $data['notes'] ?? null,
            ]);
            if ($studentIds) {
                $session->students()->attach($studentIds);
            }
            $session->load('teacher:id,first_name,last_name');
            Audit::log('study.created', sprintf('%s öğretmeniyle %s tarihinde %s %s (%d öğrenci).',
                $session->teacher->full_name, $start->format('d.m.Y H:i'), $session->kind === 'private' ? 'birebir ders' : 'etüt',
                $autoApprove ? 'planladı' : 'talebi oluşturdu', count($studentIds)), $session);

            return $session;
        });
    }

    public function update(StudySession $session, array $data): StudySession
    {
        if (in_array($session->status, ['completed', 'cancelled', 'rejected'], true)) {
            throw new BusinessRuleException('Kapanmış kayıt düzenlenemez.', 'session_closed');
        }
        $start = CarbonImmutable::parse($data['starts_at'] ?? $session->starts_at);
        $end = CarbonImmutable::parse($data['ends_at'] ?? $session->ends_at);
        $this->assertRange($start, $end);
        $teacherId = (int) ($data['teacher_id'] ?? $session->teacher_id);
        $classroomId = array_key_exists('classroom_id', $data) ? $data['classroom_id'] : $session->classroom_id;
        ScheduleConflicts::throwIfAny($this->conflicts->forRange($start, $end, $teacherId, $classroomId, null, null, $session->id));

        $capacity = $session->kind === 'private' ? 1 : (int) ($data['capacity'] ?? $session->capacity);
        if ($session->students()->count() > $capacity) {
            throw new BusinessRuleException('Kontenjan kayıtlı öğrenci sayısının altına indirilemez.', 'capacity_below_members');
        }

        $session->fill([
            'teacher_id' => $teacherId, 'subject_id' => $data['subject_id'] ?? $session->subject_id, 'classroom_id' => $classroomId,
            'topic' => $data['topic'] ?? $session->topic, 'starts_at' => $start, 'ends_at' => $end, 'capacity' => $capacity,
            'fee' => $session->kind === 'private' ? ($data['fee'] ?? $session->fee) : null, 'notes' => $data['notes'] ?? $session->notes,
        ])->save();

        return $session;
    }

    public function approve(StudySession $session, User $user): StudySession
    {
        if ($session->status !== 'requested') {
            throw new BusinessRuleException('Yalnızca onay bekleyen talep onaylanabilir.', 'not_requested');
        }
        ScheduleConflicts::throwIfAny($this->conflicts->forRange(
            CarbonImmutable::parse($session->starts_at), CarbonImmutable::parse($session->ends_at), $session->teacher_id, $session->classroom_id, null, null, $session->id));

        $session->forceFill(['status' => 'approved', 'approved_by' => $user->id])->save();
        Audit::log('study.approved', "#{$session->id} {$this->label($session)} talebini onayladı.", $session);

        return $session;
    }

    public function reject(StudySession $session, ?string $reason): StudySession
    {
        if ($session->status !== 'requested') {
            throw new BusinessRuleException('Yalnızca onay bekleyen talep reddedilebilir.', 'not_requested');
        }
        $session->forceFill(['status' => 'rejected', 'notes' => $this->appendNote($session->notes, 'Ret gerekçesi: '.($reason ?: '—'))])->save();
        Audit::log('study.rejected', "#{$session->id} {$this->label($session)} talebini reddetti.".($reason ? " Gerekçe: $reason" : ''), $session);

        return $session;
    }

    public function cancel(StudySession $session, ?string $reason): StudySession
    {
        if (! in_array($session->status, ['requested', 'approved'], true)) {
            throw new BusinessRuleException('Bu kayıt iptal edilemez.', 'not_cancellable');
        }
        $session->forceFill(['status' => 'cancelled', 'notes' => $this->appendNote($session->notes, 'İptal: '.($reason ?: '—'))])->save();
        Audit::log('study.cancelled', "#{$session->id} {$this->label($session)} kaydını iptal etti.".($reason ? " Gerekçe: $reason" : ''), $session);

        return $session;
    }

    /** @param list<int> $studentIds */
    public function addStudents(StudySession $session, array $studentIds): StudySession
    {
        if (! in_array($session->status, ['requested', 'approved'], true)) {
            throw new BusinessRuleException('Kapanmış kayda öğrenci eklenemez.', 'session_closed');
        }
        $existing = $session->students()->pluck('students.id')->all();
        $new = array_values(array_diff(array_unique(array_map('intval', $studentIds)), $existing));
        if (count($existing) + count($new) > $session->capacity) {
            throw new BusinessRuleException("Kontenjan dolu ({$session->capacity} kişi).", 'capacity_exceeded');
        }
        $valid = Student::query()->whereIn('id', $new)->pluck('id')->all();
        if ($valid) {
            $session->students()->attach($valid);
        }

        return $session;
    }

    public function removeStudent(StudySession $session, int $studentId): void
    {
        if ($session->status === 'completed') {
            throw new BusinessRuleException('Tamamlanmış kayıttan öğrenci çıkarılamaz.', 'session_closed');
        }
        $session->students()->detach($studentId);
    }

    /** @param array<int,string> $marks  student_id => present|absent|late */
    public function markAttendance(StudySession $session, array $marks): StudySession
    {
        if (! in_array($session->status, ['approved', 'completed'], true)) {
            throw new BusinessRuleException('Katılım yalnızca onaylanmış kayıt için işaretlenir.', 'not_approved');
        }
        DB::transaction(function () use ($session, $marks) {
            foreach ($marks as $studentId => $mark) {
                $session->students()->updateExistingPivot((int) $studentId, ['attendance' => $mark]);
            }
            if ($session->status !== 'completed') {
                $session->forceFill(['status' => 'completed'])->save();
            }
        });
        Audit::log('study.attendance', "#{$session->id} {$this->label($session)} katılımını işaretledi (".count($marks).' öğrenci).', $session);

        return $session;
    }

    // ------------------------------------------------------------------ uygunluk

    /** @param list<array{weekday:int, starts_at:string, ends_at:string}> $slots */
    public function setAvailability(Teacher $teacher, array $slots): void
    {
        $rows = [];
        foreach ($slots as $slot) {
            $s = TimeSlots::toMinutes($slot['starts_at']);
            $e = TimeSlots::toMinutes($slot['ends_at']);
            if ($e <= $s) {
                throw new BusinessRuleException('Uygunluk bitişi başlangıçtan sonra olmalı.', 'invalid_time_range');
            }
            $rows[] = ['teacher_id' => $teacher->id, 'weekday' => (int) $slot['weekday'], 'starts_at' => TimeSlots::toTime($s).':00', 'ends_at' => TimeSlots::toTime($e).':00'];
        }
        DB::transaction(function () use ($teacher, $rows) {
            TeacherAvailability::query()->where('teacher_id', $teacher->id)->delete();
            if ($rows) {
                TeacherAvailability::query()->insert($rows);
            }
        });
        Audit::log('teacher.availability_updated', "{$teacher->full_name} öğretmeninin uygunluk takvimini güncelledi (".count($rows).' aralık).', $teacher);
    }

    /**
     * Bir günde öğretmenin boş aralıkları: uygunluk pencereleri − (dersler + etütler).
     * Uygunluk tanımlı değilse 09:00–21:00 varsayılır.
     *
     * @return array{windows: list<array{0:string,1:string}>, busy: list<array{start:string,end:string,label:string}>, free: list<array{0:string,1:string}>, defined: bool}
     */
    public function freeSlots(Teacher $teacher, CarbonImmutable $date, int $minMinutes = 30): array
    {
        $availability = TeacherAvailability::query()->where('teacher_id', $teacher->id)->where('weekday', $date->dayOfWeekIso)->orderBy('starts_at')->get();
        $defined = TeacherAvailability::query()->where('teacher_id', $teacher->id)->exists();
        $windows = $defined
            ? $availability->map(fn ($a) => [TimeSlots::toMinutes($a->starts_at), TimeSlots::toMinutes($a->ends_at)])->all()
            : [[9 * 60, 21 * 60]];

        $dayStart = $date->startOfDay();
        $dayEnd = $date->endOfDay();
        $busy = [];

        DB::table('lesson_sessions as ls')->join('subjects as s', 's.id', '=', 'ls.subject_id')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->where('ls.teacher_id', $teacher->id)->where('ls.status', '!=', 'cancelled')->whereBetween('ls.starts_at', [$dayStart, $dayEnd])
            ->orderBy('ls.starts_at')->get(['ls.starts_at', 'ls.ends_at', 's.name as subject', 'cg.name as group_name'])
            ->each(function ($r) use (&$busy) {
                $busy[] = ['start' => substr($r->starts_at, 11, 5), 'end' => substr($r->ends_at, 11, 5), 'label' => "{$r->group_name} · {$r->subject}"];
            });

        StudySession::query()->where('teacher_id', $teacher->id)->whereIn('status', ['requested', 'approved'])->whereBetween('starts_at', [$dayStart, $dayEnd])
            ->orderBy('starts_at')->get(['starts_at', 'ends_at', 'kind', 'topic'])
            ->each(function ($r) use (&$busy) {
                $busy[] = ['start' => $r->starts_at->format('H:i'), 'end' => $r->ends_at->format('H:i'), 'label' => ($r->kind === 'private' ? 'Birebir' : 'Etüt').($r->topic ? " · {$r->topic}" : '')];
            });

        $busyMinutes = array_map(fn ($b) => [TimeSlots::toMinutes($b['start']), TimeSlots::toMinutes($b['end'])], $busy);
        $onLeave = DB::table('teacher_leaves')->where('teacher_id', $teacher->id)->where('status', 'approved')
            ->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())->exists();

        $free = $onLeave ? [] : TimeSlots::subtract($windows, $busyMinutes, $minMinutes);

        return [
            'defined' => $defined,
            'on_leave' => $onLeave,
            'windows' => array_map(fn ($w) => [TimeSlots::toTime($w[0]), TimeSlots::toTime($w[1])], $windows),
            'busy' => $busy,
            'free' => array_map(fn ($w) => [TimeSlots::toTime($w[0]), TimeSlots::toTime($w[1])], $free),
        ];
    }

    private function assertRange(CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($start->gte($end)) {
            throw new BusinessRuleException('Bitiş saati başlangıçtan sonra olmalı.', 'invalid_time_range');
        }
        if ($start->diffInMinutes($end) > 6 * 60) {
            throw new BusinessRuleException('Tek kayıt en fazla 6 saat olabilir.', 'too_long');
        }
    }

    private function label(StudySession $s): string
    {
        return $s->kind === 'private' ? 'birebir ders' : 'etüt';
    }

    private function appendNote(?string $notes, string $line): string
    {
        return mb_substr(trim(($notes ? $notes."\n" : '').$line), 0, 1000);
    }
}

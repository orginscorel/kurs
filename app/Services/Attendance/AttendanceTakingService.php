<?php

namespace App\Services\Attendance;

use App\Events\StudentMarkedAbsent;
use App\Events\StudentMarkedLate;
use App\Exceptions\BusinessRuleException;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Models\User;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hızlı yoklama ekranı: bugünkü dersler → sınıf listesi → tek istekte kaydet.
 * Öğretmen yalnız kendi derslerini görür/alır; attendance.override olan herkesinkini.
 */
class AttendanceTakingService
{
    /** @return \Illuminate\Support\Collection */
    public function sessionsForDate(User $user, string $date)
    {
        $branchId = app(BranchContext::class)->require();
        $canOverride = $user->can('attendance.override');
        $teacherId = $user->teacher?->id;

        $query = LessonSession::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->whereDate('date', $date)->where('status', '!=', 'cancelled')
            ->when(! $canOverride && $teacherId, fn (Builder $q) => $q->where('teacher_id', $teacherId))
            ->with(['subject:id,name,color', 'classGroup:id,name', 'classroom:id,name', 'teacher:id,first_name,last_name'])
            ->orderBy('starts_at')
            ->get();

        $counts = Attendance::query()->withoutGlobalScope('branch')
            ->whereIn('lesson_session_id', $query->pluck('id'))
            ->selectRaw("lesson_session_id, COUNT(*) AS taken, SUM(status='present') AS present, SUM(status='late') AS late, SUM(status='absent') AS absent, SUM(status IN ('excused','medical')) AS excused")
            ->groupBy('lesson_session_id')->get()->keyBy('lesson_session_id');

        $rosterCounts = DB::table('class_group_student')->whereNull('left_on')
            ->whereIn('class_group_id', $query->pluck('class_group_id')->unique())
            ->selectRaw('class_group_id, COUNT(*) AS c')->groupBy('class_group_id')->pluck('c', 'class_group_id');

        return $query->map(function (LessonSession $s) use ($counts, $rosterCounts) {
            $c = $counts[$s->id] ?? null;

            return [
                'id' => $s->id, 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'status' => $s->status,
                'subject' => $s->subject?->name, 'subject_color' => $s->subject?->color,
                'class_group' => $s->classGroup?->name, 'class_group_id' => $s->class_group_id,
                'classroom' => $s->classroom?->name, 'teacher' => $s->teacher?->full_name,
                'roster' => (int) ($rosterCounts[$s->class_group_id] ?? 0),
                'taken' => (int) ($c->taken ?? 0),
                'present' => (int) ($c->present ?? 0), 'late' => (int) ($c->late ?? 0), 'absent' => (int) ($c->absent ?? 0), 'excused' => (int) ($c->excused ?? 0),
                'attendance_taken_at' => $s->attendance_taken_at,
            ];
        })->values();
    }

    public function assertCanTake(LessonSession $session, User $user): void
    {
        if ($user->can('attendance.override')) {
            return;
        }
        $teacherId = $user->teacher?->id;
        if (! $teacherId || $session->teacher_id !== $teacherId) {
            throw new BusinessRuleException('Bu ders size ait değil; yalnız kendi derslerinizin yoklamasını alabilirsiniz.', 'not_own_lesson', [], 403);
        }
    }

    /** Roster + biyometrik ön dolgu (öğrencinin bugünkü ilk giriş saati). */
    public function roster(LessonSession $session): array
    {
        $session->loadMissing(['subject:id,name', 'classGroup:id,name', 'classroom:id,name', 'teacher:id,first_name,last_name']);

        $students = DB::table('class_group_student as cgs')->join('students as st', 'st.id', '=', 'cgs.student_id')
            ->where('cgs.class_group_id', $session->class_group_id)->whereNull('cgs.left_on')->whereNull('st.deleted_at')
            ->orderBy('st.full_name')
            ->get(['st.id', 'st.full_name', 'st.student_no', 'st.photo_path']);

        $existing = Attendance::query()->withoutGlobalScope('branch')
            ->where('lesson_session_id', $session->id)->get()->keyBy('student_id');

        $presences = DB::table('daily_presences')->whereIn('student_id', $students->pluck('id'))
            ->where('date', $session->date->toDateString())->get()->keyBy('student_id');

        // Disiplin: uzaklaştırmadaki öğrenci işaretlenir (ön yüz varsayılanı "izinli" + disiplin notu)
        $suspended = app(\App\Services\Discipline\SuspensionCalendar::class)->onDate($students->pluck('id'), $session->date->toDateString());

        $rows = $students->map(function ($s) use ($existing, $presences, $suspended) {
            $att = $existing[$s->id] ?? null;
            $p = $presences[$s->id] ?? null;

            return [
                'student_id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'photo_path' => $s->photo_path,
                'status' => $att->status ?? null, 'late_minutes' => $att->late_minutes ?? null, 'note' => $att->note ?? null, 'method' => $att->method ?? null,
                'first_entry_at' => $p->first_entry_at ?? null,
                'discipline' => $suspended[$s->id]['label'] ?? null,
            ];
        });

        return [
            'session' => [
                'id' => $session->id, 'date' => $session->date->toDateString(), 'starts_at' => $session->starts_at, 'ends_at' => $session->ends_at,
                'subject' => $session->subject?->name, 'class_group' => $session->classGroup?->name, 'classroom' => $session->classroom?->name,
                'teacher' => $session->teacher?->full_name, 'attendance_taken_at' => $session->attendance_taken_at, 'attendance_taken_by' => $session->attendance_taken_by,
            ],
            'students' => $rows,
        ];
    }

    /**
     * @param list<array{student_id:int, status:string, late_minutes?:int|null, note?:string|null}> $rows
     */
    public function save(LessonSession $session, array $rows, User $user): int
    {
        $session->loadMissing(['subject:id,name', 'classGroup:id,name']);
        $branchId = $session->branch_id;
        $method = ($session->teacher_id === $user->teacher?->id) ? 'teacher' : 'admin';

        DB::transaction(function () use ($session, $rows, $user, $branchId, $method) {
            $existing = Attendance::query()->withoutGlobalScope('branch')
                ->where('lesson_session_id', $session->id)->get()->keyBy('student_id');

            foreach ($rows as $row) {
                $previous = $existing[$row['student_id']] ?? null;
                $status = $row['status'];

                $attendance = Attendance::query()->withoutGlobalScope('branch')->updateOrCreate(
                    ['lesson_session_id' => $session->id, 'student_id' => $row['student_id']],
                    [
                        'branch_id' => $branchId, 'date' => $session->date, 'status' => $status,
                        'late_minutes' => $status === 'late' ? ($row['late_minutes'] ?? null) : null,
                        'note' => $row['note'] ?? null, 'method' => $method, 'recorded_by' => $user->id,
                    ],
                );

                if ($status === 'absent' && ($previous === null || $previous->status !== 'absent')) {
                    DB::afterCommit(fn () => event(new StudentMarkedAbsent($attendance->id)));
                }
                if ($status === 'late' && ($previous === null || $previous->status !== 'late')) {
                    DB::afterCommit(fn () => event(new StudentMarkedLate($attendance->id)));
                }
            }

            $session->forceFill(['attendance_taken_at' => now(), 'attendance_taken_by' => $user->id])->save();
        });

        Audit::log('attendance.taken', "{$session->subject?->name} · {$session->classGroup?->name} dersi için yoklama aldı (".count($rows).' öğrenci).', $session);

        return count($rows);
    }
}

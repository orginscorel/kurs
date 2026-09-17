<?php

namespace App\Services\Placement;

use App\Events\StudentClassChanged;
use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sınıf üyeliği yazımının tek noktası (transaction çağıranda).
 * Geçmiş korunur: eski satır left_on ile kapanır, yeni satır joined_on ile açılır;
 * aynı dönemdeki aktif kaydın (enrollments.class_group_id) sınıfı güncellenir.
 */
class MembershipWriter
{
    /** @var list<array> afterCommit ile atılacak olaylar */
    private array $pendingEvents = [];

    /**
     * @return array{changed:bool, from:?int, to:?int}
     */
    public function move(int $studentId, int $termId, ?int $toGroupId, string $date, ?string $reason, string $source, ?int $runId = null, ?ChangeSet $cs = null, bool $pinned = false, bool $syncEnrollment = true, bool $emit = true): array
    {
        $open = DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->where('cgs.student_id', $studentId)->whereNull('cgs.left_on')->where('cg.academic_term_id', $termId)
            ->orderBy('cgs.id')->get(['cgs.id', 'cgs.class_group_id', 'cgs.joined_on', 'cgs.left_on']);

        $from = $open->first()?->class_group_id;
        if ($open->count() === 1 && $toGroupId !== null && (int) $from === $toGroupId) {
            return ['changed' => false, 'from' => $toGroupId, 'to' => $toGroupId];
        }
        if ($open->isEmpty() && $toGroupId === null) {
            return ['changed' => false, 'from' => null, 'to' => null];
        }

        $now = now();
        foreach ($open as $row) {
            // Çıkış tarihi giriş tarihinden önce olamaz
            $leftOn = CarbonImmutable::parse($row->joined_on)->gt(CarbonImmutable::parse($date)) ? CarbonImmutable::parse($row->joined_on)->toDateString() : $date;
            DB::table('class_group_student')->where('id', $row->id)->update(['left_on' => $leftOn, 'updated_at' => $now]);
            if ($cs && ! array_key_exists($row->id, $cs->closed)) {
                $cs->closed[$row->id] = $row->left_on;
            }
        }

        if ($toGroupId !== null) {
            $id = DB::table('class_group_student')->insertGetId([
                'class_group_id' => $toGroupId, 'student_id' => $studentId, 'joined_on' => $date, 'left_on' => null,
                'is_pinned' => $pinned, 'change_reason' => $reason ? mb_substr($reason, 0, 500) : null, 'changed_by' => Auth::id(),
                'placement_run_id' => $runId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($cs) {
                $cs->created[] = $id;
            }
            $this->resolveWaitlist($studentId, $termId, $cs);
        }

        if ($syncEnrollment) {
            $enrollments = DB::table('enrollments')->where('student_id', $studentId)->where('academic_term_id', $termId)
                ->whereIn('status', ['active', 'frozen'])->whereNull('deleted_at')->get(['id', 'class_group_id', 'status', 'ended_on']);
            foreach ($enrollments as $e) {
                $this->recordEnrollment($cs, $e);
            }
            if ($enrollments->isNotEmpty()) {
                DB::table('enrollments')->whereIn('id', $enrollments->pluck('id'))->update(['class_group_id' => $toGroupId, 'updated_at' => $now]);
            }
        }

        if ($emit) {
            $this->queueEvent($studentId, $from === null ? null : (int) $from, $toGroupId, $source, $date, $reason);
        }

        return ['changed' => true, 'from' => $from === null ? null : (int) $from, 'to' => $toGroupId];
    }

    public function recordEnrollment(?ChangeSet $cs, object $e): void
    {
        if ($cs && ! isset($cs->enrollments[$e->id])) {
            $cs->enrollments[$e->id] = ['class_group_id' => $e->class_group_id, 'status' => $e->status, 'ended_on' => $e->ended_on];
        }
    }

    public function queueEvent(int $studentId, ?int $from, ?int $to, string $source, string $date, ?string $reason): void
    {
        $this->pendingEvents[] = [$studentId, $from, $to, $source, $date, $reason];
    }

    public function addWaitlist(int $studentId, int $termId, ?int $level, ?string $section, string $source, ?string $reason, ?int $runId = null, ?ChangeSet $cs = null): int
    {
        $existing = DB::table('class_waitlist')->where('student_id', $studentId)->where('academic_term_id', $termId)->where('status', 'waiting')->first();
        if ($existing) {
            DB::table('class_waitlist')->where('id', $existing->id)->update(['preferred_section' => $section, 'reason' => $reason ?? $existing->reason, 'grade_level' => $level, 'updated_at' => now()]);

            return (int) $existing->id;
        }
        $id = DB::table('class_waitlist')->insertGetId([
            'branch_id' => app(\App\Support\BranchContext::class)->require(), 'academic_term_id' => $termId, 'student_id' => $studentId,
            'grade_level' => $level, 'preferred_section' => $section, 'source' => $source, 'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'status' => 'waiting', 'placement_run_id' => $runId, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($cs) {
            $cs->waitlistCreated[] = $id;
        }

        return $id;
    }

    private function resolveWaitlist(int $studentId, int $termId, ?ChangeSet $cs): void
    {
        $rows = DB::table('class_waitlist')->where('student_id', $studentId)->where('academic_term_id', $termId)->where('status', 'waiting')->get(['id', 'status', 'resolved_at']);
        foreach ($rows as $r) {
            if ($cs && ! in_array($r->id, $cs->waitlistCreated, true) && ! isset($cs->waitlistResolved[$r->id])) {
                $cs->waitlistResolved[$r->id] = ['status' => $r->status, 'resolved_at' => $r->resolved_at];
            }
        }
        if ($rows->isNotEmpty()) {
            DB::table('class_waitlist')->whereIn('id', $rows->pluck('id'))->update(['status' => 'placed', 'resolved_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Olayları commit sonrasına sırala (tek noktadan). */
    public function flushEvents(): int
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];
        if ($events) {
            DB::afterCommit(function () use ($events) {
                foreach ($events as [$sid, $from, $to, $source, $date, $reason]) {
                    event(new StudentClassChanged($sid, $from, $to, $source, $date, $reason));
                }
            });
        }

        return count($events);
    }

    public function discardEvents(): void
    {
        $this->pendingEvents = [];
    }

    /**
     * Anlık görüntüden geri yükle. Sonradan elle değişiklik yapılmışsa reddeder (veri ezilmez).
     *
     * @return array{memberships:int, students:int}
     */
    public function revert(ChangeSet $cs): array
    {
        if ($cs->created) {
            $stillOpen = DB::table('class_group_student')->whereIn('id', $cs->created)->whereNull('left_on')->count();
            if ($stillOpen !== count($cs->created)) {
                throw new BusinessRuleException('Bu işlemden sonra bazı öğrencilerin sınıfı yeniden değiştirilmiş; geri alma verileri ezeceği için yapılamaz.', 'placement_revert_conflict', [], 409);
            }
        }
        foreach ($cs->students as $id => $s) {
            $row = DB::table('students')->where('id', $id)->first(array_keys($s['after']));
            foreach ($s['after'] as $k => $v) {
                if ($row && (string) $row->{$k} !== (string) $v) {
                    throw new BusinessRuleException('Bu işlemden sonra öğrenci bilgileri değiştirilmiş; geri alma yapılamaz.', 'placement_revert_conflict', ['student_id' => $id], 409);
                }
            }
        }
        // Yeniden açılacak üyeliklerin öğrencisinde (bu işlemin açtıkları dışında) açık üyelik olmamalı
        if ($cs->closed) {
            $closedIds = array_keys($cs->closed);
            $students = DB::table('class_group_student')->whereIn('id', $closedIds)->pluck('student_id')->unique();
            $conflict = DB::table('class_group_student')->whereIn('student_id', $students)->whereNull('left_on')
                ->whereNotIn('id', array_merge($cs->created, $closedIds) ?: [0])->exists();
            if ($conflict) {
                throw new BusinessRuleException('Bu işlemden sonra öğrencilere yeni sınıf ataması yapılmış; geri alma yapılamaz.', 'placement_revert_conflict', [], 409);
            }
        }

        $now = now();
        if ($cs->created) {
            DB::table('class_group_student')->whereIn('id', $cs->created)->delete();
        }
        foreach ($cs->closed as $id => $leftOn) {
            DB::table('class_group_student')->where('id', $id)->update(['left_on' => $leftOn, 'updated_at' => $now]);
        }
        foreach ($cs->enrollments as $id => $e) {
            DB::table('enrollments')->where('id', $id)->update(['class_group_id' => $e['class_group_id'], 'status' => $e['status'], 'ended_on' => $e['ended_on'], 'updated_at' => $now]);
        }
        foreach ($cs->students as $id => $s) {
            DB::table('students')->where('id', $id)->update($s['before'] + ['updated_at' => $now]);
        }
        if ($cs->waitlistCreated) {
            DB::table('class_waitlist')->whereIn('id', $cs->waitlistCreated)->delete();
        }
        foreach ($cs->waitlistResolved as $id => $w) {
            DB::table('class_waitlist')->where('id', $id)->update(['status' => $w['status'], 'resolved_at' => $w['resolved_at'], 'updated_at' => $now]);
        }
        foreach ($cs->groups as $id => $g) {
            DB::table('class_groups')->where('id', $id)->update(['is_active' => $g['is_active'], 'updated_at' => $now]);
        }
        foreach ($cs->schedules as $id => $validUntil) {
            DB::table('lesson_schedules')->where('id', $id)->update(['valid_until' => $validUntil, 'updated_at' => $now]);
        }
        foreach ($cs->sessions as $id => $s) {
            DB::table('lesson_sessions')->where('id', $id)->update(['status' => $s['status'], 'cancel_reason' => $s['cancel_reason'], 'updated_at' => $now]);
        }

        return ['memberships' => count($cs->created) + count($cs->closed), 'students' => count($cs->students)];
    }
}

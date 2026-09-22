<?php

namespace App\Services\Coaching;

use App\Models\CoachingAssignment;
use App\Models\CoachingPlan;
use App\Models\CoachingPlanItem;
use App\Models\CoachingSession;
use App\Models\Student;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Akademik koçluk iş mantığı. Denetleyici incedir; kurallar burada.
 * Çevrimdışı/hızlı kayıt güvencesi: NOT NULL metin kolonları (topics) form boş gelirse '' yazılır.
 * Denetim konusu (subject) olarak öğrenci kullanılır (morph haritası gerektirmez, öğrenci bazlı iz daha kullanışlı).
 */
class CoachingService
{
    /** Öğrenciye aktif koç ata: varsa önceki aktif atamayı pasife alır, yeni aktif atama açar. */
    public function assignCoach(Student $student, int $coachId, ?string $note = null): CoachingAssignment
    {
        return DB::transaction(function () use ($student, $coachId, $note) {
            CoachingAssignment::query()
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $assignment = CoachingAssignment::query()->create([
                'student_id' => $student->id,
                'coach_id' => $coachId,
                'assigned_at' => now(),
                'is_active' => true,
                'note' => $note,
                'assigned_by' => auth()->id(),
            ]);

            Audit::log('coaching.coach_assigned', sprintf('%s öğrencisine koç atadı.', $student->full_name), $student);

            return $assignment;
        });
    }

    /** Öğrencinin aktif koçluğunu kaldır (tarihçe kalır). */
    public function unassign(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $changed = CoachingAssignment::query()
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            if ($changed) {
                Audit::log('coaching.coach_unassigned', sprintf('%s öğrencisinin koç atamasını kaldırdı.', $student->full_name), $student);
            }
        });
    }

    public function createSession(array $data): CoachingSession
    {
        return DB::transaction(function () use ($data) {
            $data['coach_id'] ??= auth()->id();
            $data['topics'] ??= '';
            $session = CoachingSession::query()->create($data);
            $student = Student::query()->find($session->student_id);
            Audit::log('coaching.session_created', sprintf('%s için koçluk görüşmesi kaydetti.', $student?->full_name ?? '—'), $student);

            return $session;
        });
    }

    public function updateSession(CoachingSession $session, array $data): CoachingSession
    {
        return DB::transaction(function () use ($session, $data) {
            $session->fill($data);
            $session->topics ??= '';
            $session->save();
            Audit::log('coaching.session_updated', sprintf('%s için koçluk görüşmesini güncelledi.', $session->student?->full_name ?? '—'), $session->student);

            return $session;
        });
    }

    public function deleteSession(CoachingSession $session): void
    {
        DB::transaction(function () use ($session) {
            $student = $session->student;
            $session->delete();
            Audit::log('coaching.session_deleted', sprintf('%s için koçluk görüşmesini sildi.', $student?->full_name ?? '—'), $student);
        });
    }

    /**
     * Haftalık plan oluştur/güncelle. Aynı öğrenci+hafta için tek plan (varsa günceller).
     *
     * @param array<int, array{subject: string, target_kind?: string, target?: int|null}> $items
     */
    public function savePlan(array $data, array $items): CoachingPlan
    {
        return DB::transaction(function () use ($data, $items) {
            $data['coach_id'] ??= auth()->id();
            $weekStart = CarbonImmutable::parse($data['week_start'])->startOfWeek()->toDateString();

            // Aynı öğrenci + hafta için tek plan. week_start tarih kolonu olduğundan whereDate ile eşleştir
            // (datetime cast/format farkı updateOrCreate'i şaşırtmasın).
            $plan = CoachingPlan::query()->where('student_id', $data['student_id'])->whereDate('week_start', $weekStart)->first()
                ?? new CoachingPlan(['student_id' => $data['student_id'], 'week_start' => $weekStart]);
            $plan->coach_id = $data['coach_id'];
            $plan->note = $data['note'] ?? null;
            $plan->save();

            $this->syncItems($plan, $items);

            $student = Student::query()->find($plan->student_id);
            Audit::log('coaching.plan_saved', sprintf('%s için haftalık çalışma planı kaydetti.', $student?->full_name ?? '—'), $student);

            return $plan->load('items');
        });
    }

    public function updatePlan(CoachingPlan $plan, array $data, ?array $items = null): CoachingPlan
    {
        return DB::transaction(function () use ($plan, $data, $items) {
            $plan->fill(array_intersect_key($data, array_flip(['note', 'coach_id'])));
            $plan->save();
            if ($items !== null) {
                $this->syncItems($plan, $items);
            }
            Audit::log('coaching.plan_saved', sprintf('%s için haftalık çalışma planını güncelledi.', $plan->student?->full_name ?? '—'), $plan->student);

            return $plan->load('items');
        });
    }

    public function deletePlan(CoachingPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $student = $plan->student;
            $plan->items()->delete();
            $plan->delete();
            Audit::log('coaching.plan_deleted', sprintf('%s için haftalık çalışma planını sildi.', $student?->full_name ?? '—'), $student);
        });
    }

    /** Öğrenci/koç bir kalemi yaptı olarak işaretler/geri alır. */
    public function toggleItem(CoachingPlanItem $item, bool $done): CoachingPlanItem
    {
        $item->is_done = $done;
        $item->done_at = $done ? now() : null;
        $item->save();

        return $item;
    }

    /** @param array<int, array{subject: string, target_kind?: string, target?: int|null}> $items */
    private function syncItems(CoachingPlan $plan, array $items): void
    {
        $plan->items()->delete();
        foreach (array_values($items) as $i => $row) {
            $subject = trim((string) ($row['subject'] ?? ''));
            if ($subject === '') {
                continue;
            }
            $kind = in_array($row['target_kind'] ?? '', array_keys(CoachingPlanItem::TARGET_KINDS), true) ? $row['target_kind'] : 'questions';
            $plan->items()->create([
                'subject' => mb_substr($subject, 0, 120),
                'target_kind' => $kind,
                'target' => isset($row['target']) && $row['target'] !== '' ? (int) $row['target'] : null,
                'is_done' => (bool) ($row['is_done'] ?? false),
                'position' => $i,
            ]);
        }
    }
}

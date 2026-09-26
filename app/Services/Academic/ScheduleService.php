<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\ClassGroup;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Support\Audit;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Haftalık ders şablonu (lesson_schedules) yönetimi + somut oturumların senkronu.
 *
 * Kural: şablon değişince yalnızca GELECEKTEKİ ve DOKUNULMAMIŞ oturumlar
 * (yoklaması alınmamış, iptal edilmemiş) güncellenir; geçmiş olduğu gibi korunur.
 */
class ScheduleService
{
    public const HORIZON_DAYS = 21;

    public function __construct(private readonly ScheduleConflicts $conflicts, private readonly SessionGenerator $generator) {}

    /** @return list<array{type:string,message:string,schedule_id:int}> */
    public function conflictsFor(array $slot, ?int $ignoreId = null): array
    {
        return $this->conflicts->forSchedule($this->slot($slot), $ignoreId);
    }

    public function create(array $data): LessonSchedule
    {
        $data = $this->prepare($data);
        ScheduleConflicts::throwIfAny($this->conflicts->forSchedule($this->slot($data)));
        $this->assertTeacherTeachesSubject($data);

        return DB::transaction(function () use ($data) {
            $schedule = LessonSchedule::query()->create($data);
            $schedule->load(['classGroup:id,name', 'subject:id,name', 'teacher:id,first_name,last_name', 'classroom:id,name']);
            $this->regenerate($schedule);

            Audit::log('schedule.created', sprintf('%s sınıfına %s %s–%s %s dersi (%s, %s) ekledi.',
                $schedule->classGroup->name, TimeSlots::WEEKDAYS[$schedule->weekday], substr($schedule->starts_at, 0, 5), substr($schedule->ends_at, 0, 5),
                $schedule->subject->name, $schedule->teacher->full_name, $schedule->classroom->name), $schedule);

            return $schedule;
        });
    }

    public function update(LessonSchedule $schedule, array $data): LessonSchedule
    {
        $merged = $this->prepare(array_merge($schedule->only(['academic_term_id', 'class_group_id', 'subject_id', 'teacher_id', 'classroom_id', 'weekday', 'starts_at', 'ends_at']), [
            'valid_from' => $schedule->valid_from->toDateString(), 'valid_until' => $schedule->valid_until?->toDateString(),
        ], $data));

        ScheduleConflicts::throwIfAny($this->conflicts->forSchedule($this->slot($merged), $schedule->id));
        $this->assertTeacherTeachesSubject($merged);

        return DB::transaction(function () use ($schedule, $merged) {
            $before = $schedule->only(['weekday', 'starts_at', 'ends_at', 'teacher_id', 'classroom_id', 'subject_id']);
            $schedule->fill($merged)->save();
            $schedule->load(['classGroup:id,name', 'subject:id,name', 'teacher:id,first_name,last_name', 'classroom:id,name']);
            $this->syncFutureSessions($schedule);
            $this->regenerate($schedule);

            $changes = Audit::diff($schedule);
            if ($changes['after'] !== []) {
                Audit::log('schedule.updated', sprintf('%s sınıfının %s dersini %s %s–%s olarak güncelledi.',
                    $schedule->classGroup->name, $schedule->subject->name, TimeSlots::WEEKDAYS[$schedule->weekday], substr($schedule->starts_at, 0, 5), substr($schedule->ends_at, 0, 5)),
                    $schedule, ['before' => array_intersect_key($before, $changes['after']), 'after' => $changes['after']]);
            }

            return $schedule;
        });
    }

    public function delete(LessonSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule) {
            $schedule->load(['classGroup:id,name', 'subject:id,name']);
            $this->untouchedFuture($schedule)->delete();
            $schedule->delete();
            Audit::log('schedule.deleted', sprintf('%s sınıfının %s %s dersini programdan kaldırdı (gelecek oturumlar silindi, geçmiş korundu).',
                $schedule->classGroup->name, TimeSlots::WEEKDAYS[$schedule->weekday], $schedule->subject->name), $schedule);
        });
    }

    // ------------------------------------------------------------------ tek seferlik oturum işlemleri

    public function cancelSession(LessonSession $session, string $reason): LessonSession
    {
        if ($session->status === 'cancelled') {
            throw new BusinessRuleException('Bu ders zaten iptal edilmiş.', 'already_cancelled');
        }
        if ($session->attendance_taken_at || $session->attendances()->exists()) {
            throw new BusinessRuleException('Yoklaması alınmış ders iptal edilemez.', 'attendance_taken');
        }

        $session->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
        $session->load(['classGroup:id,name', 'subject:id,name']);
        Audit::log('session.cancelled', sprintf('%s sınıfının %s tarihli %s dersini iptal etti. Gerekçe: %s',
            $session->classGroup->name, $session->date->format('d.m.Y'), $session->subject->name, $reason), $session);

        if ($session->starts_at->isFuture()) {
            LessonNotifier::cancelled($session, $reason);
        }

        return $session;
    }

    public function restoreSession(LessonSession $session): LessonSession
    {
        if ($session->status !== 'cancelled') {
            throw new BusinessRuleException('Yalnızca iptal edilmiş ders geri alınabilir.', 'not_cancelled');
        }
        if (LessonSession::query()->where('makeup_of_id', $session->id)->where('status', '!=', 'cancelled')->exists()) {
            throw new BusinessRuleException('Bu ders telafiye taşınmış. Önce telafi dersini iptal edin.', 'has_makeup');
        }
        if ($session->holiday_id) {
            throw new BusinessRuleException('Ders tatil nedeniyle iptal edildi. Tatili Takvim ekranından düzenleyin.', 'holiday_cancelled');
        }
        $conf = $this->conflicts->forRange(CarbonImmutable::parse($session->starts_at), CarbonImmutable::parse($session->ends_at),
            $session->teacher_id, $session->classroom_id, $session->class_group_id, $session->id);
        ScheduleConflicts::throwIfAny($conf);

        $session->forceFill(['status' => 'scheduled', 'cancel_reason' => null])->save();
        Audit::log('session.restored', "{$session->date->format('d.m.Y')} tarihli ders iptalini geri aldı.", $session);

        return $session;
    }

    /** Tek seferlik derslik ve/veya öğretmen değişikliği (çakışma denetimli). */
    public function reassignSession(LessonSession $session, ?int $classroomId, ?int $teacherId): LessonSession
    {
        if ($session->status === 'cancelled') {
            throw new BusinessRuleException('İptal edilmiş ders için değişiklik yapılamaz.', 'session_cancelled');
        }
        $classroomId ??= $session->classroom_id;
        $teacherId ??= $session->teacher_id;

        $conf = $this->conflicts->forRange(CarbonImmutable::parse($session->starts_at), CarbonImmutable::parse($session->ends_at),
            $teacherId !== $session->teacher_id ? $teacherId : null, $classroomId !== $session->classroom_id ? $classroomId : null, null, $session->id);
        ScheduleConflicts::throwIfAny($conf);

        $before = $session->only(['classroom_id', 'teacher_id']);
        $session->forceFill(['classroom_id' => $classroomId, 'teacher_id' => $teacherId])->save();
        $session->load(['classroom:id,name', 'teacher:id,first_name,last_name', 'subject:id,name', 'classGroup:id,name']);
        Audit::log('session.reassigned', sprintf('%s sınıfının %s tarihli %s dersini %s dersliğine / %s öğretmenine atadı.',
            $session->classGroup->name, $session->date->format('d.m.Y'), $session->subject->name, $session->classroom->name, $session->teacher->full_name),
            $session, ['before' => $before, 'after' => $session->only(['classroom_id', 'teacher_id'])]);

        if ($session->starts_at->isFuture()) {
            if ($before['classroom_id'] !== $classroomId) {
                LessonNotifier::roomChanged($session);
            }
            if ($before['teacher_id'] !== $teacherId) {
                LessonNotifier::teacherChanged($session);
            }
        }

        return $session;
    }

    /** Kilitli şablon dersi program botunca yeniden planlanmaz (elle düzenleme serbest). */
    public function setLocked(LessonSchedule $schedule, bool $locked): LessonSchedule
    {
        $schedule->forceFill(['is_locked' => $locked])->save();
        $schedule->loadMissing(['classGroup:id,name', 'subject:id,name']);
        Audit::log($locked ? 'schedule.locked' : 'schedule.unlocked', sprintf('%s sınıfının %s %s %s dersini %s.',
            $schedule->classGroup->name, TimeSlots::WEEKDAYS[$schedule->weekday], substr($schedule->starts_at, 0, 5), $schedule->subject->name,
            $locked ? 'program botuna karşı kilitledi' : 'kilidini açtı'), $schedule);

        return $schedule;
    }

    /**
     * Bir derste işlenen konuları belirler (ÇOKLU). Legacy tekil `topic_id` ilk konuya set edilir
     * (eski görünümler bozulmasın); pivot `lesson_session_topic` senkronlanır.
     *
     * @param  array<int>  $topicIds
     */
    public function setTopic(LessonSession $session, array $topicIds, ?string $note): LessonSession
    {
        $topicIds = array_values(array_unique(array_filter(array_map('intval', $topicIds))));
        $sync = [];
        foreach ($topicIds as $i => $tid) {
            $sync[$tid] = ['sort' => $i];
        }
        $session->topics()->sync($sync);
        $session->forceFill(['topic_id' => $topicIds[0] ?? null, 'topic_note' => $note])->save();

        return $session;
    }

    // ------------------------------------------------------------------ yardımcılar

    private function prepare(array $data): array
    {
        $data['starts_at'] = TimeSlots::normalize($data['starts_at']);
        $data['ends_at'] = TimeSlots::normalize($data['ends_at']);
        $data['weekday'] = (int) $data['weekday'];
        $data['valid_until'] = $data['valid_until'] ?? null;

        if (empty($data['academic_term_id'])) {
            $group = ClassGroup::query()->find($data['class_group_id']);
            $data['academic_term_id'] = $group?->academic_term_id;
        }
        if (empty($data['valid_from'])) {
            $data['valid_from'] = now()->toDateString();
        }
        if ($data['valid_until'] && $data['valid_until'] < $data['valid_from']) {
            throw new BusinessRuleException('Bitiş tarihi başlangıç tarihinden önce olamaz.', 'invalid_validity');
        }
        if (TimeSlots::toMinutes($data['ends_at']) - TimeSlots::toMinutes($data['starts_at']) < 10) {
            throw new BusinessRuleException('Ders en az 10 dakika sürmeli.', 'too_short');
        }

        return $data;
    }

    private function slot(array $d): array
    {
        return [
            'teacher_id' => (int) $d['teacher_id'], 'classroom_id' => (int) $d['classroom_id'], 'class_group_id' => (int) $d['class_group_id'],
            'weekday' => (int) $d['weekday'], 'starts_at' => TimeSlots::normalize($d['starts_at']), 'ends_at' => TimeSlots::normalize($d['ends_at']),
            'valid_from' => $d['valid_from'] ?? now()->toDateString(), 'valid_until' => $d['valid_until'] ?? null,
        ];
    }

    /** Branşı dışında ders verilmek istenirse uyar (öğretmen-ders ilişkisi tanımlıysa). */
    private function assertTeacherTeachesSubject(array $data): void
    {
        $hasAny = DB::table('teacher_subject')->where('teacher_id', $data['teacher_id'])->exists();
        if ($hasAny && ! DB::table('teacher_subject')->where('teacher_id', $data['teacher_id'])->where('subject_id', $data['subject_id'])->exists()) {
            $teacher = DB::table('teachers')->where('id', $data['teacher_id'])->first(['first_name', 'last_name']);
            $subject = DB::table('subjects')->where('id', $data['subject_id'])->value('name');
            throw new BusinessRuleException("{$teacher?->first_name} {$teacher?->last_name} öğretmeninin branşları arasında {$subject} yok. Önce ders sayfasından öğretmeni derse bağlayın.", 'teacher_subject_mismatch');
        }
    }

    /** Gelecekteki, yoklaması alınmamış ve iptal edilmemiş oturumlar. */
    private function untouchedFuture(LessonSchedule $schedule)
    {
        return LessonSession::query()
            ->where('lesson_schedule_id', $schedule->id)
            ->where('starts_at', '>', now())
            ->where('status', 'scheduled')
            ->whereNull('attendance_taken_at')
            ->whereDoesntHave('attendances');
    }

    private function syncFutureSessions(LessonSchedule $schedule): void
    {
        // 1) Yeni şablona uymayan tarihleri sil (gün, geçerlilik aralığı)
        $this->untouchedFuture($schedule)
            ->where(fn ($q) => $q
                ->whereRaw('WEEKDAY(date) + 1 <> ?', [$schedule->weekday])
                ->orWhere('date', '<', $schedule->valid_from->toDateString())
                ->when($schedule->valid_until, fn ($q) => $q->orWhere('date', '>', $schedule->valid_until->toDateString())))
            ->delete();

        // 2) Kalanları yeni saat/öğretmen/derslik/ders ile güncelle
        foreach ($this->untouchedFuture($schedule)->get() as $session) {
            $day = $session->date->toDateString();
            $session->forceFill([
                'subject_id' => $schedule->subject_id, 'teacher_id' => $schedule->teacher_id, 'classroom_id' => $schedule->classroom_id,
                'class_group_id' => $schedule->class_group_id,
                'starts_at' => "$day {$schedule->starts_at}", 'ends_at' => "$day {$schedule->ends_at}",
            ])->save();
        }
    }

    /** Ufuk içindeki eksik oturumları üretir (var olanlara dokunmaz). */
    private function regenerate(LessonSchedule $schedule): void
    {
        $branchId = (int) $schedule->branch_id;
        $from = CarbonImmutable::today()->max($schedule->valid_from);
        $horizon = CarbonImmutable::today()->addDays(self::HORIZON_DAYS);
        $maxExisting = LessonSession::query()->max('date');
        $to = $maxExisting ? $horizon->max(CarbonImmutable::parse($maxExisting)) : $horizon;
        if ($schedule->valid_until) {
            $to = $to->min($schedule->valid_until);
        }
        if ($from->lte($to)) {
            app(BranchContext::class)->run($branchId, fn () => $this->generator->generate($branchId, $from, $to));
        }
    }
}

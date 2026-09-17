<?php

namespace App\Services\Guidance;

use App\Events\GuidanceMeetingRecorded;
use App\Models\GuidanceMeeting;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Rehberlik görüşmesi kaydı. `private_note` görünürlüğü denetleyicide (auth kontrolü + Audit) yönetilir;
 * bu servis yalnızca yazma işlemini yapar.
 */
class GuidanceService
{
    public const FIELDS = [
        'student_id', 'counselor_id', 'met_at', 'kind', 'summary', 'goal', 'motivation',
        'study_discipline', 'private_note', 'visibility', 'visible_to_student', 'next_meeting_on',
    ];

    /** Bir kullanıcının gizli notu görüp göremeyeceği: gizli-not yetkisi ya da (görünürlük "counselor" ise) görüşmeyi yapan kişi. */
    public static function canSeePrivateNote(User $user, GuidanceMeeting $meeting): bool
    {
        if ($user->can('guidance.private')) {
            return true;
        }

        return $meeting->visibility === 'counselor' && $meeting->counselor_id === $user->id;
    }

    public function create(array $data): GuidanceMeeting
    {
        return DB::transaction(function () use ($data) {
            $meeting = GuidanceMeeting::query()->create(array_intersect_key($data, array_flip(self::FIELDS)) + [
                'counselor_id' => $data['counselor_id'] ?? auth()->id(),
            ]);
            $student = Student::query()->find($meeting->student_id);
            // Audit kaydının "subject"ı öğrencidir (morph map'te GuidanceMeeting yok; ayrıca öğrenci bazlı denetim izi daha kullanışlı).
            Audit::log('guidance.meeting_created', sprintf('%s öğrencisi için rehberlik görüşmesi kaydetti.', $student?->full_name ?? '—'), $student);

            DB::afterCommit(fn () => event(new GuidanceMeetingRecorded($meeting->id, (int) $meeting->student_id)));

            return $meeting;
        });
    }

    public function update(GuidanceMeeting $meeting, array $data): GuidanceMeeting
    {
        return DB::transaction(function () use ($meeting, $data) {
            $meeting->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $meeting->save();
            Audit::log('guidance.meeting_updated', sprintf('%s için rehberlik görüşme kaydını güncelledi.', $meeting->student?->full_name ?? '—'), $meeting->student);

            DB::afterCommit(fn () => event(new GuidanceMeetingRecorded($meeting->id, (int) $meeting->student_id)));

            return $meeting;
        });
    }

    public function delete(GuidanceMeeting $meeting): void
    {
        DB::transaction(function () use ($meeting) {
            $student = $meeting->student;
            $meeting->delete();
            Audit::log('guidance.meeting_deleted', sprintf('%s için rehberlik görüşme kaydını sildi.', $student?->full_name ?? '—'), $student);
        });
    }
}

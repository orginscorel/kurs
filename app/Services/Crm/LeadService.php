<?php

namespace App\Services\Crm;

use App\Events\LeadConverted;
use App\Exceptions\BusinessRuleException;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Student;
use App\Services\Finance\EnrollmentService;
use App\Services\Students\StudentService;
use App\Support\Audit;
use App\Support\Crm\LeadStages;
use Illuminate\Support\Facades\DB;

/**
 * Aday hattı iş mantığı: oluşturma, aşama değişikliği (kanban), aktivite kaydı, kayda dönüştürme.
 */
class LeadService
{
    public const FIELDS = [
        'first_name', 'last_name', 'phone', 'guardian_name', 'guardian_phone', 'email',
        'school_name', 'school_grade', 'interested_program_id', 'source', 'source_detail',
        'offered_price', 'owner_id', 'next_action', 'next_action_at',
    ];

    public function create(array $data): Lead
    {
        return DB::transaction(function () use ($data) {
            $lead = new Lead();
            $lead->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $lead->stage = 'new';
            $lead->stage_position = (int) Lead::query()->where('stage', 'new')->max('stage_position') + 1;
            $lead->save();

            LeadActivity::query()->create([
                'lead_id' => $lead->id, 'user_id' => auth()->id(), 'kind' => 'note',
                'body' => 'Aday oluşturuldu.',
            ]);
            Audit::log('lead.created', "{$lead->full_name} adayını ekledi.", $lead);

            return $lead;
        });
    }

    public function update(Lead $lead, array $data): Lead
    {
        return DB::transaction(function () use ($lead, $data) {
            $lead->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            $lead->save();
            Audit::log('lead.updated', "{$lead->full_name} aday bilgilerini güncelledi.", $lead);

            return $lead;
        });
    }

    public function delete(Lead $lead): void
    {
        DB::transaction(function () use ($lead) {
            $lead->delete();
            Audit::log('lead.deleted', "{$lead->full_name} aday kaydını sildi.", $lead);
        });
    }

    /**
     * Kanban sürükle-bırak: aşama değişikliği + sütun içi sıralama tek istekte.
     *
     * @param  list<int>  $orderedIds  hedef sütundaki yeni sıralama (taşınan aday dahil)
     */
    public function move(Lead $lead, string $toStage, array $orderedIds, ?string $lostReason = null): Lead
    {
        $fromStage = $lead->stage;
        LeadStages::assertTransition($fromStage, $toStage, $lostReason, $lead->student_id !== null);

        return DB::transaction(function () use ($lead, $fromStage, $toStage, $orderedIds, $lostReason) {
            if ($fromStage !== $toStage) {
                $lead->forceFill(['stage' => $toStage, 'lost_reason' => $toStage === 'lost' ? $lostReason : null])->save();
                LeadActivity::query()->create([
                    'lead_id' => $lead->id, 'user_id' => auth()->id(), 'kind' => 'stage_change',
                    'body' => sprintf('"%s" → "%s"', Lead::STAGES[$fromStage] ?? $fromStage, Lead::STAGES[$toStage] ?? $toStage),
                    'meta' => ['from' => $fromStage, 'to' => $toStage, 'lost_reason' => $lostReason],
                ]);
                Audit::log('lead.stage_changed', sprintf('%s adayının aşamasını "%s" → "%s" yaptı.', $lead->full_name, Lead::STAGES[$fromStage] ?? $fromStage, Lead::STAGES[$toStage] ?? $toStage), $lead);
            }

            foreach (LeadStages::positions($orderedIds) as $id => $position) {
                Lead::query()->where('id', $id)->where('stage', $toStage)->update(['stage_position' => $position]);
            }
            // Kaynak sütunda da boşluk bırakmamak için sıkıştır.
            if ($fromStage !== $toStage) {
                Lead::query()->where('stage', $fromStage)->orderBy('stage_position')->get(['id'])
                    ->each(fn ($row, $i) => Lead::query()->where('id', $row->id)->update(['stage_position' => $i]));
            }

            return $lead->refresh();
        });
    }

    public function addActivity(Lead $lead, string $kind, ?string $body, ?array $meta = null): LeadActivity
    {
        if (! array_key_exists($kind, ['call' => 1, 'meeting' => 1, 'whatsapp' => 1, 'note' => 1, 'offer' => 1])) {
            throw new BusinessRuleException('Geçersiz aktivite türü.', 'invalid_activity_kind');
        }

        return DB::transaction(function () use ($lead, $kind, $body, $meta) {
            $activity = LeadActivity::query()->create([
                'lead_id' => $lead->id, 'user_id' => auth()->id(), 'kind' => $kind, 'body' => $body, 'meta' => $meta,
            ]);
            $lead->forceFill(['last_contacted_at' => now()])->save();

            return $activity;
        });
    }

    /**
     * Adayı öğrenciye dönüştürür: öğrenci + veli (+ isteğe bağlı kayıt/ödeme planı) tek transaction.
     *
     * @param  array  $studentData  StudentService::FIELDS + guardians + tag_ids
     * @param  array|null  $enrollmentData  EnrollmentService::enroll parametreleri
     */
    public function convert(Lead $lead, array $studentData, ?array $enrollmentData, StudentService $students, EnrollmentService $enrollments): Student
    {
        if ($lead->student_id) {
            throw new BusinessRuleException('Bu aday zaten bir öğrenciye dönüştürülmüş.', 'lead_already_converted');
        }

        return DB::transaction(function () use ($lead, $studentData, $enrollmentData, $students, $enrollments) {
            $student = $students->create($studentData);

            if ($enrollmentData) {
                $enrollments->enroll($student, $enrollmentData);
            }

            $lead->forceFill(['stage' => 'won', 'student_id' => $student->id, 'lost_reason' => null, 'converted_at' => now()])->save();
            LeadActivity::query()->create([
                'lead_id' => $lead->id, 'user_id' => auth()->id(), 'kind' => 'stage_change',
                'body' => 'Aday öğrenciye dönüştürüldü ve "Kayıt Oldu" aşamasına taşındı.',
                'meta' => ['from' => $lead->getOriginal('stage'), 'to' => 'won', 'student_id' => $student->id],
            ]);
            Audit::log('lead.converted', "{$lead->full_name} adayını {$student->full_name} olarak öğrenciye dönüştürdü.", $lead);

            DB::afterCommit(fn () => event(new LeadConverted($lead->id, $student->id)));

            return $student;
        });
    }
}

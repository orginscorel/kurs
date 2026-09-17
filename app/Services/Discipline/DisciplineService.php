<?php

namespace App\Services\Discipline;

use App\Exceptions\BusinessRuleException;
use App\Models\DisciplineAppeal;
use App\Models\DisciplineBehavior;
use App\Models\DisciplineDefense;
use App\Models\DisciplineEvent;
use App\Models\DisciplineIncident;
use App\Models\DisciplineIncidentStudent;
use App\Models\DisciplineSanction;
use App\Models\DisciplineSanctionType;
use App\Models\Document;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use App\Support\BranchContext;
use App\Support\Discipline\DisciplineCatalog as C;
use App\Support\Sequence;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Disiplin süreci: olay → (savunma) → yaptırım / kurul → itiraz → kapanış.
 * Her adım olay zaman çizelgesine (discipline_events) ve denetim kaydına yazılır.
 */
class DisciplineService
{
    public function __construct(private readonly DisciplineNotifier $notifier) {}

    /* ============================================================ Olay */

    /**
     * @param array{occurred_at:string, location?:?string, class_group_id?:?int, subject_id?:?int, teacher_id?:?int, title?:?string,
     *   description?:?string, witnesses?:?string, severity?:?string, source?:string,
     *   students: list<array{student_id:int, behavior_id?:?int, role?:string, note?:?string}>, quick_sanction_type_id?:?int} $data
     */
    public function createIncident(array $data, User $user, bool $notifyStaff = false): DisciplineIncident
    {
        $students = $this->normaliseParticipants($data['students'] ?? []);
        $behaviors = DisciplineBehavior::query()->whereIn('id', collect($students)->pluck('behavior_id')->filter()->unique())->get()->keyBy('id');
        foreach ($students as $s) {
            if ($s['behavior_id'] && ! $behaviors->has($s['behavior_id'])) {
                throw new BusinessRuleException('Seçilen davranış katalogda bulunamadı.', 'discipline_behavior_missing', [], 422);
            }
        }
        $involvedBehaviors = collect($students)->where('role', 'involved')->pluck('behavior_id')->filter()->map(fn ($id) => $behaviors[$id]);
        if ($involvedBehaviors->isEmpty()) {
            throw new BusinessRuleException('Olaya karışan en az bir öğrenci için davranış seçin.', 'discipline_behavior_required', [], 422);
        }
        $positive = $involvedBehaviors->every(fn (DisciplineBehavior $b) => $b->isPositive());
        if (! $positive && $involvedBehaviors->contains(fn (DisciplineBehavior $b) => $b->isPositive())) {
            throw new BusinessRuleException('Olumlu ve olumsuz davranışlar aynı kayıtta birleştirilemez; ayrı kaydedin.', 'discipline_mixed_kinds', [], 422);
        }

        $occurred = CarbonImmutable::parse($data['occurred_at']);
        if ($occurred->gt(now()->addMinutes(10))) {
            throw new BusinessRuleException('Olay tarihi ileri bir zaman olamaz.', 'discipline_future_date', [], 422);
        }
        $severity = $data['severity'] ?? null ?: DisciplineRules::maxSeverity($involvedBehaviors->pluck('severity'));
        $branchId = app(BranchContext::class)->require();

        $incident = DB::transaction(function () use ($data, $user, $students, $behaviors, $positive, $occurred, $severity, $branchId) {
            $incident = DisciplineIncident::query()->create([
                'branch_id' => $branchId,
                'incident_no' => Sequence::next($positive ? 'discipline_positive' : 'discipline_incident', $positive ? 'TKD' : 'DSP', $branchId),
                'academic_term_id' => $this->termIdFor($occurred, $branchId),
                'kind' => $positive ? 'positive' : 'negative',
                'occurred_at' => $occurred,
                'location' => $data['location'] ?? null,
                'class_group_id' => $data['class_group_id'] ?? null,
                'subject_id' => $data['subject_id'] ?? null,
                'teacher_id' => $data['teacher_id'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'witnesses' => $data['witnesses'] ?? null,
                'severity' => $positive ? 'low' : $severity,
                'status' => $positive ? 'closed' : 'open',
                'closed_at' => $positive ? now() : null,
                'source' => $data['source'] ?? 'staff',
                'reported_by' => $user->id,
            ]);

            foreach ($students as $s) {
                $b = $s['behavior_id'] ? $behaviors[$s['behavior_id']] : null;
                $incident->participants()->create($s + $this->pointsFor($b, $s['role']));
            }

            $names = $this->participantNames($incident);
            $this->event($incident, $user, 'created', ($positive ? 'Olumlu davranış kaydı oluşturuldu: ' : 'Olay kaydedildi: ').$names.'.', [
                'source' => $incident->source,
            ]);
            Audit::log($positive ? 'discipline.positive_recorded' : 'discipline.incident_created',
                sprintf('%s %s kaydı oluşturdu (%s).', $incident->incident_no, $positive ? 'olumlu davranış' : 'disiplin olayı', $names), $incident);

            return $incident;
        });

        if (! $positive && ! empty($data['quick_sanction_type_id'])) {
            $type = DisciplineSanctionType::query()->findOrFail((int) $data['quick_sanction_type_id']);
            foreach ($incident->participants()->where('role', 'involved')->pluck('student_id') as $sid) {
                $this->decideSanction($incident, ['student_id' => (int) $sid, 'sanction_type_id' => $type->id], $user);
            }
        }

        if ($notifyStaff && ! $positive) {
            $this->notifier->notifyStaffOfReport($incident);
        }

        return $incident->fresh();
    }

    /** @param array<string, mixed> $data */
    public function updateIncident(DisciplineIncident $incident, array $data, User $user): DisciplineIncident
    {
        $fields = array_intersect_key($data, array_flip(['occurred_at', 'location', 'class_group_id', 'subject_id', 'teacher_id', 'title', 'description', 'witnesses', 'severity']));
        DB::transaction(function () use ($incident, $fields, $data, $user) {
            if (isset($fields['occurred_at'])) {
                $fields['academic_term_id'] = $this->termIdFor(CarbonImmutable::parse($fields['occurred_at']), (int) $incident->branch_id);
            }
            $incident->fill($fields);
            $diff = null;
            if ($incident->isDirty()) {
                $incident->save();
                $diff = Audit::diff($incident);
            }

            if (array_key_exists('students', $data)) {
                $this->syncParticipants($incident, $this->normaliseParticipants($data['students']), $user);
            }

            if ($diff) {
                $this->event($incident, $user, 'updated', 'Olay bilgileri güncellendi.', ['fields' => array_keys($diff['after'])]);
                Audit::log('discipline.incident_updated', "{$incident->incident_no} olay kaydını güncelledi.", $incident, $diff);
            }
        });

        return $incident->fresh();
    }

    /** @param list<array{student_id:int, behavior_id:?int, role:string, note:?string}> $rows */
    private function syncParticipants(DisciplineIncident $incident, array $rows, User $user): void
    {
        $existing = $incident->participants()->get()->keyBy('student_id');
        $keep = collect($rows)->pluck('student_id')->all();
        $locked = DisciplineSanction::query()->where('incident_id', $incident->id)->whereNotIn('status', ['cancelled'])->pluck('student_id')->unique()->all();
        foreach ($existing as $sid => $row) {
            if (! in_array($sid, $keep, true)) {
                if (in_array($sid, $locked, true)) {
                    throw new BusinessRuleException('Yaptırımı olan öğrenci olaydan çıkarılamaz; önce yaptırımı iptal edin.', 'discipline_participant_locked', [], 422);
                }
                $row->delete();
            }
        }
        $behaviors = DisciplineBehavior::query()->whereIn('id', collect($rows)->pluck('behavior_id')->filter())->get()->keyBy('id');
        foreach ($rows as $r) {
            $b = $r['behavior_id'] ? $behaviors[$r['behavior_id']] ?? null : null;
            if ($r['behavior_id'] && ! $b) {
                throw new BusinessRuleException('Seçilen davranış katalogda bulunamadı.', 'discipline_behavior_missing', [], 422);
            }
            if ($b && $b->isPositive() !== ($incident->kind === 'positive')) {
                throw new BusinessRuleException('Olumlu ve olumsuz davranışlar aynı kayıtta birleştirilemez.', 'discipline_mixed_kinds', [], 422);
            }
            $current = $existing[$r['student_id']] ?? null;
            $payload = $r + $this->pointsFor($b, $r['role']);
            // Davranış değişmediyse o anki puan korunur (katalog sonradan değişmiş olabilir)
            if ($current && $current->behavior_id === $r['behavior_id'] && $current->role === $r['role']) {
                unset($payload['penalty_points'], $payload['merit_points']);
            }
            $incident->participants()->updateOrCreate(['student_id' => $r['student_id']], $payload);
        }
        $this->event($incident, $user, 'participants', 'Öğrenci listesi güncellendi: '.$this->participantNames($incident).'.');
    }

    /** Katılımcının puanını elle düzeltme (discipline.decide). */
    public function adjustPoints(DisciplineIncident $incident, int $studentId, int $points, string $reason, User $user): void
    {
        $row = $incident->participants()->where('student_id', $studentId)->firstOrFail();
        $col = $incident->kind === 'positive' ? 'merit_points' : 'penalty_points';
        $old = (int) $row->{$col};
        $row->update([$col => max(0, min(100, $points))]);
        $name = $row->student?->full_name;
        $this->event($incident, $user, 'points', "{$name} için puan {$old} → {$row->{$col}} olarak düzeltildi. Gerekçe: {$reason}");
        Audit::log('discipline.points_adjusted', "{$incident->incident_no} olayında {$name} öğrencisinin puanını {$old} → {$row->{$col}} yaptı ({$reason}).", $incident,
            ['before' => [$col => $old], 'after' => [$col => $row->{$col}]]);
    }

    public function changeStatus(DisciplineIncident $incident, string $to, User $user, ?string $note = null, ?string $outcome = null): DisciplineIncident
    {
        $incident->refresh();
        $from = $incident->status;
        if ($from === $to && $outcome === null) {
            return $incident;
        }
        DisciplineRules::assertIncidentMove($from, $to);

        if ($to === 'closed') {
            if (DisciplineAppeal::query()->whereIn('sanction_id', $incident->sanctions()->pluck('id'))->where('status', 'pending')->exists()) {
                throw new BusinessRuleException('Bekleyen itiraz varken olay kapatılamaz.', 'discipline_pending_appeal', [], 422);
            }
            if ($incident->sanctions()->where('status', 'proposed')->exists()) {
                throw new BusinessRuleException('Kurul kararı bekleyen yaptırım varken olay kapatılamaz.', 'discipline_pending_board', [], 422);
            }
            if ($outcome === 'unfounded' && $incident->sanctions()->whereIn('status', ['active', 'appealed', 'completed'])->exists()) {
                throw new BusinessRuleException('Yürürlükte yaptırımı olan olay "asılsız" kapatılamaz; önce yaptırımı iptal edin.', 'discipline_unfounded_with_sanction', [], 422);
            }
        }

        $incident->forceFill([
            'status' => $to,
            'outcome' => $to === 'closed' ? ($outcome === 'unfounded' ? 'unfounded' : null) : $incident->outcome,
            'closed_at' => $to === 'closed' ? now() : null,
            'decided_at' => $to === 'decided' ? ($incident->decided_at ?? now()) : $incident->decided_at,
        ])->save();

        if ($to !== 'closed' && $incident->outcome === 'unfounded') {
            $incident->forceFill(['outcome' => null])->save();
        }

        $label = C::INCIDENT_STATUSES[$to];
        $msg = "Durum: {$label}".($outcome === 'unfounded' ? ' (asılsız — puanlar sayılmaz)' : '').($note ? '. Not: '.rtrim($note, '. ').'.' : '.');
        $this->event($incident, $user, 'status', $msg, ['from' => $from, 'to' => $to, 'outcome' => $outcome]);
        Audit::log('discipline.incident_status', "{$incident->incident_no} olayının durumunu \"{$label}\" yaptı.", $incident, ['before' => ['status' => $from], 'after' => ['status' => $to]]);

        return $incident;
    }

    public function deleteIncident(DisciplineIncident $incident, User $user, string $reason): void
    {
        if ($incident->sanctions()->whereIn('status', ['active', 'appealed', 'completed', 'expired'])->exists()) {
            throw new BusinessRuleException('Yaptırım uygulanmış olay silinemez; "asılsız" olarak kapatabilir ya da yaptırımı iptal edebilirsiniz.', 'discipline_delete_locked', [], 422);
        }
        DB::transaction(function () use ($incident, $user, $reason) {
            $incident->sanctions()->where('status', 'proposed')->update(['status' => 'cancelled', 'cancel_reason' => 'Olay silindi']);
            $this->event($incident, $user, 'deleted', "Kayıt silindi. Gerekçe: {$reason}");
            $incident->delete();
            Audit::log('discipline.incident_deleted', "{$incident->incident_no} disiplin kaydını sildi ({$reason}).", $incident);
        });
    }

    /* ============================================================ Ek dosya */

    public function attach(DisciplineIncident $incident, UploadedFile $file, User $user, ?string $title = null): Document
    {
        $path = $file->store("discipline/{$incident->id}", 'local');
        $doc = $incident->documents()->create([
            'branch_id' => $incident->branch_id, 'category' => 'discipline', 'title' => mb_substr($title ?: $file->getClientOriginalName(), 0, 150),
            'disk' => 'local', 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            'visibility' => 'staff', 'uploaded_by' => $user->id,
        ]);
        $this->event($incident, $user, 'attachment', "Ek dosya eklendi: {$doc->title}.");
        Audit::log('discipline.attachment_added', "{$incident->incident_no} olayına ek dosya ekledi ({$doc->title}).", $incident);

        return $doc;
    }

    public function detach(DisciplineIncident $incident, Document $doc, User $user): void
    {
        Storage::disk($doc->disk ?: 'local')->delete($doc->path);
        $doc->delete();
        $this->event($incident, $user, 'attachment', "Ek dosya silindi: {$doc->title}.");
        Audit::log('discipline.attachment_removed', "{$incident->incident_no} olayından ek dosyayı sildi ({$doc->title}).", $incident);
    }

    /* ============================================================ Savunma */

    public function requestDefense(DisciplineIncident $incident, int $studentId, ?string $dueOn, ?string $note, User $user): DisciplineDefense
    {
        $incident->refresh();
        $this->assertOpenNegative($incident);
        $this->assertInvolved($incident, $studentId);
        $due = $dueOn ? CarbonImmutable::parse($dueOn) : CarbonImmutable::today()->addDays(DisciplineSettings::get('default_defense_days'));
        if ($due->lt(CarbonImmutable::today())) {
            throw new BusinessRuleException('Savunma son tarihi geçmiş bir gün olamaz.', 'discipline_due_past', [], 422);
        }

        return DB::transaction(function () use ($incident, $studentId, $due, $note, $user) {
            $defense = DisciplineDefense::query()->where('incident_id', $incident->id)->where('student_id', $studentId)->first();
            if ($defense && $defense->status === 'submitted') {
                throw new BusinessRuleException('Bu öğrencinin savunması zaten alınmış.', 'discipline_defense_exists', [], 422);
            }
            $defense ??= new DisciplineDefense(['incident_id' => $incident->id, 'student_id' => $studentId, 'branch_id' => $incident->branch_id]);
            $defense->fill(['requested_by' => $user->id, 'requested_at' => now(), 'due_on' => $due->toDateString(), 'status' => 'requested', 'request_note' => $note])->save();

            if ($incident->status === 'open') {
                $incident->forceFill(['status' => 'review'])->save();
            }
            $name = Student::query()->whereKey($studentId)->value('full_name');
            $this->event($incident, $user, 'defense_requested', "{$name} öğrencisinden {$due->format('d.m.Y')} tarihine kadar yazılı savunma istendi.");
            Audit::log('discipline.defense_requested', "{$incident->incident_no} olayı için {$name} öğrencisinden savunma istedi.", $incident);

            return $defense;
        });
    }

    public function recordDefense(DisciplineDefense $defense, string $statement, User $user, string $via = 'staff', ?string $submittedAt = null): DisciplineDefense
    {
        if ($defense->status === 'submitted' && $via === 'portal') {
            throw new BusinessRuleException('Savunmanız daha önce kaydedildi.', 'discipline_defense_exists', [], 422);
        }
        $incident = $defense->incident;
        $defense->fill([
            'statement' => $statement, 'status' => 'submitted', 'submitted_via' => $via,
            'submitted_at' => $submittedAt ? CarbonImmutable::parse($submittedAt) : now(), 'recorded_by' => $user->id,
        ])->save();
        $name = $defense->student?->full_name;
        $late = $defense->due_on && $defense->submitted_at->toDateString() > $defense->due_on->toDateString() ? ' (son tarihten sonra)' : '';
        $this->event($incident, $user, 'defense_submitted', ($via === 'portal' ? "{$name} savunmasını öğrenci portalından yazdı" : "{$name} öğrencisinin savunması kaydedildi").$late.'.');
        Audit::log('discipline.defense_recorded', "{$incident->incident_no} olayı için {$name} öğrencisinin savunmasını kaydetti".($via === 'portal' ? ' (portal)' : '').'.', $incident);

        return $defense;
    }

    public function waiveDefense(DisciplineIncident $incident, int $studentId, string $reason, User $user): DisciplineDefense
    {
        $this->assertInvolved($incident, $studentId);
        $defense = DisciplineDefense::query()->firstOrNew(['incident_id' => $incident->id, 'student_id' => $studentId], [
            'branch_id' => $incident->branch_id, 'requested_by' => $user->id, 'requested_at' => now(), 'due_on' => today()->toDateString(),
        ]);
        if ($defense->status === 'submitted') {
            throw new BusinessRuleException('Savunması alınmış öğrenci için "alınmadı" işaretlenemez.', 'discipline_defense_exists', [], 422);
        }
        $defense->fill(['status' => 'waived', 'request_note' => trim(($defense->request_note ? $defense->request_note."\n" : '').'Savunma alınmadı: '.$reason), 'recorded_by' => $user->id])->save();
        $name = Student::query()->whereKey($studentId)->value('full_name');
        $this->event($incident, $user, 'defense_waived', "{$name} için savunma alınmadı olarak işaretlendi: {$reason}");
        Audit::log('discipline.defense_waived', "{$incident->incident_no} olayında {$name} için savunmayı \"alınmadı\" işaretledi.", $incident);

        return $defense;
    }

    /* ============================================================ Yaptırım */

    /**
     * @param array{student_id:int, sanction_type_id:int, decision_note?:?string, duty_description?:?string, starts_on?:?string, days?:?int, visible_to_portal?:bool} $data
     */
    public function decideSanction(DisciplineIncident $incident, array $data, User $user): DisciplineSanction
    {
        $incident->refresh(); // aynı istekte başka adım durumu değiştirmiş olabilir (bayat nesne durumu ezmesin)
        $this->assertOpenNegative($incident);
        $this->assertInvolved($incident, (int) $data['student_id']);
        $type = DisciplineSanctionType::query()->findOrFail((int) $data['sanction_type_id']);
        if (! $type->is_active) {
            throw new BusinessRuleException('Bu yaptırım kademesi pasif.', 'discipline_type_inactive', [], 422);
        }
        $status = DisciplineRules::initialSanctionStatus($type->authority, fn (string $p) => $user->can($p));

        if (DisciplineSanction::query()->where('incident_id', $incident->id)->where('student_id', $data['student_id'])
            ->where('sanction_type_id', $type->id)->whereIn('status', ['proposed', 'active', 'appealed'])->exists()) {
            throw new BusinessRuleException('Bu öğrenciye bu olay için aynı yaptırım zaten verilmiş / önerilmiş.', 'discipline_duplicate_sanction', [], 422);
        }

        [$startsOn, $endsOn, $days] = $this->suspensionFields($type, $data, (int) $data['student_id']);
        if ($type->has_duty && empty($data['duty_description'])) {
            throw new BusinessRuleException('Etüt / hizmet görevinin açıklamasını yazın.', 'discipline_duty_required', [], 422);
        }

        return DB::transaction(function () use ($incident, $data, $user, $type, $status, $startsOn, $endsOn, $days) {
            $sanction = DisciplineSanction::query()->create([
                'branch_id' => $incident->branch_id,
                'sanction_no' => Sequence::next('discipline_sanction', 'YPT', (int) $incident->branch_id),
                'incident_id' => $incident->id,
                'student_id' => (int) $data['student_id'],
                'sanction_type_id' => $type->id,
                'status' => $status,
                'decision_note' => $data['decision_note'] ?? null,
                'duty_description' => $type->has_duty ? ($data['duty_description'] ?? null) : null,
                'starts_on' => $startsOn, 'ends_on' => $endsOn, 'days' => $days,
                'visible_to_portal' => (bool) ($data['visible_to_portal'] ?? true),
                'decided_by' => $status === 'active' ? $user->id : null,
                'decided_at' => $status === 'active' ? now() : null,
                'expires_on' => $status === 'active' ? DisciplineRules::expiresOn($type->expires_after_days, today()->toDateString(), $endsOn) : null,
            ]);
            $name = $sanction->student?->full_name;
            if ($status === 'active') {
                $this->afterActivated($sanction, $user, "{$name} için \"{$type->name}\" yaptırımı verildi");
            } else {
                if (in_array($incident->status, ['open'], true)) {
                    $incident->forceFill(['status' => 'review'])->save();
                }
                $this->event($incident, $user, 'sanction_proposed', "{$name} için \"{$type->name}\" önerildi; disiplin kurulu kararı bekleniyor.", ['sanction_id' => $sanction->id]);
                Audit::log('discipline.sanction_proposed', "{$incident->incident_no} olayında {$name} için \"{$type->name}\" yaptırımını kurula önerdi.", $sanction);
            }

            return $sanction;
        });
    }

    /** @return array{0:?string, 1:?string, 2:?int} */
    private function suspensionFields(DisciplineSanctionType $type, array $data, int $studentId, ?int $ignoreId = null): array
    {
        if (! $type->is_suspension) {
            return [null, null, null];
        }
        if (empty($data['starts_on']) || empty($data['days'])) {
            throw new BusinessRuleException('Uzaklaştırma için başlangıç tarihi ve gün sayısı girin.', 'discipline_suspension_dates', [], 422);
        }
        [$start, $end] = DisciplineRules::suspensionRange((string) $data['starts_on'], (int) $data['days']);
        $existing = DisciplineSanction::query()->where('student_id', $studentId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->whereIn('status', ['proposed', 'active', 'appealed'])->whereNotNull('starts_on')
            ->get(['sanction_no', 'starts_on', 'ends_on'])
            ->map(fn ($s) => ['sanction_no' => $s->sanction_no, 'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString()]);
        DisciplineRules::assertNoSuspensionOverlap($start, $end, $existing);

        return [$start, $end, (int) $data['days']];
    }

    private function afterActivated(DisciplineSanction $sanction, User $user, string $message): void
    {
        $incident = $sanction->incident;
        if (in_array($incident->status, ['open', 'review'], true) && ! $incident->sanctions()->where('status', 'proposed')->exists()) {
            $incident->forceFill(['status' => 'decided', 'decided_at' => now()])->save();
        }
        $type = $sanction->type;
        $extra = $sanction->starts_on ? sprintf(' (%s – %s, %d gün)', $sanction->starts_on->format('d.m.Y'), $sanction->ends_on->format('d.m.Y'), $sanction->days) : '';
        $this->event($incident, $user, 'sanction_decided', $message.$extra.'.', ['sanction_id' => $sanction->id]);
        Audit::log('discipline.sanction_decided', "{$incident->incident_no} olayında {$sanction->student?->full_name} için \"{$type->name}\" yaptırımı verdi ({$sanction->sanction_no}){$extra}.", $sanction);
        DB::afterCommit(fn () => $this->notifier->fireSanctionDecided($sanction->fresh(['student', 'type', 'incident'])));
    }

    /** Kurul/itiraz kararıyla öneriyi yürürlüğe koyar (yetki çağıran tarafta denetlenir). */
    public function activateProposed(DisciplineSanction $sanction, User $user, ?int $meetingId, ?int $typeId = null, ?array $data = null): DisciplineSanction
    {
        DisciplineRules::assertSanctionMove($sanction->status, 'active');
        $type = $typeId ? DisciplineSanctionType::query()->findOrFail($typeId) : $sanction->type;
        $defense = DisciplineDefense::query()->where('incident_id', $sanction->incident_id)->where('student_id', $sanction->student_id)->value('status');
        DisciplineRules::assertDefenseSettled($defense);

        $data = array_filter($data ?? [], fn ($v) => $v !== null && $v !== '') + ['starts_on' => $sanction->starts_on?->toDateString(), 'days' => $sanction->days];
        [$startsOn, $endsOn, $days] = $this->suspensionFields($type, $data, (int) $sanction->student_id, $sanction->id);

        $sanction->forceFill([
            'sanction_type_id' => $type->id, 'status' => 'active', 'board_meeting_id' => $meetingId ?? $sanction->board_meeting_id,
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'days' => $days,
            'duty_description' => $type->has_duty ? ($data['duty_description'] ?? $sanction->duty_description) : null,
            'decided_by' => $user->id, 'decided_at' => now(),
            'expires_on' => DisciplineRules::expiresOn($type->expires_after_days, today()->toDateString(), $endsOn),
        ])->save();
        $this->afterActivated($sanction, $user, "Disiplin kurulu kararıyla {$sanction->student?->full_name} için \"{$type->name}\" yürürlüğe girdi");

        return $sanction;
    }

    public function changeSanctionStatus(DisciplineSanction $sanction, string $to, User $user, ?string $reason = null): DisciplineSanction
    {
        DisciplineRules::assertSanctionMove($sanction->status, $to);
        if ($to === 'cancelled' && ! $reason) {
            throw new BusinessRuleException('İptal gerekçesini yazın.', 'discipline_reason_required', [], 422);
        }
        $from = $sanction->status;
        $sanction->forceFill(['status' => $to, 'cancel_reason' => $to === 'cancelled' ? $reason : $sanction->cancel_reason])->save();
        $label = C::SANCTION_STATUSES[$to];
        $this->event($sanction->incident, $user, 'sanction_status', "{$sanction->sanction_no} ({$sanction->type?->name}, {$sanction->student?->full_name}): {$label}".($reason ? ' — '.rtrim($reason, '. ') : '').'.', ['sanction_id' => $sanction->id]);
        Audit::log('discipline.sanction_status', "{$sanction->sanction_no} yaptırımının durumunu \"{$label}\" yaptı".($reason ? " ({$reason})" : '').'.', $sanction,
            ['before' => ['status' => $from], 'after' => ['status' => $to]]);

        return $sanction;
    }

    /** Süresi dolan uzaklaştırma "tamamlandı", düşme tarihi geçen yaptırım "düştü" olur. @return array{completed:int, expired:int} */
    public function sweep(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $completed = 0;
        $expired = 0;
        DisciplineSanction::query()->where('status', 'active')->whereNotNull('ends_on')->where('ends_on', '<', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today->toDateString()))
            ->each(function (DisciplineSanction $s) use (&$completed) {
                $s->forceFill(['status' => 'completed'])->save();
                $this->event($s->incident, null, 'sanction_status', "{$s->sanction_no}: uzaklaştırma süresi bitti (tamamlandı).", ['sanction_id' => $s->id]);
                $completed++;
            });
        DisciplineSanction::query()->whereIn('status', ['active', 'completed'])->whereNotNull('expires_on')->where('expires_on', '<', $today->toDateString())
            ->each(function (DisciplineSanction $s) use (&$expired) {
                $s->forceFill(['status' => 'expired'])->save();
                $this->event($s->incident, null, 'sanction_status', "{$s->sanction_no}: süresi doldu, yaptırım düştü.", ['sanction_id' => $s->id]);
                $expired++;
            });

        return ['completed' => $completed, 'expired' => $expired];
    }

    /* ============================================================ İtiraz */

    public function fileAppeal(DisciplineSanction $sanction, array $data, User $user): DisciplineAppeal
    {
        if ($sanction->status !== 'active') {
            throw new BusinessRuleException('Yalnız yürürlükteki yaptırıma itiraz kaydedilebilir.', 'discipline_appeal_state', [], 422);
        }

        return DB::transaction(function () use ($sanction, $data, $user) {
            $appeal = DisciplineAppeal::query()->create([
                'branch_id' => $sanction->branch_id, 'sanction_id' => $sanction->id, 'student_id' => $sanction->student_id,
                'appellant' => $data['appellant'] ?? 'guardian', 'appealed_on' => $data['appealed_on'] ?? today()->toDateString(),
                'reason' => $data['reason'], 'status' => 'pending', 'recorded_by' => $user->id,
            ]);
            $sanction->forceFill(['status' => 'appealed'])->save();
            $incident = $sanction->incident;
            if ($incident->status !== 'appealed' && DisciplineRules::canMoveIncident($incident->status, 'appealed')) {
                $incident->forceFill(['status' => 'appealed'])->save();
            }
            $who = $appeal->appellant === 'student' ? 'Öğrenci' : 'Veli';
            $this->event($incident, $user, 'appeal_filed', "{$who}, {$sanction->sanction_no} ({$sanction->type?->name}) yaptırımına itiraz etti.", ['sanction_id' => $sanction->id, 'appeal_id' => $appeal->id]);
            Audit::log('discipline.appeal_filed', "{$sanction->sanction_no} yaptırımına itirazı kaydetti.", $sanction);

            return $appeal;
        });
    }

    public function decideAppeal(DisciplineAppeal $appeal, array $data, User $user): DisciplineAppeal
    {
        if ($appeal->status !== 'pending') {
            throw new BusinessRuleException('Bu itiraz zaten karara bağlandı.', 'discipline_appeal_done', [], 422);
        }
        $sanction = $appeal->sanction;
        $perm = DisciplineRules::appealPermission($sanction->type->authority);
        if (! $user->can($perm)) {
            throw new BusinessRuleException($perm === 'discipline.board' ? 'Kurul kararıyla verilen yaptırımın itirazını yalnız disiplin kurulu yetkilisi karara bağlar.' : 'İtirazı karara bağlama yetkiniz yok.', 'discipline_forbidden', [], 403);
        }

        return DB::transaction(function () use ($appeal, $data, $user, $sanction) {
            $result = $data['status'];
            $incident = $sanction->incident;
            if ($result === 'accepted') {
                DisciplineRules::assertSanctionMove('appealed', 'overturned');
                $sanction->forceFill(['status' => 'overturned'])->save();
            } elseif ($result === 'rejected') {
                $sanction->forceFill(['status' => 'active'])->save();
            } else { // modified: daha hafif kademe
                $newType = DisciplineSanctionType::query()->findOrFail((int) ($data['new_sanction_type_id'] ?? 0));
                if ($newType->level >= $sanction->type->level && $newType->id !== $sanction->sanction_type_id) {
                    throw new BusinessRuleException('Kısmi kabulde daha hafif bir yaptırım seçin.', 'discipline_appeal_not_lighter', [], 422);
                }
                $old = $sanction->type->name;
                [$startsOn, $endsOn, $days] = $this->suspensionFields($newType, $data, (int) $sanction->student_id, $sanction->id);
                $sanction->forceFill([
                    'sanction_type_id' => $newType->id, 'status' => 'active', 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'days' => $days,
                    'expires_on' => DisciplineRules::expiresOn($newType->expires_after_days, $sanction->decided_at?->toDateString() ?? today()->toDateString(), $endsOn),
                    'decision_note' => trim(($sanction->decision_note ? $sanction->decision_note."\n" : '')."İtiraz üzerine {$old} → {$newType->name}."),
                ])->save();
            }
            $appeal->fill(['status' => $result, 'result_note' => $data['result_note'] ?? null, 'decided_by' => $user->id, 'decided_at' => now()])->save();

            if ($incident->status === 'appealed' && ! DisciplineAppeal::query()->whereIn('sanction_id', $incident->sanctions()->pluck('id'))->where('status', 'pending')->exists()) {
                $incident->forceFill(['status' => 'decided'])->save();
            }
            $label = C::APPEAL_STATUSES[$result];
            $this->event($incident, $user, 'appeal_decided', "{$sanction->sanction_no} itirazı: {$label}".(! empty($data['result_note']) ? ' — '.rtrim($data['result_note'], '. ') : '').'.', ['sanction_id' => $sanction->id, 'appeal_id' => $appeal->id]);
            Audit::log('discipline.appeal_decided', "{$sanction->sanction_no} yaptırımına yapılan itirazı karara bağladı: {$label}.", $sanction);

            return $appeal;
        });
    }

    /* ============================================================ Veli bildirimi (taslak) */

    public function markGuardianNotified(DisciplineIncident $incident, string $via, User $user, ?int $sanctionId = null): void
    {
        $incident->forceFill(['guardian_notified_at' => now(), 'guardian_notified_via' => $via])->save();
        if ($sanctionId) {
            DisciplineSanction::query()->where('incident_id', $incident->id)->whereKey($sanctionId)->update(['guardian_notified_at' => now()]);
        }
        $labels = ['phone' => 'telefonla', 'meeting' => 'yüz yüze', 'whatsapp' => 'WhatsApp ile (elle)', 'letter' => 'yazılı', 'other' => ''];
        $this->event($incident, $user, 'guardian_notified', 'Veli '.trim(($labels[$via] ?? '').' bilgilendirildi').'.', ['via' => $via, 'sanction_id' => $sanctionId]);
        Audit::log('discipline.guardian_notified', "{$incident->incident_no} olayı için velinin bilgilendirildiğini işaretledi ({$via}).", $incident);
    }

    /* ============================================================ Yardımcılar */

    public function event(DisciplineIncident $incident, ?User $user, string $type, string $message, ?array $meta = null): void
    {
        DisciplineEvent::query()->create([
            'incident_id' => $incident->id, 'user_id' => $user?->id, 'type' => $type, 'message' => mb_substr($message, 0, 500), 'meta' => $meta,
        ]);
    }

    /** @return array{penalty_points:int, merit_points:int} */
    private function pointsFor(?DisciplineBehavior $b, string $role): array
    {
        if (! $b || $role !== 'involved') {
            return ['penalty_points' => 0, 'merit_points' => 0];
        }

        return $b->isPositive() ? ['penalty_points' => 0, 'merit_points' => (int) $b->points] : ['penalty_points' => (int) $b->points, 'merit_points' => 0];
    }

    /** @return list<array{student_id:int, behavior_id:?int, role:string, note:?string}> */
    private function normaliseParticipants(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) ($r['student_id'] ?? 0);
            if ($sid <= 0 || isset($out[$sid])) {
                continue;
            }
            $role = $r['role'] ?? 'involved';
            $out[$sid] = [
                'student_id' => $sid,
                'behavior_id' => ! empty($r['behavior_id']) ? (int) $r['behavior_id'] : null,
                'role' => array_key_exists($role, C::ROLES) ? $role : 'involved',
                'note' => isset($r['note']) && $r['note'] !== '' ? mb_substr((string) $r['note'], 0, 500) : null,
            ];
        }
        if ($out === []) {
            throw new BusinessRuleException('En az bir öğrenci seçin.', 'discipline_students_required', [], 422);
        }
        $found = Student::query()->whereIn('id', array_keys($out))->count();
        if ($found !== count($out)) {
            throw new BusinessRuleException('Seçilen öğrencilerden biri bulunamadı.', 'discipline_student_missing', [], 422);
        }

        return array_values($out);
    }

    private function participantNames(DisciplineIncident $incident): string
    {
        return DisciplineIncidentStudent::query()->where('incident_id', $incident->id)->join('students', 'students.id', '=', 'discipline_incident_students.student_id')
            ->orderByRaw("CASE role WHEN 'involved' THEN 0 WHEN 'victim' THEN 1 ELSE 2 END")->limit(6)->pluck('students.full_name')->implode(', ');
    }

    private function termIdFor(CarbonImmutable $on, int $branchId): ?int
    {
        return DB::table('academic_terms')->where('branch_id', $branchId)->where('starts_on', '<=', $on->toDateString())->where('ends_on', '>=', $on->toDateString())->value('id');
    }

    private function assertInvolved(DisciplineIncident $incident, int $studentId): void
    {
        if (! $incident->participants()->where('student_id', $studentId)->where('role', 'involved')->exists()) {
            throw new BusinessRuleException('Bu işlem yalnız olaya karışan öğrenci için yapılabilir.', 'discipline_not_involved', [], 422);
        }
    }

    private function assertOpenNegative(DisciplineIncident $incident): void
    {
        if ($incident->kind !== 'negative') {
            throw new BusinessRuleException('Olumlu davranış kaydında savunma/yaptırım olmaz.', 'discipline_positive_record', [], 422);
        }
        if (! in_array($incident->status, ['open', 'review', 'decided'], true)) {
            throw new BusinessRuleException('Kapanmış ya da itirazdaki olayda bu işlem yapılamaz; önce olayı yeniden incelemeye alın.', 'discipline_incident_locked', [], 422);
        }
    }
}

<?php

namespace App\Services\Placement;

use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\ActivityFeed;
use App\Models\ClassGroup;
use App\Models\ClassWaitlistEntry;
use App\Models\Student;
use App\Services\Placement\Core\PlacementEngine;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tekil öğrenci sınıf değişimi: aynı seviyede şube değişimi, dolu şubede karşılıklı takas,
 * bekleme listesi, sınıf geçmişi.
 */
class ClassChangeService
{
    public function __construct(
        private readonly ClassStructure $structure,
        private readonly PlacementData $data,
        private readonly MembershipWriter $writer,
        private readonly PlacementService $placement,
    ) {}

    /** Öğrenci profili paneli: mevcut sınıf, seçenekler, bekleme listesi, tüm sınıf geçmişi. */
    public function studentPanel(Student $student, ?AcademicTerm $term = null): array
    {
        $term ??= AcademicTerm::current();
        $history = DB::table('class_group_student as cgs')->join('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->leftJoin('academic_terms as t', 't.id', '=', 'cg.academic_term_id')->leftJoin('users as u', 'u.id', '=', 'cgs.changed_by')
            ->where('cgs.student_id', $student->id)->orderByDesc('cgs.joined_on')->orderByDesc('cgs.id')
            ->get(['cgs.id', 'cgs.class_group_id', 'cg.name as class_name', 'cg.academic_term_id', 't.name as term_name', 'cgs.joined_on', 'cgs.left_on', 'cgs.change_reason', 'cgs.is_pinned', 'u.name as changed_by', 'cg.deleted_at', 'cg.is_active'])
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'class_group_id' => (int) $r->class_group_id, 'class_name' => $r->class_name, 'term' => $r->term_name,
                'joined_on' => $r->joined_on, 'left_on' => $r->left_on, 'reason' => $r->change_reason, 'changed_by' => $r->changed_by,
                'pinned' => (bool) $r->is_pinned, 'is_current' => $r->left_on === null, 'class_active' => $r->deleted_at === null && (bool) $r->is_active,
            ]);

        if (! $term) {
            return ['term' => null, 'current' => null, 'level' => null, 'options' => [], 'waitlist' => null, 'history' => $history];
        }

        $groups = $this->structure->groups($term->id);
        $current = $this->currentStructured($student, $term);
        $level = $current?->grade_level ?? ClassStructure::gradeOf($student->school_grade);
        $counts = DB::table('class_group_student')->whereNull('left_on')->whereIn('class_group_id', $groups->pluck('id'))
            ->selectRaw('class_group_id, COUNT(*) c')->groupBy('class_group_id')->pluck('c', 'class_group_id');
        $options = $level === null ? collect() : $groups->filter(fn ($g) => $g->grade_level === $level)->values()->map(fn ($g) => [
            'class_group_id' => $g->id, 'name' => $g->name, 'section' => $g->section, 'capacity' => $g->capacity,
            'count' => (int) ($counts[$g->id] ?? 0), 'full' => (int) ($counts[$g->id] ?? 0) >= $g->capacity, 'is_current' => $current?->id === $g->id,
        ]);
        $wait = ClassWaitlistEntry::query()->where('student_id', $student->id)->where('academic_term_id', $term->id)->where('status', 'waiting')->first();
        $otherOpen = $history->first(fn ($h) => $h['is_current'] && $h['class_group_id'] !== $current?->id);

        return [
            'term' => ['id' => $term->id, 'name' => $term->name, 'starts_on' => $term->starts_on?->toDateString()],
            'student' => ['id' => $student->id, 'full_name' => $student->full_name, 'school_grade' => $student->school_grade, 'status' => $student->status],
            'current' => $current ? ['class_group_id' => $current->id, 'name' => $current->name, 'section' => $current->section, 'grade_level' => $current->grade_level,
                'joined_on' => $current->joined_on, 'pinned' => (bool) $current->is_pinned] : null,
            'unstructured_class' => $otherOpen ? ['class_group_id' => $otherOpen['class_group_id'], 'name' => $otherOpen['class_name']] : null,
            'level' => $level,
            'options' => $options,
            'waitlist' => $wait ? ['id' => $wait->id, 'preferred_section' => $wait->preferred_section, 'reason' => $wait->reason, 'source_label' => ClassWaitlistEntry::SOURCES[$wait->source] ?? $wait->source, 'created_at' => $wait->created_at] : null,
            'history' => $history,
        ];
    }

    /** Değişim önizlemesi: hedef dolu mu, doluysa takas önerileri. */
    public function preview(Student $student, ClassGroup $target): array
    {
        [$term, $group, $current, $level] = $this->validateTarget($student, $target);
        $count = $this->memberCount($group->id);
        $full = $count >= $group->capacity;
        $suggestions = [];

        if ($full && $current) {
            $levelGroups = $this->structure->groups($term->id)->filter(fn ($g) => $g->grade_level === $level)->keyBy('section');
            $roster = $this->data->roster($term->id, $level, $levelGroups)->filter(fn ($s) => $s->class_group_id !== null);
            $ids = $roster->keys()->map(fn ($v) => (int) $v)->all();
            $scores = $this->data->scores($ids);
            $risks = $this->data->risks($ids);
            $families = $this->data->families($ids);
            $capacities = $levelGroups->sortKeys()->map(fn ($g) => $g->capacity)->all();
            $engine = new PlacementEngine;
            foreach ($engine->suggestSwaps($capacities, $this->data->candidates($roster, $scores, $risks, $families), $student->id, $group->section, 3, $this->structure->settings()['siblings_apart']) as $sug) {
                $s = $roster[$sug['student_id']];
                $suggestions[] = [
                    'student_id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'gender' => $s->gender,
                    'avg_net' => $scores[$s->id]['avg'] ?? null, 'risk' => $risks[$s->id] ?? null, 'delta' => $sug['delta'],
                    'effect' => $sug['delta'] <= 0.001 ? 'Dengeyi korur ya da iyileştirir' : ($sug['delta'] < 3 ? 'Dengeye etkisi az' : 'Şube dengesini bozar'),
                ];
            }
        }

        return [
            'target' => ['class_group_id' => $group->id, 'name' => $group->name, 'section' => $group->section, 'capacity' => $group->capacity, 'count' => $count],
            'current' => $current ? ['class_group_id' => $current->id, 'name' => $current->name, 'joined_on' => $current->joined_on] : null,
            'full' => $full, 'requires_reason' => true, 'suggestions' => $suggestions,
            'term' => ['id' => $term->id, 'starts_on' => $term->starts_on?->toDateString()],
        ];
    }

    /**
     * @param  array{class_group_id:int, reason:string, effective_on?:?string, swap_with_student_id?:?int, to_waitlist?:bool}  $data
     * @return array{message:string}
     */
    public function change(Student $student, array $data): array
    {
        return DB::transaction(function () use ($student, $data) {
            $target = ClassGroup::query()->lockForUpdate()->findOrFail($data['class_group_id']);
            [$term, $group, $current, $level] = $this->validateTarget($student, $target);
            $reason = trim((string) ($data['reason'] ?? ''));
            if (mb_strlen($reason) < 3) {
                throw new BusinessRuleException('Sınıf değişimi için gerekçe yazın.', 'placement_reason_required');
            }
            $date = $this->placement->effectiveDate($term, $data['effective_on'] ?? null);
            if ($current && CarbonImmutable::parse($date)->lt(CarbonImmutable::parse($current->joined_on))) {
                throw new BusinessRuleException('Geçerlilik tarihi öğrencinin mevcut sınıfa giriş tarihinden ('.CarbonImmutable::parse($current->joined_on)->format('d.m.Y').') önce olamaz.', 'placement_invalid_date');
            }

            if (! empty($data['to_waitlist'])) {
                $this->writer->addWaitlist($student->id, $term->id, $level, $group->section, 'change', $reason);
                Audit::log('placement.waitlisted', "{$student->full_name} öğrencisini {$group->name} şubesi için bekleme listesine ekledi. Gerekçe: {$reason}", $student);

                return ['message' => "{$student->full_name}, {$group->name} için bekleme listesine eklendi."];
            }

            $full = $this->memberCount($group->id) >= $group->capacity;
            $swapId = $data['swap_with_student_id'] ?? null;

            if ($full && ! $swapId) {
                throw new BusinessRuleException("{$group->name} dolu ({$group->capacity}/{$group->capacity}). Karşılıklı takas önerilerinden birini seçin ya da öğrenciyi bekleme listesine ekleyin.", 'class_group_full', ['class_group_id' => $group->id], 409);
            }

            if ($swapId) {
                if (! $current) {
                    throw new BusinessRuleException('Sınıfsız öğrenci için karşılıklı takas yapılamaz.', 'placement_swap_invalid');
                }
                $other = Student::query()->findOrFail($swapId);
                $otherRow = DB::table('class_group_student')->where('student_id', $other->id)->where('class_group_id', $group->id)->whereNull('left_on')->first();
                if (! $otherRow) {
                    throw new BusinessRuleException("{$other->full_name} artık {$group->name} şubesinde değil. Önizlemeyi yenileyin.", 'placement_swap_invalid', [], 409);
                }
                if ($otherRow->is_pinned) {
                    throw new BusinessRuleException("{$other->full_name} şubesine sabitlenmiş; takas için önce sabitlemeyi kaldırın.", 'placement_swap_pinned');
                }
                $swapReason = "Karşılıklı takas ({$student->full_name} ↔ {$other->full_name}): {$reason}";
                $this->writer->move($student->id, $term->id, $group->id, $date, $swapReason, 'swap');
                $this->writer->move($other->id, $term->id, $current->id, $date, $swapReason, 'swap');
                $message = "{$student->full_name} {$group->name}, {$other->full_name} {$current->name} şubesine geçti.";
                Audit::log('student.class_changed', "{$student->full_name} ({$current->name} → {$group->name}) ile {$other->full_name} ({$group->name} → {$current->name}) öğrencilerini karşılıklı takas etti. Gerekçe: {$reason}", $student);
            } else {
                $this->writer->move($student->id, $term->id, $group->id, $date, $reason, 'change');
                $message = $current ? "{$student->full_name}, {$current->name} → {$group->name} şubesine geçti." : "{$student->full_name}, {$group->name} şubesine yerleştirildi.";
                Audit::log('student.class_changed', ($current ? "{$student->full_name} öğrencisinin sınıfını {$current->name} → {$group->name} olarak değiştirdi" : "{$student->full_name} öğrencisini {$group->name} sınıfına yerleştirdi")." (geçerlilik {$date}). Gerekçe: {$reason}", $student);
            }

            // Bekleme listesinden yerleşen "Kayıt bekliyor" öğrenci aktifleşir
            if ($student->status === 'pending') {
                $student->forceFill(['status' => 'active'])->save();
                Audit::log('student.status_changed', "{$student->full_name} öğrencisi şubeye yerleştiği için durumu \"Kayıt bekliyor\" → \"Aktif\" oldu.", $student, ['before' => ['status' => 'pending'], 'after' => ['status' => 'active']]);
                DB::afterCommit(fn () => event(new \App\Events\StudentStatusChanged($student->id, 'pending', 'active')));
            }

            ActivityFeed::query()->create(['kind' => 'class_change', 'message' => $message, 'subject_type' => $student->getMorphClass(), 'subject_id' => $student->id, 'student_id' => $student->id, 'occurred_at' => now()]);
            $this->writer->flushEvents();

            return ['message' => $message];
        });
    }

    public function cancelWaitlist(ClassWaitlistEntry $entry): void
    {
        if ($entry->status !== 'waiting') {
            throw new BusinessRuleException('Bu kayıt artık bekleme listesinde değil.', 'placement_waitlist_closed');
        }
        $entry->forceFill(['status' => 'cancelled', 'resolved_at' => now()])->save();
        $name = $entry->student?->full_name ?? 'Öğrenci';
        Audit::log('placement.waitlist_cancelled', "{$name} öğrencisini sınıf bekleme listesinden çıkardı.", $entry->student);
    }

    /** Hedef doğrulaması → [term, hedef (yapı nesnesi), mevcut şube|null, seviye] */
    private function validateTarget(Student $student, ClassGroup $target): array
    {
        $term = AcademicTerm::query()->findOrFail($target->academic_term_id);
        $groups = $this->structure->groups($term->id);
        $group = $groups->first(fn ($g) => $g->id === $target->id);
        if (! $group) {
            throw new BusinessRuleException("{$target->name} seviye/şube yapısında bir sınıf değil (ör. 10-A) ya da pasif.", 'placement_target_invalid');
        }
        if (! in_array($student->status, ['active', 'frozen', 'pending', 'enrolled'], true)) {
            throw new BusinessRuleException('"'.(Student::STATUSES[$student->status] ?? $student->status).'" durumundaki öğrencinin sınıfı değiştirilemez.', 'placement_student_inactive');
        }
        $current = $this->currentStructured($student, $term);
        $level = $current?->grade_level ?? ClassStructure::gradeOf($student->school_grade);
        if ($level === null) {
            throw new BusinessRuleException('Öğrencinin sınıf seviyesi (9-12) tanımlı değil. Önce öğrenci bilgilerinden okul sınıfını girin.', 'placement_level_unknown');
        }
        if ($group->grade_level !== $level) {
            throw new BusinessRuleException("Öğrenci {$level}. sınıf; {$group->name} farklı seviyede. Seviye değişimi için \"Seviye atlat\" işlemini kullanın.", 'placement_level_mismatch');
        }
        if ($current && $current->id === $group->id) {
            throw new BusinessRuleException("Öğrenci zaten {$group->name} şubesinde.", 'placement_same_class');
        }

        return [$term, $group, $current, $level];
    }

    private function currentStructured(Student $student, AcademicTerm $term): ?object
    {
        $groups = $this->structure->groups($term->id);
        $row = DB::table('class_group_student')->where('student_id', $student->id)->whereNull('left_on')->whereIn('class_group_id', $groups->pluck('id'))->orderByDesc('id')->first();
        if (! $row) {
            return null;
        }
        $g = $groups->first(fn ($x) => $x->id === (int) $row->class_group_id);

        return (object) ((array) $g + ['joined_on' => $row->joined_on, 'is_pinned' => (bool) $row->is_pinned]);
    }

    private function memberCount(int $groupId): int
    {
        return DB::table('class_group_student')->where('class_group_id', $groupId)->whereNull('left_on')->count();
    }
}

<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\ClassGroup;
use App\Models\Student;
use App\Services\Finance\EnrollmentService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class ClassGroupService
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function create(array $data): ClassGroup
    {
        $this->assertNameFree($data);
        $group = ClassGroup::query()->create($data);
        Audit::log('class_group.created', "{$group->name} sınıfını oluşturdu (kontenjan {$group->capacity}).", $group);

        return $group;
    }

    public function update(ClassGroup $group, array $data): ClassGroup
    {
        $this->assertNameFree(array_merge($group->only(['academic_term_id', 'name']), $data), $group->id);
        if (isset($data['capacity']) && (int) $data['capacity'] < $group->activeStudents()->count()) {
            throw new BusinessRuleException('Kontenjan mevcut öğrenci sayısının altına indirilemez.', 'capacity_below_members');
        }
        $group->fill($data)->save();
        $changes = Audit::diff($group);
        if ($changes['after'] !== []) {
            Audit::log('class_group.updated', "{$group->name} sınıfını güncelledi.", $group, $changes);
        }

        return $group;
    }

    public function delete(ClassGroup $group): void
    {
        if ($group->activeStudents()->exists()) {
            throw new BusinessRuleException('İçinde öğrenci olan sınıf silinemez. Önce öğrencileri başka sınıfa taşıyın.', 'class_group_has_students');
        }
        DB::transaction(function () use ($group) {
            $group->schedules()->delete();
            $group->forceFill(['is_active' => false])->save();
            $group->delete();
            Audit::log('class_group.deleted', "{$group->name} sınıfını sildi.", $group);
        });
    }

    /** @param list<int> $studentIds  @return array{added:int, errors:list<string>} */
    public function addStudents(ClassGroup $group, array $studentIds, string $joinedOn): array
    {
        $added = 0;
        $errors = [];
        foreach (Student::query()->whereIn('id', $studentIds)->get() as $student) {
            try {
                $before = $group->activeStudents()->whereKey($student->id)->exists();
                $this->enrollments->assignClassGroup($student, $group, $joinedOn);
                if (! $before) {
                    $added++;
                }
            } catch (BusinessRuleException $e) {
                $errors[] = "{$student->full_name}: {$e->getMessage()}";
            }
        }
        if ($added) {
            Audit::log('class_group.students_added', "{$group->name} sınıfına {$added} öğrenci ekledi.", $group);
        }

        return ['added' => $added, 'errors' => $errors];
    }

    public function removeStudent(ClassGroup $group, Student $student, ?string $leftOn = null): void
    {
        $affected = DB::table('class_group_student')->where('class_group_id', $group->id)->where('student_id', $student->id)->whereNull('left_on')
            ->update(['left_on' => $leftOn ?? now()->toDateString(), 'updated_at' => now()]);
        if (! $affected) {
            throw new BusinessRuleException('Öğrenci bu sınıfın aktif üyesi değil.', 'not_member', [], 404);
        }
        Audit::log('class_group.student_removed', "{$student->full_name} öğrencisini {$group->name} sınıfından çıkardı.", $group);
    }

    private function assertNameFree(array $data, ?int $ignoreId = null): void
    {
        $exists = ClassGroup::query()->withTrashed()->where('academic_term_id', $data['academic_term_id'])->where('name', $data['name'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($exists) {
            throw new BusinessRuleException("Bu dönemde \"{$data['name']}\" adlı bir sınıf zaten var.", 'duplicate_class_group');
        }
    }
}

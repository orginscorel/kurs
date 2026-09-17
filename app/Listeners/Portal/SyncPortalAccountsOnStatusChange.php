<?php

namespace App\Listeners\Portal;

use App\Events\StudentStatusChanged;
use App\Models\Student;
use App\Services\Guardians\GuardianAccountService;
use App\Services\Students\StudentAccountService;
use App\Support\BranchContext;

/**
 * Öğrenci durumu değişti → portal hesapları eşitlenir (senkron; kuyruk beklenmez):
 *  - Ayrıldı/Mezun: öğrenci hesabı pasif + oturumları kapanır; tekrar Aktif/Donduruldu → açılır.
 *  - Veli: tüm bağlı öğrencileri kapalıysa veli hesabı pasif, biri açılınca yeniden aktif.
 * Demo/sessiz mod (kurs.silent_events) bu eşitlemeyi DURDURMAZ: güvenlik kuralıdır, mesaj değildir.
 */
class SyncPortalAccountsOnStatusChange
{
    public function __construct(
        private readonly StudentAccountService $students,
        private readonly GuardianAccountService $guardians,
        private readonly BranchContext $branches,
    ) {}

    public function handle(StudentStatusChanged $event): void
    {
        $student = Student::query()->withoutGlobalScopes()->find($event->studentId);
        if (! $student) {
            return;
        }

        $this->branches->run($student->branch_id, function () use ($student) {
            $this->students->syncActive($student);
            $this->guardians->syncForStudent($student);
        });
    }
}

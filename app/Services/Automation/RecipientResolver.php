<?php

namespace App\Services\Automation;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Sensitive;
use Illuminate\Support\Collection;

/**
 * Otomasyon eylemlerindeki `to` (öğrenci/veli/öğretmen/yönetici) alıcı listesini çözer.
 * Odak her zaman bir öğrencidir (fan-out — ör. bir ders programındaki tüm öğrenciler —
 * çağıran tarafından öğrenci başına ayrı `AutomationEngine::fire()` ile yapılır).
 */
class RecipientResolver
{
    /** @return Collection<int, array{type:string,model:?object,user_id:?int,phone:?string,email:?string,name:?string}> */
    public function resolve(string $to, Student $student, array $context = []): Collection
    {
        return match ($to) {
            'student' => collect([$this->studentRecipient($student)]),
            'guardian' => $this->guardians($student),
            'teacher' => $this->teacher($student, $context),
            'admin' => $this->admins($student),
            default => collect(),
        };
    }

    private function studentRecipient(Student $student): array
    {
        return [
            'type' => 'student', 'model' => $student, 'user_id' => $student->user_id,
            'phone' => Sensitive::normalizePhone($student->whatsapp_phone ?: $student->phone),
            'email' => $student->email, 'name' => $student->full_name,
        ];
    }

    private function guardians(Student $student): Collection
    {
        $guardians = $student->guardians()->wherePivot('receives_notifications', true)->get();

        if ($guardians->isEmpty()) {
            $guardians = $student->guardians()->wherePivot('is_primary', true)->get();
        }

        return $guardians->map(fn ($g) => [
            'type' => 'guardian', 'model' => $g, 'user_id' => $g->user_id,
            'phone' => $g->messagingPhone(), 'email' => $g->email, 'name' => $g->full_name,
        ])->values();
    }

    private function teacher(Student $student, array $context): Collection
    {
        $teacher = null;

        if (! empty($context['teacher_id'])) {
            $teacher = Teacher::query()->find($context['teacher_id']);
        } else {
            $classGroup = $student->currentClassGroups()->first();
            $teacher = $classGroup?->advisor;
        }

        if (! $teacher) {
            return collect();
        }

        return collect([[
            'type' => 'teacher', 'model' => $teacher, 'user_id' => $teacher->user_id,
            'phone' => Sensitive::normalizePhone($teacher->whatsapp_phone ?: $teacher->phone),
            'email' => $teacher->email, 'name' => $teacher->full_name,
        ]]);
    }

    private function admins(Student $student): Collection
    {
        return User::query()->where('branch_id', $student->branch_id)->role('yonetici')->get()
            ->map(fn (User $u) => [
                'type' => 'admin', 'model' => $u, 'user_id' => $u->id,
                'phone' => Sensitive::normalizePhone($u->phone ?? null), 'email' => $u->email, 'name' => $u->name,
            ])->values();
    }
}

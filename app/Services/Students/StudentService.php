<?php

namespace App\Services\Students;

use App\Events\StudentCreated;
use App\Events\StudentStatusChanged;
use App\Events\StudentUpdated;
use App\Exceptions\BusinessRuleException;
use App\Models\ActivityFeed;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\Guardians\GuardianAccountService;
use App\Support\Audit;
use App\Support\Sensitive;
use App\Support\Sequence;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StudentService
{
    public const FIELDS = [
        'first_name', 'last_name', 'birth_date', 'gender', 'school_name', 'school_grade', 'field', 'target_university',
        'target_department', 'phone', 'whatsapp_phone', 'email', 'address', 'status', 'guidance_teacher_id',
        'registered_on', 'medical_notes', 'notes',
    ];

    /**
     * @param array $data  öğrenci alanları + national_id + guardians: [{id?|first_name,last_name,phone,…, relationship, is_primary}]
     */
    public function create(array $data): Student
    {
        return DB::transaction(function () use ($data) {
            $this->assertNationalIdFree($data['national_id'] ?? null);

            $student = new Student(Arr::only($data, self::FIELDS));
            $student->forceFill(Sensitive::nationalIdColumns($data['national_id'] ?? null));
            // Sayaç ilk kez oluşurken mevcut en yüksek numaradan devam eder (içe aktarılmış/demo kayıtlarla çakışmasın)
            $maxExisting = (int) Student::query()->withTrashed()->whereRaw("student_no REGEXP '^[0-9]+$'")->max(DB::raw('CAST(student_no AS UNSIGNED)'));
            $student->student_no = $data['student_no'] ?? (string) Sequence::nextNumber('student_no', max((int) (now()->year.'001'), $maxExisting + 1));
            $student->status ??= 'active';
            $student->registered_on ??= now()->toDateString();
            $student->save();

            $this->syncGuardians($student, $data['guardians'] ?? []);
            $this->syncTags($student, $data['tag_ids'] ?? null);
            $this->syncMarketingConsents('student', $student->id, $data['marketing_consents'] ?? null);

            // Öğrenci portal hesabı (kullanıcı adı = öğrenci no, başlangıç şifresi sistemce üretilir)
            app(StudentAccountService::class)->ensure($student, audit: false);

            ActivityFeed::query()->create([
                'kind' => 'enrollment', 'message' => "{$student->full_name} öğrenci olarak eklendi",
                'student_id' => $student->id, 'subject_type' => 'student', 'subject_id' => $student->id, 'occurred_at' => now(),
            ]);
            Audit::log('student.created', "{$student->full_name} ({$student->student_no}) öğrenci kaydını oluşturdu.", $student);

            DB::afterCommit(fn () => event(new StudentCreated($student->id)));

            return $student;
        });
    }

    public function update(Student $student, array $data): Student
    {
        return DB::transaction(function () use ($student, $data) {
            $student->fill(Arr::only($data, self::FIELDS));

            if (array_key_exists('national_id', $data)) {
                $newId = $data['national_id'] ? preg_replace('/\D/', '', $data['national_id']) : null;
                if ($newId !== Sensitive::decrypt($student->national_id_encrypted)) {
                    $this->assertNationalIdFree($newId, $student->id);
                    $student->forceFill(Sensitive::nationalIdColumns($newId));
                }
            }

            $student->save();
            $changes = Audit::diff($student, ['national_id_encrypted', 'national_id_hash', 'full_name']);

            if (array_key_exists('guardians', $data)) {
                $this->syncGuardians($student, $data['guardians']);
            }
            if (array_key_exists('tag_ids', $data)) {
                $this->syncTags($student, $data['tag_ids']);
            }
            $this->syncMarketingConsents('student', $student->id, $data['marketing_consents'] ?? null);

            if (isset($changes['after']['first_name']) || isset($changes['after']['last_name'])) {
                app(StudentAccountService::class)->syncName($student);
            }

            if ($changes['after'] !== []) {
                Audit::log('student.updated', "{$student->full_name} öğrenci bilgilerini güncelledi.", $student, $changes);
                DB::afterCommit(fn () => event(new StudentUpdated($student->id, array_keys($changes['after']))));
            }

            return $student;
        });
    }

    public function changeStatus(Student $student, string $status, ?string $note = null): Student
    {
        if (! array_key_exists($status, Student::STATUSES)) {
            throw new BusinessRuleException('Geçersiz öğrenci durumu.', 'invalid_status');
        }

        $old = $student->status;
        if ($old === $status) {
            return $student;
        }

        DB::transaction(function () use ($student, $status, $note, $old) {
            // withdrawn_at: KVKK anonimleştirme süresinin başlangıcı (kurs:anonymize-withdrawn)
            $student->forceFill(['status' => $status, 'withdrawn_at' => $status === 'withdrawn' ? now() : null])->save();

            // Ayrılan / mezun öğrencinin sınıf üyeliği kapanır, aktif kaydı biter.
            if (in_array($status, ['withdrawn', 'graduated'], true)) {
                DB::table('class_group_student')->where('student_id', $student->id)->whereNull('left_on')->update(['left_on' => now()->toDateString(), 'updated_at' => now()]);
                DB::table('enrollments')->where('student_id', $student->id)->where('status', 'active')
                    ->update(['status' => $status === 'graduated' ? 'completed' : 'withdrawn', 'ended_on' => now()->toDateString(), 'updated_at' => now()]);
            }
            if ($status === 'frozen') {
                DB::table('enrollments')->where('student_id', $student->id)->where('status', 'active')->update(['status' => 'frozen', 'updated_at' => now()]);
            }
            if ($status === 'active' && $old === 'frozen') {
                DB::table('enrollments')->where('student_id', $student->id)->where('status', 'frozen')->update(['status' => 'active', 'updated_at' => now()]);
            }

            Audit::log('student.status_changed', sprintf('%s öğrencisinin durumunu "%s" → "%s" yaptı.%s',
                $student->full_name, Student::STATUSES[$old] ?? $old, Student::STATUSES[$status], $note ? " Not: {$note}" : ''), $student,
                ['before' => ['status' => $old], 'after' => ['status' => $status]]);

            DB::afterCommit(fn () => event(new StudentStatusChanged($student->id, $old, $status)));
        });

        return $student;
    }

    public function delete(Student $student): void
    {
        if ($student->payments()->exists()) {
            throw new BusinessRuleException('Tahsilat kaydı olan öğrenci silinemez. Durumunu "Ayrıldı" olarak değiştirin.', 'student_has_payments');
        }

        DB::transaction(function () use ($student) {
            $student->delete();
            app(StudentAccountService::class)->deactivate($student);
            // Velinin başka açık öğrencisi kalmadıysa veli hesabı da kapanır
            app(GuardianAccountService::class)->syncForStudent($student);
            Audit::log('student.deleted', "{$student->full_name} ({$student->student_no}) öğrenci kaydını sildi.", $student);
        });
    }

    private function assertNationalIdFree(?string $nationalId, ?int $ignoreId = null): void
    {
        if (! $nationalId) {
            return;
        }
        if (! Sensitive::isValidNationalId(preg_replace('/\D/', '', $nationalId))) {
            throw new BusinessRuleException('TC kimlik numarası geçersiz.', 'invalid_national_id');
        }

        $exists = Student::query()->withTrashed()->whereIn('national_id_hash', Sensitive::hashes($nationalId))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->first(['id', 'full_name']);

        if ($exists) {
            throw new BusinessRuleException("Bu TC kimlik numarasıyla kayıtlı öğrenci var: {$exists->full_name}.", 'duplicate_national_id', ['student_id' => $exists->id]);
        }
    }

    /** @param list<array> $guardians */
    private function syncGuardians(Student $student, array $guardians): void
    {
        $accounts = app(GuardianAccountService::class);
        $previous = DB::table('guardian_student')->where('student_id', $student->id)->pluck('guardian_id')->all();
        $sync = [];
        $hasPrimary = collect($guardians)->contains(fn ($g) => ! empty($g['is_primary']));

        foreach ($guardians as $i => $g) {
            if (! empty($g['id'])) {
                $guardian = Guardian::query()->findOrFail($g['id']);
                $guardian->fill(Arr::only($g, ['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'occupation', 'address']))->save();
                if ($guardian->wasChanged(['first_name', 'last_name'])) {
                    $accounts->syncName($guardian);
                }
                if ($guardian->wasChanged(['phone', 'whatsapp_phone'])) {
                    $accounts->syncUsername($guardian);
                }
            } else {
                if (empty($g['first_name']) || empty($g['last_name'])) {
                    continue;
                }
                $guardian = Guardian::query()->create(Arr::only($g, ['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'occupation', 'address'])
                    + Sensitive::nationalIdColumns($g['national_id'] ?? null));
            }

            $sync[$guardian->id] = [
                'relationship' => $g['relationship'] ?? 'parent',
                'is_primary' => $hasPrimary ? ! empty($g['is_primary']) : $i === 0,
                'is_financially_responsible' => ! empty($g['is_financially_responsible']) || (! $hasPrimary && $i === 0),
                'receives_notifications' => $g['receives_notifications'] ?? true,
            ];

            $this->syncMarketingConsents('guardian', $guardian->id, $g['marketing_consents'] ?? null);

            if (isset($g['whatsapp_consent'])) {
                DB::table('communication_consents')->updateOrInsert(
                    ['consentable_type' => 'guardian', 'consentable_id' => $guardian->id, 'channel' => 'whatsapp', 'purpose' => 'informational'],
                    ['granted' => (bool) $g['whatsapp_consent'], 'source' => 'Öğrenci formu', 'recorded_at' => now(), 'recorded_by' => auth()->id()],
                );
            }
        }

        $student->guardians()->sync($sync);

        // Veli portal hesapları: yeni veliye hesap açılır; bağlantısı kaldırılan velinin aktifliği yeniden hesaplanır
        $accounts->syncForStudent($student, audit: false);
        $removed = array_diff($previous, array_keys($sync));
        if ($removed !== []) {
            Guardian::query()->withoutGlobalScopes()->whereIn('id', $removed)->get()->each(fn (Guardian $g) => $accounts->syncActive($g));
        }
    }

    /** Ticari ileti onayı (SMS/e-posta/WhatsApp): yalnız değişen kanallar "Kayıt formu" kaynağıyla yazılır. */
    private function syncMarketingConsents(string $type, int $id, ?array $flags): void
    {
        if ($flags) {
            app(\App\Services\Campaigns\ConsentService::class)->syncFromForm($type, $id, $flags, auth()->id());
        }
    }

    private function syncTags(Student $student, ?array $tagIds): void
    {
        if ($tagIds !== null) {
            $student->tags()->sync($tagIds);
        }
    }
}

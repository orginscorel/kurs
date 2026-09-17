<?php

namespace App\Services\Attendance;

use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Support\Attendance\QrSigner;
use App\Support\BranchContext;

/**
 * Öğrenci QR kimliği: rastgele + imzalı değer (student_id tahmin edilemez).
 * device_identities.kind='qr' altında saklanır; ham biyometrik veri İÇERMEZ.
 * İmzalama mantığı App\Support\Attendance\QrSigner'da (saf, test edilebilir).
 */
class QrIdentityService
{
    /** Öğrencinin aktif QR değerini döner; yoksa üretir. */
    public function forStudent(Student $student): string
    {
        $existing = DeviceIdentity::query()
            ->where('person_type', 'student')->where('person_id', $student->id)
            ->where('kind', 'qr')->where('is_active', true)->first();

        if ($existing) {
            return $existing->identifier;
        }

        return $this->regenerate($student);
    }

    /** Eski QR'ı pasifleştirip yenisini üretir (kayıp kart / sızıntı durumunda). */
    public function regenerate(Student $student): string
    {
        DeviceIdentity::query()
            ->where('person_type', 'student')->where('person_id', $student->id)->where('kind', 'qr')
            ->update(['is_active' => false]);

        $signed = QrSigner::generate($student->id, $this->secret());

        DeviceIdentity::query()->create([
            'branch_id' => app(BranchContext::class)->require(),
            'person_type' => 'student',
            'person_id' => $student->id,
            'kind' => 'qr',
            'identifier' => $signed,
            'is_active' => true,
        ]);

        return $signed;
    }

    /** İmzayı doğrular (bozuk/kopyalanmış QR erken elenir; asıl yetki yine DB eşleşmesiyle). */
    public function verify(string $signedValue): bool
    {
        return QrSigner::verify($signedValue, $this->secret());
    }

    private function secret(): string
    {
        return (string) config('app.key');
    }
}

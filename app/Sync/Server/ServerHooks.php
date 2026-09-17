<?php

namespace App\Sync\Server;

use App\Models\Guardian;
use App\Models\Student;
use App\Services\Guardians\GuardianAccountService;
use App\Services\Students\StudentAccountService;
use App\Services\Students\StudentService;
use App\Sync\SyncTable;

/**
 * Cihazdan satır olarak gelen değişikliklerin sunucu tarafı yan etkileri (servis katmanı):
 *  - yeni öğrenci → portal hesabı (öğrenci no = kullanıcı adı)
 *  - öğrenci durumu → StudentService::changeStatus (sınıf üyeliği, kayıt durumu, portal hesabı, olaylar)
 *  - veli ekleme/telefon değişimi → veli portal hesabı
 * Yan etkiler normal (web kaynaklı) değişiklik olarak günlüğe girer ve tüm cihazlara iner.
 */
class ServerHooks
{
    public function afterInsert(SyncTable $def, int $id): void
    {
        match ($def->table) {
            'students' => $this->safely(fn () => ($s = Student::query()->withoutGlobalScopes()->find($id))
                ? app(StudentAccountService::class)->ensure($s, audit: false) : null),
            'guardians' => $this->safely(fn () => ($g = Guardian::query()->withoutGlobalScopes()->find($id))
                ? app(GuardianAccountService::class)->ensure($g, audit: false) : null),
            default => null,
        };
    }

    /**
     * Servisle yürütülmesi gereken alanları ayıklar (ör. öğrenci durumu).
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    public function beforeUpdate(SyncTable $def, int $id, array $decoded): array
    {
        if ($def->table === 'students' && array_key_exists('status', $decoded)) {
            $status = (string) $decoded['status'];
            unset($decoded['status'], $decoded['withdrawn_at']);
            $this->pendingStatus[$id] = $status;
        }

        return $decoded;
    }

    /** @var array<int, string> */
    private array $pendingStatus = [];

    public function afterUpdate(SyncTable $def, int $id): void
    {
        if ($def->table === 'students' && isset($this->pendingStatus[$id])) {
            $status = $this->pendingStatus[$id];
            unset($this->pendingStatus[$id]);
            $student = Student::query()->withoutGlobalScopes()->find($id);
            if ($student && $student->status !== $status) {
                app(StudentService::class)->changeStatus($student, $status, 'Çevrimdışı cihazdan');
            }
        }
        if ($def->table === 'guardians') {
            $this->safely(fn () => ($g = Guardian::query()->withoutGlobalScopes()->find($id))
                ? app(GuardianAccountService::class)->ensure($g, audit: false) : null);
        }
    }

    /** Portal hesabı açılamaması (ör. telefon başka hesapta) eşitlemeyi durdurmaz. */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            \Illuminate\Support\Facades\Log::info('Eşitleme yan etkisi atlandı: '.$e->getMessage());
        }
    }
}

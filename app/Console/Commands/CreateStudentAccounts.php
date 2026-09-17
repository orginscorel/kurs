<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\Students\StudentAccountService;
use App\Support\BranchContext;
use Illuminate\Console\Command;

/**
 * Portal hesabı olmayan öğrencilere hesap açar ve hesapların açık/kapalı durumunu öğrenci
 * durumuyla eşitler (Ayrıldı/Mezun → pasif; diğerleri → aktif). İdempotent; şifrelere dokunmaz.
 *   php artisan kurs:student-accounts [--dry-run]
 */
class CreateStudentAccounts extends Command
{
    protected $signature = 'kurs:student-accounts {--dry-run : Yalnız yapılacakları göster, değişiklik yapma}';

    protected $description = 'Öğrenci portal hesaplarını açar (kullanıcı adı = öğrenci no) ve aktiflik durumunu eşitler';

    public function handle(StudentAccountService $accounts, BranchContext $branches): int
    {
        $dry = (bool) $this->option('dry-run');

        $missing = Student::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('user_id')->orWhereNotExists(fn ($s) => $s->from('users')->whereColumn('users.id', 'students.user_id')));

        $total = (clone $missing)->count();
        $this->info("Hesabı olmayan öğrenci: {$total}");

        // Aktiflik uyumsuzlukları (silinmiş öğrenciler dahil: arşivlenen öğrencinin hesabı kapalı olmalı)
        $mismatch = Student::query()->withoutGlobalScopes()->join('users as u', 'u.id', '=', 'students.user_id')
            ->where('u.user_type', 'student')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('u.is_active', true)->where(fn ($x) => $x->whereNotNull('students.deleted_at')->orWhereIn('students.status', ['withdrawn', 'graduated'])))
                ->orWhere(fn ($w) => $w->where('u.is_active', false)->whereNull('students.deleted_at')->whereNotIn('students.status', ['withdrawn', 'graduated'])))
            ->select('students.*');
        $mismatchCount = (clone $mismatch)->count();
        $this->info("Aktifliği öğrenci durumuyla uyuşmayan hesap: {$mismatchCount}");

        if ($dry) {
            return self::SUCCESS;
        }

        $created = 0;
        if ($total > 0) {
            $missing->orderBy('id')->chunkById(100, function ($students) use ($accounts, $branches, &$created) {
                foreach ($students as $student) {
                    $branches->run($student->branch_id, function () use ($accounts, $student, &$created) {
                        if ($accounts->ensure($student, audit: false)['created']) {
                            $created++;
                        }
                    });
                }
            });
        }

        $synced = 0;
        foreach ($mismatch->get() as $student) {
            $branches->run($student->branch_id, function () use ($accounts, $student, &$synced) {
                if ($accounts->syncActive($student)) {
                    $synced++;
                }
            });
        }

        $this->info("Açılan hesap: {$created} · Durumu eşitlenen hesap: {$synced}");

        return self::SUCCESS;
    }
}

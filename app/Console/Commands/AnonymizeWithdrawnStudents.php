<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Student;
use App\Support\Audit;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KVKK: "Ayrıldı" durumundaki öğrencinin kişisel verisini `retention.withdrawn_student_anonymize_after_days`
 * gün sonra anonimleştirir. İdempotent (students.anonymized_at).
 *
 * Silinen/boşaltılan: TC (şifreli+özet+son 4), telefon, WhatsApp, e-posta, adres, doğum tarihi, sağlık notu,
 * fotoğraf, cihaz (parmak izi/kart) eşlemeleri; portal hesabı pasife alınır ve başlangıç şifresi silinir.
 * Tüm öğrencileri anonimleşmiş velinin de TC/telefon/e-posta/adresi boşaltılır (başka aktif öğrencisi varsa dokunulmaz).
 * KORUNAN: ad soyad ve öğrenci no (makbuz/sınav geçmişi okunabilir kalsın), finans, yoklama, sınav kayıtları.
 */
class AnonymizeWithdrawnStudents extends Command
{
    protected $signature = 'kurs:anonymize-withdrawn {--dry-run : Yalnız sayıyı göster} {--limit=500 : Tek çalışmada en fazla öğrenci}';

    protected $description = 'Saklama süresi dolan ayrılmış öğrencilerin kişisel verilerini anonimleştirir (KVKK)';

    public function handle(): int
    {
        $total = 0;
        foreach (Branch::query()->get(['id', 'name']) as $branch) {
            $total += app(BranchContext::class)->run($branch->id, fn () => $this->runForBranch($branch->id));
        }
        $this->line(($this->option('dry-run') ? 'Anonimleştirilecek: ' : 'Anonimleştirilen: ').$total.' öğrenci.');

        return self::SUCCESS;
    }

    private function runForBranch(int $branchId): int
    {
        $days = max(30, (int) Settings::get('retention.withdrawn_student_anonymize_after_days', 3650, $branchId));
        $cutoff = now()->subDays($days);

        $query = DB::table('students')->where('branch_id', $branchId)->where('status', 'withdrawn')->whereNull('anonymized_at')
            ->whereRaw('COALESCE(withdrawn_at, updated_at) <= ?', [$cutoff]);

        if ($this->option('dry-run')) {
            return $query->count();
        }

        $students = $query->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get(['id', 'user_id', 'photo_path', 'student_no']);
        foreach ($students as $s) {
            DB::transaction(function () use ($s, $days) {
                self::anonymizeStudent((int) $s->id);
                Audit::log('student.anonymized', "Öğrenci no {$s->student_no}: ayrılışından {$days} gün sonra kişisel veriler KVKK saklama süresi gereği anonimleştirildi.",
                    Student::query()->withoutGlobalScopes()->find($s->id));
            });
            if ($s->photo_path) {
                Storage::disk('public')->delete($s->photo_path);
            }
        }

        return $students->count();
    }

    /** Tek öğrenciyi anonimleştirir (çağıran transaction açar). */
    public static function anonymizeStudent(int $studentId): void
    {
        $now = now();
        $student = DB::table('students')->where('id', $studentId)->first(['id', 'user_id']);
        if (! $student) {
            return;
        }

        DB::table('students')->where('id', $studentId)->update([
            'national_id_encrypted' => null, 'national_id_hash' => null, 'national_id_last4' => null,
            'phone' => null, 'whatsapp_phone' => null, 'email' => null, 'address' => null,
            'birth_date' => null, 'medical_notes' => null, 'photo_path' => null,
            'anonymized_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('device_identities')->where('person_type', 'student')->where('person_id', $studentId)->delete();

        if ($student->user_id) {
            DB::table('users')->where('id', $student->user_id)->update(['is_active' => false, 'initial_password' => null, 'updated_at' => $now]);
            DB::table('personal_access_tokens')->where('tokenable_type', 'user')->where('tokenable_id', $student->user_id)->delete();
        }

        // Veliler: bağlı tüm öğrencileri anonimleştirildiyse velinin iletişim/kimlik bilgisi de boşaltılır.
        $guardianIds = DB::table('guardian_student')->where('student_id', $studentId)->pluck('guardian_id');
        foreach ($guardianIds as $gid) {
            $stillNeeded = DB::table('guardian_student as gs')->join('students as s', 's.id', '=', 'gs.student_id')
                ->where('gs.guardian_id', $gid)->whereNull('s.anonymized_at')->exists();
            if ($stillNeeded) {
                continue;
            }
            $g = DB::table('guardians')->where('id', $gid)->first(['id', 'user_id']);
            DB::table('guardians')->where('id', $gid)->update([
                'national_id_encrypted' => null, 'national_id_hash' => null, 'national_id_last4' => null,
                'phone' => null, 'whatsapp_phone' => null, 'email' => null, 'address' => null, 'occupation' => null,
                'updated_at' => $now,
            ]);
            if ($g?->user_id) {
                DB::table('users')->where('id', $g->user_id)->update(['is_active' => false, 'updated_at' => $now]);
            }
        }
    }
}

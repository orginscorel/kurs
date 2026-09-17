<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denetim (2026-09-16) arka uç bağlantıları — yalnız EKLEME yapar:
 *  - students.withdrawn_at / anonymized_at: KVKK anonimleştirme süresi ve idempotent işaret (kurs:anonymize-withdrawn)
 *  - leads.converted_at: "bu ay kayda dönen" sayımı için dönüşüm anı
 *  - student.class_changed şablonu + PASİF otomasyon kuralı (şube başına, varsa dokunulmaz)
 */
return new class extends Migration
{
    private const TEMPLATE_KEY = 'student.class_changed';

    private const RULE_NAME = 'Sınıfı değişen öğrencinin velisine bildir';

    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('students', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable()->after('withdrawn_at');
            }
        });

        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('student_id');
                $table->index(['branch_id', 'converted_at']);
            }
        });

        // Geriye dönük doldurma: ayrılan öğrenci → son "Ayrıldı" denetim kaydı, yoksa updated_at.
        foreach (DB::table('students')->where('status', 'withdrawn')->whereNull('withdrawn_at')->get(['id', 'updated_at']) as $s) {
            $at = DB::table('audit_logs')->where('action', 'student.status_changed')->where('subject_type', 'student')->where('subject_id', $s->id)
                ->where('changes', 'like', '%"after":{"status":"withdrawn"}%')->max('created_at');
            DB::table('students')->where('id', $s->id)->update(['withdrawn_at' => $at ?? $s->updated_at]);
        }

        // Dönüşmüş aday → dönüşüm etkinliği, yoksa updated_at.
        foreach (DB::table('leads')->where('stage', 'won')->whereNull('converted_at')->get(['id', 'updated_at']) as $l) {
            $at = DB::table('lead_activities')->where('lead_id', $l->id)->where('kind', 'stage_change')
                ->where('meta', 'like', '%"to":"won"%')->max('created_at');
            DB::table('leads')->where('id', $l->id)->update(['converted_at' => $at ?? $l->updated_at]);
        }

        $now = now();
        $body = "Sayın {{veli_adi}},\n\n{{ogrenci_adi}} öğrencimizin sınıfı {{tarih}} itibarıyla {{eski_sinif}} → {{yeni_sinif}} olarak değişmiştir. Ders programı yeni sınıfa göre güncellenecektir.\n\nErbaa Bilgi Eğitim";
        foreach (DB::table('branches')->pluck('id') as $branchId) {
            $exists = DB::table('message_templates')->where('branch_id', $branchId)->where('key', self::TEMPLATE_KEY)->where('channel', 'whatsapp')->exists();
            if (! $exists) {
                DB::table('message_templates')->insert([
                    'branch_id' => $branchId, 'key' => self::TEMPLATE_KEY, 'channel' => 'whatsapp', 'name' => 'Sınıf değişikliği (veli)',
                    'body' => $body, 'language' => 'tr', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $ruleExists = DB::table('automation_rules')->where('branch_id', $branchId)->where('trigger', 'student.class_changed')->exists();
            if (! $ruleExists) {
                DB::table('automation_rules')->insert([
                    'branch_id' => $branchId, 'name' => self::RULE_NAME, 'trigger' => 'student.class_changed', 'conditions' => null,
                    'actions' => json_encode([['type' => 'whatsapp', 'to' => 'guardian', 'template' => self::TEMPLATE_KEY]], JSON_UNESCAPED_UNICODE),
                    'delay_minutes' => 0, 'is_active' => false, 'run_count' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('automation_rules')->where('trigger', 'student.class_changed')->where('name', self::RULE_NAME)->where('run_count', 0)->delete();
        DB::table('message_templates')->where('key', self::TEMPLATE_KEY)->where('channel', 'whatsapp')->delete();
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'converted_at')) {
                $table->dropIndex(['branch_id', 'converted_at']);
                $table->dropColumn('converted_at');
            }
        });
        Schema::table('students', function (Blueprint $table) {
            foreach (['anonymized_at', 'withdrawn_at'] as $c) {
                if (Schema::hasColumn('students', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};

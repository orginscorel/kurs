<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sınıf yapısı (seviye 9-12 × şube A/B) + otomatik yerleştirme + sınıf değişimi.
 * Yalnız ekleme yapar; mevcut veriye dokunmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->unsignedTinyInteger('grade_level')->nullable()->after('name');   // 9..12
            $table->char('section', 1)->nullable()->after('grade_level');            // A | B
            $table->index(['academic_term_id', 'grade_level', 'section'], 'class_groups_term_level_section_idx');
        });

        Schema::table('class_group_student', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('left_on');          // yerleştirme botu taşımaz
            $table->string('change_reason', 500)->nullable()->after('is_pinned');
            $table->foreignId('changed_by')->nullable()->after('change_reason')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('placement_run_id')->nullable()->after('changed_by')->index();
        });

        // Toplu işlemler (otomatik yerleştirme, seviye atlatma, demo dönüşümü): geri alma anlık görüntüsü
        Schema::create('placement_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('academic_term_id')->constrained();
            $table->string('kind', 20);                      // auto | promotion | restructure
            $table->json('grade_levels')->nullable();
            $table->json('summary');
            $table->longText('snapshot');                    // JSON: geri alma verisi
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'academic_term_id', 'created_at']);
        });

        // Bekleme listesi: yer bulamayan öğrenci ya da dolu şubeye geçmek isteyen öğrenci
        Schema::create('class_waitlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('academic_term_id')->constrained();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('grade_level')->nullable();
            $table->char('preferred_section', 1)->nullable();
            $table->string('source', 20)->default('manual');   // placement | change | restructure | manual
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('waiting');  // waiting | placed | cancelled
            $table->unsignedBigInteger('placement_run_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'academic_term_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_waitlist');
        Schema::dropIfExists('placement_runs');
        Schema::table('class_group_student', function (Blueprint $table) {
            $table->dropConstrainedForeignId('changed_by');
            $table->dropIndex(['placement_run_id']);
            $table->dropColumn(['is_pinned', 'change_reason', 'placement_run_id']);
        });
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropIndex('class_groups_term_level_section_idx');
            $table->dropColumn(['grade_level', 'section']);
        });
    }
};
